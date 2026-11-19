<?php
namespace ASCLA\Core\Frontend;

/** Bind anonymous login nonces to one browser, rather than WordPress guest user 0. */
final class LoginCsrf
{
    private const COOKIE='ascla_login_guest';
    private const ACTIONS=['ascla_frontend_login','ascla_login_language','ascla_native_login'];

    public static function boot(): void
    {
        add_action('template_redirect',static function(){ if (Login::isFrontendPage()) { self::prepare(); } },1);
        add_action('login_init',[self::class,'prepare'],1);
        add_filter('nonce_user_logged_out',[self::class,'guestUser'],10,2);
        add_action('login_form',static fn()=>wp_nonce_field('ascla_native_login','_ascla_native_login_nonce',false));
        add_filter('authenticate',[self::class,'nativeLogin'],100,3);
    }

    public static function prepare(): void
    {
        if (is_user_logged_in()) { return; }
        $name=self::cookieName();$cookie=$_COOKIE[$name]??'';
        if (is_string($cookie) && preg_match('/^[a-f0-9]{64}$/D',$cookie)) { return; }
        $cookie=bin2hex(random_bytes(32));$_COOKIE[$name]=$cookie;
        if (!headers_sent()) { setcookie($name,$cookie,['expires'=>time()+2*HOUR_IN_SECONDS,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']); }
    }

    private static function cookieName(): string { return (is_ssl()?'__Host-':'').self::COOKIE; }

    public static function guestUser($user,string $action)
    {
        if (!in_array($action,self::ACTIONS,true)) { return $user; }
        self::prepare();
        return hash_hmac('sha256',(string)($_COOKIE[self::cookieName()]??''),wp_salt('nonce'));
    }

    public static function nativeLogin($user,string $login,string $password)
    {
        unset($password);
        if (($GLOBALS['pagenow']??'')!=='wp-login.php' || ($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || $login==='' || is_wp_error($user)) { return $user; }
        $nonce=$_POST['_ascla_native_login_nonce']??'';
        if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)),'ascla_native_login')) {
            return new \WP_Error('ascla_login_expired',Language::text('La sesión del formulario expiró. Recarga la página e inténtalo nuevamente.','The form session expired. Reload the page and try again.'));
        }
        return $user;
    }
}
