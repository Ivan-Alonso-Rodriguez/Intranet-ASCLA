<?php
namespace ASCLA\Core\Frontend;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Services\Access;
use ASCLA\Core\Services\Turnstile;

/**
 * ASCLA authentication entry point.
 *
 * Members use a dedicated public /login/ page while WordPress keeps wp-login.php
 * intact for native recovery, reset and technical administrator flows.
 */
final class Login
{
    private const PAGE_OPTION = 'ascla_login_page';
    private const RETURN_COOKIE = 'ascla_login_return';
    private const NOTICE_COOKIE = 'ascla_login_notice';
    private const RETURN_TTL = 900;
    private static ?\WP_Error $frontError = null;

    public static function boot(): void
    {
        Language::boot();
        add_filter('pre_option_users_can_register',static fn()=>0);
        add_action('template_redirect',[self::class,'frontController'],2);
        add_filter('template_include',static fn($template)=>self::isFrontendPage()?ASCLA_PATH.'templates/login.php':$template,100);
        add_action('wp_enqueue_scripts',[self::class,'frontAssets'],100);
        add_filter('wp_robots',[LoginHooks::class,'frontRobots']);
        add_filter('wp_sitemaps_posts_query_args',[LoginHooks::class,'sitemapArgs'],10,2);
        add_action('login_init',static fn()=>add_filter('gettext',[self::class,'translate'],10,3));
        add_action('login_head',[Theme::class,'printScript'],0);
        add_action('login_enqueue_scripts',[LoginHooks::class,'enqueueNativeAssets']);
        add_filter('login_body_class',[LoginHooks::class,'bodyClasses']);
        add_filter('login_site_html_link',[LoginHooks::class,'siteHtmlLink']);
        add_filter('login_remember_me_help_text',static fn()=>Language::text('Mantiene tu sesión durante más tiempo. Usa esta opción solo en tus dispositivos personales.','Keeps you signed in longer. Use this only on your own devices.'));
        add_filter('login_headerurl',static fn()=>self::nativeAdminContext()?admin_url():self::url());
        add_filter('login_headertext',static fn()=>self::nativeAdminContext()?Language::text('ASCLA · Administración de WordPress','ASCLA · WordPress administration'):Language::text('ASCLA · Comunidad profesional','ASCLA · Professional community'));
        add_filter('login_title',static fn($title,$screen)=>esc_html($screen.(self::nativeAdminContext()?' · Administración ASCLA':' · ASCLA')),10,2);
        add_action('login_header',[LoginHooks::class,'headerWelcome']);
        add_filter('login_message',[self::class,'welcome']);
        add_action('login_footer',[LoginHooks::class,'footerHelp']);
        add_filter('login_redirect',[self::class,'redirect'],10,3);
        add_action('wp_logout',[self::class,'markLoggedOut']);
    }

    /** Native wp-login.php is reserved as the technical administration entry point. */
    public static function nativeAdminContext(): bool
    {
        if (self::isFrontendRequest()) { return false; }
        if (!empty($_REQUEST['interim-login'])) { return false; }
        return ($GLOBALS['action'] ?? 'login') === 'login';
    }

    public static function pageId(): int
    {
        return (int)get_option(self::PAGE_OPTION, 0);
    }

    public static function url(array $args = []): string
    {
        $id = self::pageId();
        $base = $id > 0 && get_post_status($id) && get_post_status($id) !== 'trash'
            ? get_permalink($id)
            : home_url('/login/');
        return add_query_arg($args, $base ?: home_url('/login/'));
    }

    public static function isFrontendPage(): bool
    {
        if (!function_exists('is_page') || !is_page()) { return false; }
        $id = self::pageId();
        return $id > 0 && get_queried_object_id() === $id;
    }

    /** Request-level check that is safe before WordPress conditional tags are available. */
    public static function isFrontendRequest(): bool
    {
        if (!empty($_REQUEST['ascla_frontend_login'])) { return true; }
        $requestPath=untrailingslashit((string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH));
        if($requestPath===''){ return false; }
        $id=self::pageId();
        $slug=$id>0?(string)get_post_field('post_name',$id):'login';
        $slug=trim($slug,'/');
        return $slug!=='' && ($requestPath==='/'.$slug || str_ends_with($requestPath,'/'.$slug));
    }

    public static function frontAssets(): void
    {
        if (!self::isFrontendPage()) { return; }
        wp_enqueue_style('login');
        wp_enqueue_style('dashicons');
        wp_enqueue_style('ascla-login', ASCLA_URL . 'assets/login.css', ['login'], ASCLA_VERSION);
        // Turnstile normally enqueues itself on wp-login.php. The dedicated ASCLA login
        // calls the same policy so adaptive protection is preserved instead of duplicated.
        Turnstile::enqueue();
    }

    public static function frontController(): void
    {
        if (!self::isFrontendPage()) { return; }
        LoginRequest::prepareFrontendResponse();LoginRequest::canonicalizeLegacyRequest();
        $targetFor=static fn(\WP_User $user,string $target): string=>self::targetFor($user,$target);
        if (is_user_logged_in()) { self::$frontError=LoginRequest::loggedInError($targetFor);return; }
        if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') { self::$frontError=LoginRequest::loginPost($targetFor); }
    }

    public static function frontError(): ?\WP_Error
    {
        return self::$frontError;
    }

    public static function frontErrorMessage(): string
    {
        $error=self::frontError();
        if (!$error) { return ''; }
        $code=(string)$error->get_error_code();
        return match(true) {
            in_array($code,['incorrect_password','invalid_username','invalid_email'],true)=>Language::text('El usuario/correo o la contraseña no son correctos.','The username/email or password is incorrect.'),
            $code==='empty_username'=>Language::text('Escribe tu usuario o correo electrónico.','Enter your username or email address.'),
            $code==='empty_password'=>Language::text('Escribe tu contraseña.','Enter your password.'),
            default=>wp_strip_all_tags((string)$error->get_error_message()),
        };
    }

    /**
     * Keep the browser address at /login/ while preserving the intended internal page
     * in a short-lived, signed HttpOnly cookie. Query-string redirect_to remains accepted
     * only as a backwards-compatible input and is canonicalized immediately.
     */
    public static function rememberTarget(string $target): void
    {
        $safe = wp_validate_redirect(trim($target), '');
        if ($safe === '' || strlen($safe) > 2500) {
            self::forgetTarget();
            return;
        }
        $payload = rtrim(strtr(base64_encode($safe), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
        self::setBrowserCookie(self::RETURN_COOKIE, $payload . '.' . $signature, time() + self::RETURN_TTL, true);
    }

    public static function requestedTarget(): string
    {
        $legacy=$_POST['redirect_to']??$_GET['redirect_to']??'';
        if (is_string($legacy) && trim($legacy)!=='') { return trim(wp_unslash($legacy)); }
        $packed=isset($_COOKIE[self::RETURN_COOKIE])&&is_string($_COOKIE[self::RETURN_COOKIE])
            ?(string)wp_unslash($_COOKIE[self::RETURN_COOKIE]):'';
        $target='';
        if ($packed!=='' && str_contains($packed,'.')) {
            [$payload,$signature]=explode('.',$packed,2);
            $expected=hash_hmac('sha256',$payload,wp_salt('auth'));
            if (hash_equals($expected,$signature)) {
                $normalized=strtr($payload,'-_','+/');
                $padding=strlen($normalized)%4;
                if ($padding) { $normalized.=str_repeat('=',4-$padding); }
                $decoded=base64_decode($normalized,true);
                $target=is_string($decoded)?trim($decoded):'';
            } else {
                self::forgetTarget();
            }
        }
        return $target;
    }

    public static function forgetTarget(): void
    {
        self::setBrowserCookie(self::RETURN_COOKIE, '', time() - 3600, true);
    }

    public static function markLoggedOut(): void
    {
        self::setBrowserCookie(self::NOTICE_COOKIE, 'logged_out', time() + 120, true);
    }

    public static function consumeLoggedOutNotice(): bool
    {
        $visible = isset($_COOKIE[self::NOTICE_COOKIE]) && $_COOKIE[self::NOTICE_COOKIE] === 'logged_out';
        if ($visible) { self::setBrowserCookie(self::NOTICE_COOKIE, '', time() - 3600, true); }
        return $visible;
    }

    private static function setBrowserCookie(string $name, string $value, int $expires, bool $httpOnly): void
    {
        if (headers_sent()) { return; }
        $options = [
            'expires' => $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ];
        if (defined('COOKIE_DOMAIN') && is_string(COOKIE_DOMAIN) && COOKIE_DOMAIN !== '') {
            $options['domain'] = COOKIE_DOMAIN;
        }
        setcookie($name, $value, $options);
        if ($expires > time() && $value !== '') { $_COOKIE[$name] = $value; }
        else { unset($_COOKIE[$name]); }
    }

    private static function targetFor(\WP_User $user, string $requested): string
    {
        $fallback=Catalog::url('intranet');
        if ($requested==='') { return $fallback; }
        $safe=wp_validate_redirect($requested,$fallback);
        $allowed=false;
        if (user_can($user,'manage_options')) { $allowed=str_starts_with($safe,admin_url()); }
        if (!$allowed && user_can($user,'ascla_admin_area')) {
            $adminPageId=(int)get_option('ascla_admin_front_page',0);
            $allowed=$adminPageId>0 && url_to_postid($safe)===$adminPageId;
        }
        return $allowed?$safe:self::redirect($fallback,$safe,$user);
    }

    /** Spanish UI copy is scoped to wp-login.php; native form handling stays with WordPress. */
    public static function translate(string $translation, string $text, string $domain): string
    {
        if ($domain !== 'default' || Language::english()) { return $translation; }
        $adminLabels=['Log In'=>'Entrar a administración','Log in'=>'Volver al acceso administrativo'];
        if (self::nativeAdminContext() && isset($adminLabels[$text])) { return $adminLabels[$text]; }
        static $labels = [
            'Log In' => 'Entrar a la comunidad',
            'Log in' => 'Volver al inicio de sesión',
            'Username or Email Address' => 'Usuario o correo electrónico',
            'Username' => 'Usuario',
            'Password' => 'Contraseña',
            'Remember Me' => 'Recordarme',
            'Lost your password?' => '¿Olvidaste tu contraseña?',
            'Lost Password' => 'Recuperar contraseña',
            'Get New Password' => 'Enviar enlace de recuperación',
            'Show password' => 'Mostrar contraseña',
            'Hide password' => 'Ocultar contraseña',
            '&larr; Go to %s' => '&larr; Volver a %s',
            'Reset Password' => 'Restablecer contraseña',
            'New password' => 'Nueva contraseña',
            'Confirm new password' => 'Confirmar nueva contraseña',
            'Save Password' => 'Guardar contraseña',
            'Generate Password' => 'Generar contraseña',
            'Strength indicator' => 'Seguridad de la contraseña',
            'Confirm use of weak password' => 'Confirmar el uso de una contraseña débil',
            'Please enter your username or email address. You will receive an email message with instructions on how to reset your password.' => 'Escribe tu usuario o correo electrónico. Recibirás un enlace para restablecer tu contraseña.',
            'Enter your new password below or generate one.' => 'Escribe una nueva contraseña o genera una automáticamente.',
            'Your password has been reset.' => 'Tu contraseña se ha restablecido.',
            'You are now logged out.' => 'Has cerrado sesión.',
            'You have logged in successfully.' => 'Has iniciado sesión correctamente.',
            '<strong>Error:</strong> The username field is empty.' => '<strong>Error:</strong> Escribe tu usuario.',
            '<strong>Error:</strong> The password field is empty.' => '<strong>Error:</strong> Escribe tu contraseña.',
            '<strong>Error:</strong> The email field is empty.' => '<strong>Error:</strong> Escribe tu correo electrónico.',
            '<strong>Error:</strong> The password you entered for the username %s is incorrect.' => '<strong>Error:</strong> La contraseña de %s es incorrecta.',
            '<strong>Error:</strong> The password you entered for the email address %s is incorrect.' => '<strong>Error:</strong> La contraseña de %s es incorrecta.',
            '<strong>Error:</strong> The username <strong>%s</strong> is not registered on this site. If you are unsure of your username, try your email address instead.' => '<strong>Error:</strong> El usuario <strong>%s</strong> no está registrado. Prueba con tu correo electrónico.',
            'Unknown email address. Check again or try your username.' => 'No se reconoce ese correo electrónico. Revísalo o prueba con tu usuario.',
        ];
        return $labels[$text] ?? $translation;
    }

    public static function welcome(string $message): string
    {
        $admin = self::nativeAdminContext();
        if ($admin) {
            $copy = Language::english()
                ? ['Administrative access','Sign in with an administrator account to manage WordPress and the ASCLA platform.']
                : ['Acceso administrativo','Ingresa con una cuenta administradora para gestionar WordPress y la plataforma ASCLA.'];
            $eyebrow = Language::text('ADMINISTRACIÓN ASCLA','ASCLA ADMINISTRATION');
        } else {
            $copy = match ($GLOBALS['action'] ?? 'login') {
                'login' => ['Te damos la bienvenida', 'Ingresa con tu cuenta para acceder a la comunidad.'],
                'lostpassword', 'retrievepassword' => ['Recupera tu acceso', 'Te ayudamos a volver a tu comunidad.'],
                'resetpass', 'rp' => ['Elige tu nueva contraseña', 'Protege tu cuenta con una contraseña única.'],
                'register' => ['Acceso exclusivo para asociados', 'Las cuentas ASCLA son creadas únicamente por la administración.'],
                default => ['Tu cuenta ASCLA', 'Gestiona tu acceso a la comunidad.'],
            };
            if(Language::english()) { $copy=match($GLOBALS['action']??'login') {
                'login'=>['Welcome to ASCLA','Sign in to access your community.'],
                'lostpassword','retrievepassword'=>['Recover your access','Let us help you return to your community.'],
                'resetpass','rp'=>['Choose a new password','Protect your account with a unique password.'],
                'register'=>['Members-only access','ASCLA accounts are created only by the administration.'],
                default=>['Your ASCLA account','Manage access to your community.'],
            };
            }
            $eyebrow = 'INTRANET ASCLA';
        }
        return '<div class="ascla-login-intro"><p class="ascla-login-eyebrow">' . esc_html($eyebrow) . '</p><h2>'
            . esc_html($copy[0]) . '</h2><p>' . esc_html($copy[1]) . '</p></div>' . $message;
    }

    public static function redirect(string $redirect, string $requested, $user): string
    {
        if (!$user instanceof \WP_User || !user_can($user, 'ascla_access') || user_can($user, 'manage_options')) {
            return $redirect;
        }
        $fallback = Catalog::url('intranet');
        $target = wp_validate_redirect($requested, $fallback);
        if(user_can($user,'ascla_admin_area') && str_starts_with($target,admin_url())){
            parse_str((string)wp_parse_url($target,PHP_URL_QUERY),$query);
            $page=is_string($query['page']??null)?sanitize_key($query['page']):'';
            return App::adminUrl($page!==''?['page'=>$page]:[]);
        }
        // Only ASCLA page IDs may receive member redirects, including query-string permalinks.
        $pageId = url_to_postid($target);
        return $pageId && in_array($pageId, array_map('intval', get_option('ascla_pages', [])), true)
            ? $target : $fallback;
    }
}
