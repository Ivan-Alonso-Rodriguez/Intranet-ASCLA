<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Frontend\Language;
use ASCLA\Core\Integrations\Secrets;

/** Adaptive Cloudflare Turnstile protection for public authentication/form flows. */
final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TRUST_COOKIE = 'ascla_turnstile_trust';
    private const LOGIN_HINT_COOKIE = 'ascla_turnstile_login_hint';
    private const RECOVERY_HINT_COOKIE = 'ascla_turnstile_recovery_hint';
    private const TRUST_TTL = DAY_IN_SECONDS;
    private const ATTEMPT_TTL = 30 * MINUTE_IN_SECONDS;
    private const LOCK_TTL = 10 * MINUTE_IN_SECONDS;
    private const LOGIN_CHALLENGE_AFTER = 3;
    private const LOGIN_LOCK_AFTER = 5;
    private const RECOVERY_CHALLENGE_AFTER = 2;
    private const PUBLIC_CHALLENGE_AFTER = 3;
    private const IP_CHALLENGE_AFTER = 12;

    public static function boot(): void
    {
        add_filter('authenticate', [self::class, 'authenticate'], 5, 3);
        add_action('wp_login_failed', [self::class, 'loginFailed'], 10, 2);
        add_action('wp_login', [self::class, 'loginSucceeded'], 10, 2);
        add_action('lostpassword_post', [self::class, 'lostPasswordPost'], 10, 2);
        add_action('retrieve_password', [self::class, 'recoveryAccepted'], 10, 1);
        add_action('after_password_reset', [self::class, 'passwordResetCompleted'], 10, 2);
        add_action('login_form', static fn() => self::render('login'));
        add_action('lostpassword_form', static fn() => self::render('recovery'));
        add_action('login_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enabled(): bool
    {
        $settings = Settings::get();
        return !empty($settings['turnstile_enabled'])
            && trim((string)($settings['turnstile_site_key'] ?? '')) !== ''
            && Secrets::get('turnstile_secret') !== '';
    }

    public static function protects(string $flow): bool
    {
        if (!self::enabled() || is_user_logged_in()) { return false; }
        $settings = Settings::get();
        return !empty($settings['turnstile_' . $flow]);
    }

    public static function authenticate($user, string $username, string $password)
    {
        if ($user instanceof \WP_User || is_wp_error($user) || !self::protects('login') || !self::isWpLoginRequest()) { return $user; }
        if ($username === '' || $password === '') { return $user; }
        $state = self::state('login', $username);
        if ((int)($state['lock_until'] ?? 0) > time()) {
            $minutes = max(1, (int)ceil(((int)$state['lock_until'] - time()) / 60));
            return new \WP_Error('ascla_login_locked', sprintf(
                Language::text('Demasiados intentos fallidos. Inténtalo nuevamente en %d minuto(s).', 'Too many failed attempts. Try again in %d minute(s).'),
                $minutes
            ));
        }
        if (!self::loginChallengeRequired($username)) { return $user; }
        $result = self::verifyRequest('login', 'ascla_login');
        return $result === true ? $user : $result;
    }

    public static function loginFailed(string $username, \WP_Error $error): void
    {
        if (!self::protects('login') || !self::isWpLoginRequest()) { return; }
        $ignore = ['empty_username', 'empty_password', 'ascla_turnstile_required', 'ascla_turnstile_failed', 'ascla_login_locked'];
        if (in_array((string)$error->get_error_code(), $ignore, true)) { return; }
        $state = self::increment('login', $username);
        self::incrementIp('login');
        if ((int)$state['count'] >= self::LOGIN_LOCK_AFTER) {
            $state['lock_until'] = time() + self::LOCK_TTL;
            self::saveState('login', self::stateKey('login', $username), $state);
            // A new burst of credential failures is suspicious enough to require a fresh challenge after the lock.
            self::clearTrust();
        }
        self::rememberHint('login', self::stateKey('login', $username));
    }

    public static function loginSucceeded(string $login, \WP_User $user): void
    {
        foreach (array_unique([$login, $user->user_login, $user->user_email]) as $identifier) {
            self::clearState('login', (string)$identifier);
        }
        self::clearHint('login');
    }

    public static function lostPasswordPost(\WP_Error $errors, $userData): void
    {
        if (!self::protects('recovery')) { return; }
        $identifier = self::recoveryIdentifier($userData);
        if (!self::recoveryChallengeRequired($identifier)) { return; }
        $result = self::verifyRequest('recovery', 'ascla_recovery');
        if (is_wp_error($result)) { $errors->add($result->get_error_code(), $result->get_error_message()); }
    }

    public static function recoveryAccepted(string $userLogin): void
    {
        if (!self::protects('recovery')) { return; }
        self::increment('recovery', $userLogin);
        self::incrementIp('recovery');
        self::rememberHint('recovery', self::stateKey('recovery', $userLogin));
    }

    public static function passwordResetCompleted(\WP_User $user, string $newPassword): void
    {
        self::clearState('recovery', $user->user_login);
        self::clearHint('recovery');
    }

    public static function loginChallengeRequired(string $identifier = ''): bool
    {
        if (!self::protects('login')) { return false; }
        if (self::trusted()) { return false; }
        $state = self::state('login', $identifier);
        if ((int)($state['lock_until'] ?? 0) > time()) { return true; }
        return (int)($state['count'] ?? 0) >= self::LOGIN_CHALLENGE_AFTER || self::ipCount('login') >= self::IP_CHALLENGE_AFTER;
    }

    public static function recoveryChallengeRequired(string $identifier = ''): bool
    {
        if (!self::protects('recovery')) { return false; }
        if (self::trusted()) { return false; }
        $state = self::state('recovery', $identifier);
        return (int)($state['count'] ?? 0) >= self::RECOVERY_CHALLENGE_AFTER || self::ipCount('recovery') >= 6;
    }

    /** Generic adaptive policy for any future unauthenticated ASCLA public form. */
    public static function publicChallengeRequired(string $form, string $identifier = ''): bool
    {
        if (!self::protects('public') || self::trusted()) { return false; }
        $key = 'public_' . sanitize_key($form);
        $state = self::state($key, $identifier);
        $suspicious = empty($_SERVER['HTTP_USER_AGENT']) || (bool)apply_filters('ascla_turnstile_suspicious_request', false, $form, $identifier);
        return $suspicious || (int)($state['count'] ?? 0) >= self::PUBLIC_CHALLENGE_AFTER || self::ipCount($key) >= 10;
    }

    public static function notePublicSubmission(string $form, string $identifier = ''): void
    {
        if (!self::protects('public')) { return; }
        $key = 'public_' . sanitize_key($form);
        self::increment($key, $identifier);
        self::incrementIp($key);
    }

    public static function verifyPublicRequest(string $form, string $identifier = ''): true|\WP_Error
    {
        if (!self::publicChallengeRequired($form, $identifier)) { return true; }
        return self::verifyRequest('public', substr('ascla_public_' . sanitize_key($form), 0, 32));
    }

    public static function enqueue(): void
    {
        $flow = self::currentFlow();
        if ($flow === '' || !self::shouldRender($flow)) { return; }
        self::enqueueScript(false);
    }

    /** Render helper for future unauthenticated ASCLA public forms that opt in to adaptive protection. */
    public static function renderPublic(string $form, string $identifier = ''): void
    {
        if (!self::publicChallengeRequired($form, $identifier)) { return; }
        $siteKey = trim((string)(Settings::get()['turnstile_site_key'] ?? ''));
        if ($siteKey === '') { return; }
        self::enqueueScript(true);
        $action = substr('ascla_public_' . sanitize_key($form), 0, 32);
        echo '<div class="ascla-turnstile-wrap"><div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-theme="auto" data-size="flexible" data-appearance="interaction-only" data-retry="auto" data-refresh-expired="auto" data-action="' . esc_attr($action) . '"></div></div>';
    }

    public static function render(string $flow): void
    {
        if (!self::shouldRender($flow)) { return; }
        $siteKey = trim((string)(Settings::get()['turnstile_site_key'] ?? ''));
        if ($siteKey === '') { return; }
        $action = $flow === 'recovery' ? 'ascla_recovery' : 'ascla_login';
        $notice = '';
        if ($flow === 'login') {
            $state = self::state('login', self::requestIdentifier('login'));
            if ((int)($state['lock_until'] ?? 0) > time()) {
                $minutes = max(1, (int)ceil(((int)$state['lock_until'] - time()) / 60));
                $notice = '<p class="ascla-turnstile-lock">' . esc_html(sprintf(
                    Language::text('Acceso temporalmente pausado por demasiados intentos. Prueba de nuevo en %d minuto(s).', 'Access is temporarily paused after too many attempts. Try again in %d minute(s).'),
                    $minutes
                )) . '</p>';
            }
        }
        echo '<div class="ascla-turnstile-wrap">' . $notice
            . '<div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-theme="auto" data-size="flexible" data-appearance="interaction-only" data-retry="auto" data-refresh-expired="auto" data-action="' . esc_attr($action) . '"></div>'
            . '<p class="ascla-turnstile-note">' . esc_html(Language::text('Verificación de seguridad · Cloudflare Turnstile', 'Security check · Cloudflare Turnstile')) . '</p></div>';
    }

    public static function shouldRender(string $flow): bool
    {
        if (!self::protects($flow)) { return false; }
        if ($flow === 'login') { return self::loginChallengeRequired(self::requestIdentifier('login')); }
        if ($flow === 'recovery') { return self::recoveryChallengeRequired(self::requestIdentifier('recovery')); }
        return false;
    }

    public static function verifyRequest(string $flow, string $expectedAction, bool $allowTrust = true): true|\WP_Error
    {
        if (!self::enabled()) { return true; }
        if ($allowTrust && self::trusted()) { return true; }
        $token = isset($_POST['cf-turnstile-response']) ? trim((string)wp_unslash($_POST['cf-turnstile-response'])) : '';
        if ($token === '') {
            return new \WP_Error('ascla_turnstile_required', Language::text('Completa la verificación de seguridad para continuar.', 'Complete the security check to continue.'));
        }
        if (strlen($token) > 2048) {
            return new \WP_Error('ascla_turnstile_failed', Language::text('La verificación de seguridad no es válida. Inténtalo nuevamente.', 'The security check is invalid. Please try again.'));
        }
        $secret = Secrets::get('turnstile_secret');
        if ($secret === '') {
            return new \WP_Error('ascla_turnstile_failed', Language::text('La verificación de seguridad no está disponible temporalmente.', 'The security check is temporarily unavailable.'));
        }
        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => self::clientIp(),
            ],
        ]);
        if (is_wp_error($response)) {
            Audit::record('turnstile_error', 0, 'network');
            return new \WP_Error('ascla_turnstile_failed', Language::text('No se pudo comprobar la verificación de seguridad. Inténtalo de nuevo.', 'The security check could not be verified. Please try again.'));
        }
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) {
            $codes = is_array($body['error-codes'] ?? null) ? implode(',', array_map('sanitize_key', $body['error-codes'])) : 'invalid';
            Audit::record('turnstile_failed', 0, substr($codes, 0, 120));
            return new \WP_Error('ascla_turnstile_failed', Language::text('La verificación de seguridad falló o expiró. Inténtalo nuevamente.', 'The security check failed or expired. Please try again.'));
        }
        $action = sanitize_key((string)($body['action'] ?? ''));
        if ($action !== '' && $action !== sanitize_key($expectedAction)) {
            Audit::record('turnstile_failed', 0, 'action_mismatch');
            return new \WP_Error('ascla_turnstile_failed', Language::text('La verificación de seguridad no corresponde a este formulario.', 'The security check does not match this form.'));
        }
        $hostname = strtolower((string)($body['hostname'] ?? ''));
        $expectedHost = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($hostname !== '' && $expectedHost !== '' && $hostname !== $expectedHost && !self::testingSiteKey()) {
            Audit::record('turnstile_failed', 0, 'hostname_mismatch');
            return new \WP_Error('ascla_turnstile_failed', Language::text('La verificación de seguridad pertenece a otro sitio.', 'The security check belongs to another site.'));
        }
        self::markTrusted();
        Audit::record('turnstile_passed', 0, $flow);
        return true;
    }

    public static function trusted(): bool
    {
        $raw = (string)($_COOKIE[self::TRUST_COOKIE] ?? '');
        if ($raw === '') { return false; }
        $parts = explode('.', $raw);
        if (count($parts) !== 4) { return false; }
        [$expiry, $ipHash, $uaHash, $signature] = $parts;
        if (!ctype_digit($expiry) || (int)$expiry < time()) { return false; }
        $payload = $expiry . '.' . $ipHash . '.' . $uaHash;
        if (!hash_equals(self::sign($payload), $signature)) { return false; }
        return hash_equals($ipHash, self::shortHash(self::clientIp())) && hash_equals($uaHash, self::shortHash(self::userAgent()));
    }

    public static function clearTrust(): void
    {
        self::cookie(self::TRUST_COOKIE, '', time() - HOUR_IN_SECONDS);
        unset($_COOKIE[self::TRUST_COOKIE]);
    }

    public static function markTrusted(): void
    {
        $expiry = time() + self::TRUST_TTL;
        $payload = $expiry . '.' . self::shortHash(self::clientIp()) . '.' . self::shortHash(self::userAgent());
        $value = $payload . '.' . self::sign($payload);
        self::cookie(self::TRUST_COOKIE, $value, $expiry);
        $_COOKIE[self::TRUST_COOKIE] = $value;
    }

    public static function clientIp(): string
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { $ip = '0.0.0.0'; }
        /** Allows a deployment behind a trusted reverse proxy to provide its verified client IP. */
        $filtered = (string)apply_filters('ascla_turnstile_client_ip', $ip);
        return filter_var($filtered, FILTER_VALIDATE_IP) ? $filtered : $ip;
    }

    private static function enqueueScript(bool $footer): void
    {
        wp_enqueue_script('ascla-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, $footer);
        wp_script_add_data('ascla-turnstile', 'strategy', 'defer');
    }

    private static function isWpLoginRequest(): bool
    {
        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php') { return true; }
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        return $script === 'wp-login.php';
    }

    private static function currentFlow(): string
    {
        $action = (string)($GLOBALS['action'] ?? $_REQUEST['action'] ?? 'login');
        return match ($action) {
            'lostpassword', 'retrievepassword' => 'recovery',
            'login' => 'login',
            default => '',
        };
    }

    private static function requestIdentifier(string $flow): string
    {
        if ($flow === 'login') {
            $value = $_POST['log'] ?? '';
            return is_string($value) ? trim(wp_unslash($value)) : '';
        }
        if ($flow === 'recovery') {
            $value = $_POST['user_login'] ?? '';
            return is_string($value) ? trim(wp_unslash($value)) : '';
        }
        return '';
    }

    private static function recoveryIdentifier($userData): string
    {
        if ($userData instanceof \WP_User) { return $userData->user_login; }
        return self::requestIdentifier('recovery');
    }

    private static function state(string $flow, string $identifier = ''): array
    {
        $key = $identifier !== '' ? self::stateKey($flow, $identifier) : self::hintKey($flow);
        if ($key === '') { return ['count' => 0, 'lock_until' => 0]; }
        $state = get_transient(self::transientName($flow, $key));
        return is_array($state) ? array_merge(['count' => 0, 'lock_until' => 0], $state) : ['count' => 0, 'lock_until' => 0];
    }

    private static function increment(string $flow, string $identifier): array
    {
        $key = self::stateKey($flow, $identifier);
        $state = self::state($flow, $identifier);
        $state['count'] = (int)($state['count'] ?? 0) + 1;
        $state['updated_at'] = time();
        self::saveState($flow, $key, $state);
        return $state;
    }

    private static function saveState(string $flow, string $key, array $state): void
    {
        set_transient(self::transientName($flow, $key), $state, self::ATTEMPT_TTL);
    }

    private static function clearState(string $flow, string $identifier): void
    {
        if ($identifier === '') { return; }
        delete_transient(self::transientName($flow, self::stateKey($flow, $identifier)));
    }

    private static function incrementIp(string $flow): void
    {
        $name = self::ipTransientName($flow);
        $count = (int)get_transient($name);
        set_transient($name, $count + 1, self::ATTEMPT_TTL);
    }

    private static function ipCount(string $flow): int
    {
        return (int)get_transient(self::ipTransientName($flow));
    }

    private static function stateKey(string $flow, string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        return substr(hash_hmac('sha256', $flow . '|' . self::clientIp() . '|' . $identifier, wp_salt('auth')), 0, 40);
    }

    private static function transientName(string $flow, string $key): string
    {
        return 'ascla_ts_' . substr(sanitize_key($flow), 0, 24) . '_' . substr($key, 0, 40);
    }

    private static function ipTransientName(string $flow): string
    {
        return 'ascla_ts_ip_' . substr(sanitize_key($flow), 0, 24) . '_' . self::shortHash(self::clientIp());
    }

    private static function rememberHint(string $flow, string $key): void
    {
        if (!in_array($flow, ['login', 'recovery'], true)) { return; }
        $expiry = time() + self::ATTEMPT_TTL;
        $payload = $flow . '|' . $key . '|' . $expiry . '|' . self::shortHash(self::clientIp());
        $value = $key . '.' . $expiry . '.' . self::sign($payload);
        self::cookie(self::hintCookie($flow), $value, $expiry);
        $_COOKIE[self::hintCookie($flow)] = $value;
    }

    private static function hintKey(string $flow): string
    {
        if (!in_array($flow, ['login', 'recovery'], true)) { return ''; }
        $raw = (string)($_COOKIE[self::hintCookie($flow)] ?? '');
        $parts = explode('.', $raw);
        if (count($parts) !== 3) { return ''; }
        [$key, $expiry, $signature] = $parts;
        if (!preg_match('/^[a-f0-9]{40}$/', $key) || !ctype_digit($expiry) || (int)$expiry < time()) { return ''; }
        $payload = $flow . '|' . $key . '|' . $expiry . '|' . self::shortHash(self::clientIp());
        return hash_equals(self::sign($payload), $signature) ? $key : '';
    }

    private static function clearHint(string $flow): void
    {
        if (!in_array($flow, ['login', 'recovery'], true)) { return; }
        self::cookie(self::hintCookie($flow), '', time() - HOUR_IN_SECONDS);
        unset($_COOKIE[self::hintCookie($flow)]);
    }

    private static function hintCookie(string $flow): string
    {
        return $flow === 'recovery' ? self::RECOVERY_HINT_COOKIE : self::LOGIN_HINT_COOKIE;
    }

    private static function cookie(string $name, string $value, int $expiry): void
    {
        if (headers_sent()) { return; }
        setcookie($name, $value, [
            'expires' => $expiry,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function userAgent(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    }

    private static function shortHash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, wp_salt('nonce')), 0, 24);
    }

    private static function sign(string $value): string
    {
        return substr(hash_hmac('sha256', $value, wp_salt('secure_auth')), 0, 32);
    }

    private static function testingSiteKey(): bool
    {
        $siteKey = trim((string)(Settings::get()['turnstile_site_key'] ?? ''));
        return str_starts_with($siteKey, '1x00000000000000000000');
    }
}
