<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Frontend\Language;
use ASCLA\Core\Integrations\Secrets;

/** Adaptive Cloudflare Turnstile protection for public authentication/form flows. */
final class Turnstile
{
    private const LOCK_TTL = 10 * MINUTE_IN_SECONDS;
    private const LOGIN_CHALLENGE_AFTER = 3;
    private const LOGIN_LOCK_AFTER = 5;
    private const RECOVERY_CHALLENGE_AFTER = 2;
    private const PUBLIC_CHALLENGE_AFTER = 3;
    private const IP_CHALLENGE_AFTER = 12;
    private const IP_LOCK_AFTER = 20;
    private const IP_LOCK_TTL = 15 * MINUTE_IN_SECONDS;

    public static function boot(): void
    {
        add_filter('authenticate', [self::class, 'authenticate'], 99, 3);
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
        $result=$user;
        $eligible=TurnstileChallenge::isLoginRequest()
            && $username!==''
            && $password!=='';
        if ($eligible) {
            $state=TurnstileState::state('login',$username);
            $lockUntil=max((int)($state['lock_until']??0),TurnstileState::ipLockUntil('login'));
            if ($lockUntil>time()) {
                $minutes=max(1,(int)ceil(($lockUntil-time())/60));
                $result=new \WP_Error('ascla_login_locked',sprintf(
                    Language::text('Demasiados intentos fallidos. Inténtalo nuevamente en %d minuto(s).','Too many failed attempts. Try again in %d minute(s).'),
                    $minutes
                ));
            } elseif (!is_wp_error($user) && self::loginChallengeRequired($username)) {
                $verification=self::verifyRequest('login','ascla_login',false);
                if ($verification!==true) { $result=$verification; }
            }
        }
        return $result;
    }

    public static function loginFailed(string $username, \WP_Error $error): void
    {
        if ($username==='' || !TurnstileChallenge::isLoginRequest()) { return; }
        $ignore = ['empty_username', 'empty_password', 'ascla_turnstile_required', 'ascla_turnstile_failed', 'ascla_login_locked'];
        if (in_array((string)$error->get_error_code(), $ignore, true)) { return; }
        $state = TurnstileState::increment('login', $username);
        TurnstileState::incrementIp('login');
        if (TurnstileState::ipCount('login') >= self::IP_LOCK_AFTER) {
            TurnstileState::lockIp('login', self::IP_LOCK_TTL);
        }
        if ((int)$state['count'] >= self::LOGIN_LOCK_AFTER) {
            $state['lock_until'] = time() + self::LOCK_TTL;
            TurnstileState::saveState('login', TurnstileState::stateKey('login', $username), $state);
            // A new burst of credential failures is suspicious enough to require a fresh challenge after the lock.
            TurnstileTrust::clearTrust();
        }
        TurnstileState::rememberHint('login', TurnstileState::stateKey('login', $username));
    }

    public static function loginSucceeded(string $login, \WP_User $user): void
    {
        foreach (array_unique([$login, $user->user_login, $user->user_email]) as $identifier) {
            TurnstileState::clearState('login', (string)$identifier);
        }
        TurnstileState::clearHint('login');
    }

    public static function lostPasswordPost(\WP_Error $errors, $userData): void
    {
        if (!self::protects('recovery')) { return; }
        $identifier = TurnstileChallenge::recoveryIdentifier($userData);
        if (!self::recoveryChallengeRequired($identifier)) { return; }
        $result = self::verifyRequest('recovery', 'ascla_recovery');
        if (is_wp_error($result)) { $errors->add($result->get_error_code(), $result->get_error_message()); }
    }

    public static function recoveryAccepted(string $userLogin): void
    {
        if (!self::protects('recovery')) { return; }
        TurnstileState::increment('recovery', $userLogin);
        TurnstileState::incrementIp('recovery');
        TurnstileState::rememberHint('recovery', TurnstileState::stateKey('recovery', $userLogin));
    }

    public static function passwordResetCompleted(\WP_User $user, string $newPassword): void
    {
        unset($newPassword);
        TurnstileState::clearState('recovery', $user->user_login);
        TurnstileState::clearHint('recovery');
    }

    public static function loginChallengeRequired(string $identifier = ''): bool
    {
        $required=false;
        if (self::protects('login') && !TurnstileTrust::trusted()) {
            $state=TurnstileState::state('login',$identifier);
            $required=(int)($state['lock_until']??0)>time()
                || TurnstileState::ipLockUntil('login')>time()
                || (int)($state['count']??0)>=self::LOGIN_CHALLENGE_AFTER
                || TurnstileState::ipCount('login')>=self::IP_CHALLENGE_AFTER;
        }
        return $required;
    }

    public static function recoveryChallengeRequired(string $identifier = ''): bool
    {
        if (!self::protects('recovery')) { return false; }
        if (TurnstileTrust::trusted()) { return false; }
        $state = TurnstileState::state('recovery', $identifier);
        return (int)($state['count'] ?? 0) >= self::RECOVERY_CHALLENGE_AFTER || TurnstileState::ipCount('recovery') >= 6;
    }

    /** Generic adaptive policy for any future unauthenticated ASCLA public form. */
    public static function publicChallengeRequired(string $form, string $identifier = ''): bool
    {
        if (!self::protects('public') || TurnstileTrust::trusted()) { return false; }
        $key = 'public_' . sanitize_key($form);
        $state = TurnstileState::state($key, $identifier);
        $suspicious = empty($_SERVER['HTTP_USER_AGENT']) || (bool)apply_filters('ascla_turnstile_suspicious_request', false, $form, $identifier);
        return $suspicious || (int)($state['count'] ?? 0) >= self::PUBLIC_CHALLENGE_AFTER || TurnstileState::ipCount($key) >= 10;
    }

    public static function notePublicSubmission(string $form, string $identifier = ''): void
    {
        if (!self::protects('public')) { return; }
        $key = 'public_' . sanitize_key($form);
        TurnstileState::increment($key, $identifier);
        TurnstileState::incrementIp($key);
    }

    public static function verifyPublicRequest(string $form, string $identifier = ''): true|\WP_Error
    {
        if (!self::publicChallengeRequired($form, $identifier)) { return true; }
        return self::verifyRequest('public', substr('ascla_public_' . sanitize_key($form), 0, 32));
    }

    public static function enqueue(): void
    {
        $flow = TurnstileChallenge::currentFlow();
        if ($flow === '' || !self::shouldRender($flow)) { return; }
        TurnstileChallenge::enqueueScript(false);
    }

    /** Render helper for future unauthenticated ASCLA public forms that opt in to adaptive protection. */
    public static function renderPublic(string $form, string $identifier = ''): void
    {
        if (!self::publicChallengeRequired($form, $identifier)) { return; }
        $siteKey = trim((string)(Settings::get()['turnstile_site_key'] ?? ''));
        if ($siteKey === '') { return; }
        TurnstileChallenge::enqueueScript(true);
        $action = substr('ascla_public_' . sanitize_key($form), 0, 32);
        echo '<div class="ascla-turnstile-wrap"><div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-theme="auto" data-size="normal" data-appearance="always" data-retry="auto" data-refresh-expired="auto" data-action="' . esc_attr($action) . '"></div></div>';
    }

    public static function render(string $flow): void
    {
        if (!self::shouldRender($flow)) { return; }
        $siteKey = trim((string)(Settings::get()['turnstile_site_key'] ?? ''));
        if ($siteKey === '') { return; }
        $action = $flow === 'recovery' ? 'ascla_recovery' : 'ascla_login';
        $notice = '';
        if ($flow === 'login') {
            $state = TurnstileState::state('login', TurnstileChallenge::requestIdentifier('login'));
            $lockUntil = max((int)($state['lock_until'] ?? 0), TurnstileState::ipLockUntil('login'));
            if ($lockUntil > time()) {
                $minutes = max(1, (int)ceil(($lockUntil - time()) / 60));
                $notice = '<p class="ascla-turnstile-lock">' . esc_html(sprintf(
                    Language::text('Acceso temporalmente pausado por demasiados intentos. Prueba de nuevo en %d minuto(s).', 'Access is temporarily paused after too many attempts. Try again in %d minute(s).'),
                    $minutes
                )) . '</p>';
            }
        }
        $language=Language::english()?'en':'es';
        echo '<div class="ascla-turnstile-native">'
            . $notice
            . '<div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-theme="auto" data-language="' . esc_attr($language) . '" data-size="normal" data-appearance="always" data-retry="auto" data-refresh-expired="auto" data-action="' . esc_attr($action) . '"></div>'
            . '</div>';
    }

    public static function shouldRender(string $flow): bool
    {
        if (!self::protects($flow)) { return false; }
        return match($flow) {
            'login'=>self::loginChallengeRequired(TurnstileChallenge::requestIdentifier('login')),
            'recovery'=>self::recoveryChallengeRequired(TurnstileChallenge::requestIdentifier('recovery')),
            default=>false,
        };
    }

    public static function verifyRequest(string $flow, string $expectedAction, bool $allowTrust = true): true|\WP_Error
    {
        if (!self::enabled() || ($allowTrust && TurnstileTrust::trusted())) { return true; }
        $token=TurnstileChallenge::requestToken();
        if (is_wp_error($token)) { return $token; }
        return TurnstileChallenge::verifyToken($flow,$expectedAction,$token);
    }




































































    public static function __callStatic(string $name,array $arguments): mixed
    {
        if ($name==='clearTrust') {
            TurnstileTrust::clearTrust(...$arguments);
            return null;
        }
        if ($name==='markTrusted') {
            TurnstileTrust::markTrusted(...$arguments);
            return null;
        }
        return match($name) {
            'trusted'=>TurnstileTrust::trusted(...$arguments),
            'clientIp'=>TurnstileTrust::clientIp(...$arguments),
            default=>throw new \BadMethodCallException('Método Turnstile no disponible: '.$name),
        };
    }

}

final class TurnstileChallenge
{
    private const VERIFY_URL='https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function requestToken(): string|\WP_Error
    {
        $token=isset($_POST['cf-turnstile-response'])?trim((string)wp_unslash($_POST['cf-turnstile-response'])):'';
        if ($token==='') {
            return new \WP_Error('ascla_turnstile_required',Language::text('Completa la verificación de seguridad para continuar.','Complete the security check to continue.'));
        }
        if (strlen($token)>2048) {
            return new \WP_Error('ascla_turnstile_failed',Language::text('La verificación de seguridad no es válida. Inténtalo nuevamente.','The security check is invalid. Please try again.'));
        }
        return $token;
    }

    public static function verifyToken(string $flow,string $expectedAction,string $token): true|\WP_Error
    {
        $secret=Secrets::get('turnstile_secret');
        if ($secret==='') {
            return new \WP_Error('ascla_turnstile_failed',Language::text('La verificación de seguridad no está disponible temporalmente.','The security check is temporarily unavailable.'));
        }
        $response=wp_remote_post(self::VERIFY_URL,[
            'timeout'=>8,
            'headers'=>['Accept'=>'application/json'],
            'body'=>['secret'=>$secret,'response'=>$token,'remoteip'=>TurnstileTrust::clientIp()],
        ]);
        if (is_wp_error($response)) {
            Audit::record('turnstile_error',0,'network');
            return new \WP_Error('ascla_turnstile_failed',Language::text('No se pudo comprobar la verificación de seguridad. Inténtalo de nuevo.','The security check could not be verified. Please try again.'));
        }
        return self::validateVerification($flow,$expectedAction,json_decode((string)wp_remote_retrieve_body($response),true));
    }

    public static function validateVerification(string $flow,string $expectedAction,mixed $body): true|\WP_Error
    {
        $error=self::verificationResponseError($body,$expectedAction);
        if ($error) { return $error; }
        TurnstileTrust::markTrusted();
        Audit::record('turnstile_passed',0,$flow);
        return true;
    }

    public static function verificationResponseError(mixed $body,string $expectedAction): ?\WP_Error
    {
        $error=null;
        if (!is_array($body) || empty($body['success'])) {
            $codes=is_array($body['error-codes']??null)?implode(',',array_map('sanitize_key',$body['error-codes'])):'invalid';
            Audit::record('turnstile_failed',0,substr($codes,0,120));
            $error=new \WP_Error('ascla_turnstile_failed',Language::text('La verificación de seguridad falló o expiró. Inténtalo nuevamente.','The security check failed or expired. Please try again.'));
        } elseif (($action=sanitize_key((string)($body['action']??'')))!=='' && $action!==sanitize_key($expectedAction)) {
            Audit::record('turnstile_failed',0,'action_mismatch');
            $error=new \WP_Error('ascla_turnstile_failed',Language::text('La verificación de seguridad no corresponde a este formulario.','The security check does not match this form.'));
        } else {
            $hostname=strtolower((string)($body['hostname']??''));
            $expectedHost=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
            if ($hostname!=='' && $expectedHost!=='' && $hostname!==$expectedHost && !self::testingSiteKey()) {
                Audit::record('turnstile_failed',0,'hostname_mismatch');
                $error=new \WP_Error('ascla_turnstile_failed',Language::text('La verificación de seguridad pertenece a otro sitio.','The security check belongs to another site.'));
            }
        }
        return $error;
    }

    public static function enqueueScript(bool $footer): void
    {
        wp_enqueue_script('ascla-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, $footer);
        wp_script_add_data('ascla-turnstile', 'strategy', 'defer');
    }

    public static function isLoginRequest(): bool
    {
        if (!empty($_REQUEST['ascla_frontend_login'])) { return true; }
        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php') { return true; }
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        return $script === 'wp-login.php';
    }

    public static function currentFlow(): string
    {
        $action = (string)($GLOBALS['action'] ?? $_REQUEST['action'] ?? 'login');
        return match ($action) {
            'lostpassword', 'retrievepassword' => 'recovery',
            'login' => 'login',
            default => '',
        };
    }

    public static function requestIdentifier(string $flow): string
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

    public static function recoveryIdentifier($userData): string
    {
        if ($userData instanceof \WP_User) { return $userData->user_login; }
        return self::requestIdentifier('recovery');
    }

    public static function testingSiteKey(): bool
    {
        $siteKey = trim((string)(Settings::get()['turnstile_site_key'] ?? ''));
        return str_starts_with($siteKey, '1x00000000000000000000');
    }
}


final class TurnstileState
{
    private const ATTEMPT_TTL=30 * MINUTE_IN_SECONDS;
    private const LOGIN_HINT_COOKIE='ascla_turnstile_login_hint';
    private const RECOVERY_HINT_COOKIE='ascla_turnstile_recovery_hint';

    public static function state(string $flow, string $identifier = ''): array
    {
        $key = $identifier !== '' ? self::stateKey($flow, $identifier) : self::hintKey($flow);
        if ($key === '') { return ['count' => 0, 'lock_until' => 0]; }
        $state = get_transient(self::transientName($flow, $key));
        return is_array($state) ? array_merge(['count' => 0, 'lock_until' => 0], $state) : ['count' => 0, 'lock_until' => 0];
    }

    public static function increment(string $flow, string $identifier): array
    {
        $key = self::stateKey($flow, $identifier);
        $state = self::state($flow, $identifier);
        $state['count'] = (int)($state['count'] ?? 0) + 1;
        $state['updated_at'] = time();
        self::saveState($flow, $key, $state);
        return $state;
    }

    public static function saveState(string $flow, string $key, array $state): void
    {
        set_transient(self::transientName($flow, $key), $state, self::ATTEMPT_TTL);
    }

    public static function clearState(string $flow, string $identifier): void
    {
        if ($identifier === '') { return; }
        delete_transient(self::transientName($flow, self::stateKey($flow, $identifier)));
    }

    public static function incrementIp(string $flow): void
    {
        $name = self::ipTransientName($flow);
        $count = (int)get_transient($name);
        set_transient($name, $count + 1, self::ATTEMPT_TTL);
    }

    public static function ipCount(string $flow): int
    {
        return (int)get_transient(self::ipTransientName($flow));
    }

    public static function ipLockUntil(string $flow): int
    {
        return (int)get_transient(self::ipLockTransientName($flow));
    }

    public static function lockIp(string $flow, int $ttl): void
    {
        $until = time() + max(60, $ttl);
        set_transient(self::ipLockTransientName($flow), $until, max(60, $ttl));
    }

    public static function stateKey(string $flow, string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        $scope=TurnstileTrust::clientIp();
        if ($flow==='login') {
            // Username and email share an account lock, including attempts from another IP.
            $account=get_user_by('login',$identifier) ?: get_user_by('email',$identifier);
            if ($account) { $identifier='account:'.$account->ID; $scope='account'; }
        }
        return substr(hash_hmac('sha256', $flow . '|' . $scope . '|' . $identifier, wp_salt('auth')), 0, 40);
    }

    public static function transientName(string $flow, string $key): string
    {
        return 'ascla_ts_' . substr(sanitize_key($flow), 0, 24) . '_' . substr($key, 0, 40);
    }

    public static function ipTransientName(string $flow): string
    {
        return 'ascla_ts_ip_' . substr(sanitize_key($flow), 0, 24) . '_' . TurnstileTrust::shortHash(TurnstileTrust::clientIp());
    }

    public static function ipLockTransientName(string $flow): string
    {
        return 'ascla_ts_ip_lock_' . substr(sanitize_key($flow), 0, 20) . '_' . TurnstileTrust::shortHash(TurnstileTrust::clientIp());
    }

    public static function rememberHint(string $flow, string $key): void
    {
        if (!in_array($flow, ['login', 'recovery'], true)) { return; }
        $expiry = time() + self::ATTEMPT_TTL;
        $payload = $flow . '|' . $key . '|' . $expiry . '|' . TurnstileTrust::shortHash(TurnstileTrust::clientIp());
        $value = $key . '.' . $expiry . '.' . TurnstileTrust::sign($payload);
        TurnstileTrust::cookie(self::hintCookie($flow), $value, $expiry);
        $_COOKIE[self::hintCookie($flow)] = $value;
    }

    public static function hintKey(string $flow): string
    {
        $key='';
        if (in_array($flow,['login','recovery'],true)) {
            $raw=(string)($_COOKIE[self::hintCookie($flow)]??'');
            $parts=explode('.',$raw);
            if (count($parts)===3) {
                [$candidate,$expiry,$signature]=$parts;
                $valid=(bool)preg_match('/^[a-f0-9]{40}$/',$candidate) && ctype_digit($expiry) && (int)$expiry>=time();
                if ($valid) {
                    $payload=$flow.'|'.$candidate.'|'.$expiry.'|'.TurnstileTrust::shortHash(TurnstileTrust::clientIp());
                    if (hash_equals(TurnstileTrust::sign($payload),$signature)) { $key=$candidate; }
                }
            }
        }
        return $key;
    }

    public static function clearHint(string $flow): void
    {
        if (!in_array($flow, ['login', 'recovery'], true)) { return; }
        TurnstileTrust::cookie(self::hintCookie($flow), '', time() - HOUR_IN_SECONDS);
        unset($_COOKIE[self::hintCookie($flow)]);
    }

    public static function hintCookie(string $flow): string
    {
        return $flow === 'recovery' ? self::RECOVERY_HINT_COOKIE : self::LOGIN_HINT_COOKIE;
    }
}


final class TurnstileTrust
{
    private const TRUST_COOKIE='ascla_turnstile_trust';
    private const TRUST_TTL=DAY_IN_SECONDS;

    public static function trusted(): bool
    {
        $raw=(string)($_COOKIE[self::TRUST_COOKIE]??'');
        $parts=$raw===''?[]:explode('.',$raw);
        $valid=count($parts)===4;
        if ($valid) {
            [$expiry,$ipHash,$uaHash,$signature]=$parts;
            $payload=$expiry.'.'.$ipHash.'.'.$uaHash;
            $valid=ctype_digit($expiry)
                && (int)$expiry>=time()
                && hash_equals(self::sign($payload),$signature)
                && hash_equals($ipHash,self::shortHash(self::clientIp()))
                && hash_equals($uaHash,self::shortHash(self::userAgent()));
        }
        return $valid;
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

    public static function cookie(string $name, string $value, int $expiry): void
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

    public static function userAgent(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    }

    public static function shortHash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, wp_salt('nonce')), 0, 24);
    }

    public static function sign(string $value): string
    {
        return substr(hash_hmac('sha256', $value, wp_salt('secure_auth')), 0, 32);
    }
}
