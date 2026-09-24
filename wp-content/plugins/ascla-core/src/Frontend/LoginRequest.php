<?php
namespace ASCLA\Core\Frontend;
use ASCLA\Core\Services\Access;

/** Request-level login flow kept separate from the public Login facade. */
final class LoginRequest
{
    public static function prepareFrontendResponse(): void
    {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }

    public static function canonicalizeLegacyRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { return; }
        $canonicalize=false;
        if (isset($_GET['redirect_to']) && is_string($_GET['redirect_to'])) { Login::rememberTarget((string)wp_unslash($_GET['redirect_to']));$canonicalize=true; }
        if (isset($_GET['logged_out'])) { Login::markLoggedOut();$canonicalize=true; }
        if (isset($_GET['wp_lang']) && is_string($_GET['wp_lang'])) { Language::rememberLoginChoice((string)wp_unslash($_GET['wp_lang']));$canonicalize=true; }
        if ($canonicalize) { wp_safe_redirect(Login::url());exit; }
    }

    public static function loggedInError(callable $targetFor): ?\WP_Error
    {
        $user=wp_get_current_user();
        if (Access::member((int)$user->ID)) {
            $target=Login::requestedTarget();Login::forgetTarget();wp_safe_redirect($targetFor($user,$target));exit;
        }
        return new \WP_Error('ascla_access_denied',Language::text('Esta cuenta no tiene acceso a la comunidad ASCLA. Contacta al administrador.','This account does not have access to the ASCLA community. Contact the administrator.'));
    }

    private static function credentialsError(string $login,string $password): ?\WP_Error
    {
        if ($login==='') { return new \WP_Error('empty_username',Language::text('Escribe tu usuario o correo electrónico.','Enter your username or email address.')); }
        if ($password==='') { return new \WP_Error('empty_password',Language::text('Escribe tu contraseña.','Enter your password.')); }
        return null;
    }

    private static function handleLanguagePost(): void
    {
        $nonce=isset($_POST['_ascla_language_nonce'])?sanitize_text_field(wp_unslash($_POST['_ascla_language_nonce'])):'';
        if ($nonce && wp_verify_nonce($nonce,'ascla_login_language')) {
            Language::rememberLoginChoice((string)($_POST['_ascla_locale']??''));
        }
        wp_safe_redirect(Login::url());exit;
    }

    private static function signIn(string $login,string $password,callable $targetFor): ?\WP_Error
    {
        $_POST['ascla_frontend_login']='1';
        $user=wp_signon(['user_login'=>$login,'user_password'=>$password,'remember'=>!empty($_POST['rememberme'])],is_ssl());
        if (is_wp_error($user)) { return $user; }
        if (!Access::member((int)$user->ID)) {
            wp_clear_auth_cookie();Login::forgetTarget();
            return new \WP_Error('ascla_access_denied',Language::text('Esta cuenta no tiene acceso a la comunidad ASCLA. Contacta al administrador.','This account does not have access to the ASCLA community. Contact the administrator.'));
        }
        $target=Login::requestedTarget();Login::forgetTarget();wp_safe_redirect($targetFor($user,$target));exit;
    }

    public static function loginPost(callable $targetFor): ?\WP_Error
    {
        if (!empty($_POST['_ascla_login_language'])) { self::handleLanguagePost(); }
        $nonce=isset($_POST['_ascla_login_nonce'])?sanitize_text_field(wp_unslash($_POST['_ascla_login_nonce'])):'';
        if (!$nonce || !wp_verify_nonce($nonce,'ascla_frontend_login')) {
            return new \WP_Error('ascla_login_expired',Language::text('La sesión del formulario expiró. Recarga la página e inténtalo nuevamente.','The form session expired. Reload the page and try again.'));
        }
        $login=isset($_POST['log'])&&is_string($_POST['log'])?trim(wp_unslash($_POST['log'])):'';
        $password=isset($_POST['pwd'])&&is_string($_POST['pwd'])?(string)wp_unslash($_POST['pwd']):'';
        $error=self::credentialsError($login,$password);
        return $error??self::signIn($login,$password,$targetFor);
    }

}
