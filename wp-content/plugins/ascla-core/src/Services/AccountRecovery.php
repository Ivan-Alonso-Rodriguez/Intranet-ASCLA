<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Frontend\{Language,Login};

/** The public recovery response never discloses whether an account exists. */
final class AccountRecovery
{
    public static function boot(): void
    {
        add_action('login_form_lostpassword',[self::class,'controller']);
        add_action('login_form_retrievepassword',[self::class,'controller']);
    }
    public static function request(string $identifier): true|\WP_Error
    {
        if (trim($identifier)==='' || strlen($identifier)>320) {
            return new \WP_Error('empty_username',Language::text('Escribe tu usuario o correo electrónico.','Enter your username or email address.'));
        }
        $result=retrieve_password($identifier);
        if (!is_wp_error($result)) { return true; }
        return self::publicResult($result);
    }

    private static function publicResult(\WP_Error $result): true|\WP_Error
    {
        // Captcha and third-party restrictions still prevent issuing a reset key.
        $accountErrors=['invalid_email','invalidcombo','retrieve_password_email_failure','no_password_reset','invalidcombo_email'];
        foreach ($result->get_error_codes() as $code) {
            if (in_array($code,$accountErrors,true)) { continue; }
            $message=str_starts_with((string)$code,'ascla_turnstile_')?$result->get_error_message($code):Language::text('No se pudo procesar la solicitud. Inténtalo nuevamente.','The request could not be processed. Please try again.');
            return new \WP_Error($code,$message);
        }
        return true;
    }
    public static function controller(): void
    {
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { return; }
        $identifier=isset($_POST['user_login']) && is_string($_POST['user_login'])?wp_unslash($_POST['user_login']):'';
        $result=self::request($identifier);
        if ($result===true) {
            $copy=Language::text('Si existe una cuenta asociada, recibirás un enlace de recuperación. Revisa también la carpeta de spam.','If an account matches, you will receive a recovery link. Please also check your spam folder.');
            login_header(Language::text('Recuperar contraseña','Reset password'),'<p class="message">'.esc_html($copy).'</p>');
        } else {
            login_header(Language::text('Recuperar contraseña','Reset password'),'',$result);
            self::form($identifier);
        }
        echo '<p id="nav"><a href="'.esc_url(Login::url()).'">'.esc_html(Language::text('Volver al inicio de sesión','Back to sign in')).'</a></p>';
        login_footer();exit;
    }
    private static function form(string $identifier): void
    {
        echo '<form id="lostpasswordform" method="post" action="'.esc_url(network_site_url('wp-login.php?action=lostpassword','login_post')).'"><p><label for="user_login">'.esc_html(Language::text('Usuario o correo electrónico','Username or email')).'</label><input id="user_login" class="input" name="user_login" type="text" autocomplete="username" required value="'.esc_attr($identifier).'" /></p>';
        do_action('lostpassword_form');
        echo '<p class="submit"><button id="wp-submit" class="button button-primary button-large" type="submit">'.esc_html(Language::text('Enviar enlace de recuperación','Send recovery link')).'</button></p></form>';
    }
}
