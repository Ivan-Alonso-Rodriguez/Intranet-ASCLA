<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Frontend\{Language,Login};
use ASCLA\Core\Domain\Catalog;

/** The public recovery response never discloses whether an account exists. */
final class AccountRecovery
{
    public static function boot(): void
    {
        add_action('login_form_lostpassword',[self::class,'controller']);
        add_action('login_form_retrievepassword',[self::class,'controller']);
        add_action('login_form_checkemail',[self::class,'controller']);
        add_filter('lostpassword_redirect',static fn()=>self::confirmationUrl());
        add_filter('login_url',[self::class,'loginUrl'],20,1);
        add_filter('lostpassword_url',[self::class,'lostPasswordUrl'],20,2);
        add_action('lostpassword_form',[self::class,'contextField']);
        add_action('resetpass_form',[self::class,'contextField']);
        add_filter('retrieve_password_message',[self::class,'resetMessage'],20,3);
        add_action('after_password_reset',[self::class,'enterIntranet'],20,2);
    }
    public static function confirmationUrl(): string
    {
        $args=['action'=>'lostpassword','ascla_recovery'=>'sent','wp_lang'=>Language::requested()?:Language::current()];
        $args['ascla_login_context']=self::communityContext()?'community':'admin';
        return add_query_arg($args,network_site_url('wp-login.php','login'));
    }
    private static function communityContext(): bool
    {
        $context=$_POST['ascla_login_context']??$_GET['ascla_login_context']??'';
        if (in_array($context,['community','admin'],true)) { return $context==='community'; }
        $redirect=$_POST['redirect_to']??$_GET['redirect_to']??'';
        return !is_string($redirect) || untrailingslashit(wp_unslash($redirect))!==untrailingslashit(admin_url());
    }
    public static function lostPasswordUrl(string $url,string $redirect): string
    {
        $context=untrailingslashit($redirect)===untrailingslashit(admin_url())?'admin':'community';
        return add_query_arg('ascla_login_context',$context,$url);
    }
    public static function loginUrl(string $url): string
    {
        return ($GLOBALS['pagenow']??'')==='wp-login.php' && in_array($GLOBALS['action']??'',['lostpassword','retrievepassword','rp','resetpass','checkemail'],true) && self::communityContext()?Login::url():$url;
    }
    public static function contextField(): void
    {
        echo '<input type="hidden" name="ascla_login_context" value="'.(self::communityContext()?'community':'admin').'">';
    }
    public static function resetMessage(string $message,string $key,string $login): string
    {
        $page=network_site_url('wp-login.php','login').'?';
        $context=self::communityContext()?'community':'admin';
        return preg_replace_callback('~https?://[^\s<>"]+~i',static function(array $match) use ($page,$key,$login,$context): string {
            $url=$match[0];
            if (!str_starts_with($url,$page)) { return $url; }
            parse_str(substr($url,strlen($page)),$query);
            if (($query['action']??'')!=='rp' || ($query['login']??'')!==$login || ($query['key']??'')!==$key) { return $url; }
            return add_query_arg('ascla_login_context',$context,$url);
        },$message)??$message;
    }

    public static function enterIntranet(\WP_User $user,#[\SensitiveParameter] string $password): void
    {
        if (($GLOBALS['pagenow']??'')!=='wp-login.php'
            || !in_array($GLOBALS['action']??'',['rp','resetpass'],true)
            || ($_SERVER['REQUEST_METHOD']??'')!=='POST'
            || !self::communityContext() || !Access::member($user->ID)) { return; }

        $stored=get_userdata($user->ID);
        if (!$stored || !wp_check_password($password,$stored->user_pass,$user->ID)) { return; }

        $path=wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        if (is_string($path) && $path!=='') {
            setcookie('wp-resetpass-'.COOKIEHASH,'',time()-YEAR_IN_SECONDS,$path,COOKIE_DOMAIN,is_ssl(),true);
        }
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        do_action('wp_login',$user->user_login,$user);
        wp_safe_redirect(Catalog::url('intranet'),303);
        exit;
    }
    public static function confirmationMessage(): string
    {
        return Language::text('Si existe una cuenta asociada, recibirás un enlace de recuperación. Revisa también la carpeta de spam.','If an account matches, you will receive a recovery link. Please also check your spam folder.');
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
        $posted=($_SERVER['REQUEST_METHOD']??'GET')==='POST';
        $confirmed=($_GET['ascla_recovery']??'')==='sent'||($_GET['checkemail']??'')==='confirm';
        if (!$posted&&!$confirmed) { return; }
        $GLOBALS['action']='lostpassword';
        $identifier=isset($_POST['user_login']) && is_string($_POST['user_login'])?wp_unslash($_POST['user_login']):'';
        $result=$posted?self::request($identifier):true;
        nocache_headers();
        if ($posted&&$result===true) { wp_safe_redirect(self::confirmationUrl(),303);exit; }
        if ($result===true) {
            login_header(Language::text('Revisa tu correo','Check your email'),'<div class="message ascla-recovery-confirmation" role="status"><strong>'.esc_html(Language::text('Solicitud enviada','Request sent')).'</strong><p>'.esc_html(self::confirmationMessage()).'</p></div>');
        } else {
            login_header(Language::text('Recuperar contraseña','Reset password'),'',$result);
            self::form($identifier);
        }
        echo '<p id="nav"><a href="'.esc_url(self::communityContext()?Login::url():site_url('wp-login.php','login')).'">'.esc_html(Language::text('Volver al inicio de sesión','Back to sign in')).'</a></p>';
        if ($result===true) {
            $retry=remove_query_arg('ascla_recovery',self::confirmationUrl());
            echo '<p class="ascla-recovery-retry"><a href="'.esc_url($retry).'">'.esc_html(Language::text('Enviar otro enlace de recuperación','Send another recovery link')).'</a></p>';
        }
        login_footer();exit;
    }
    private static function form(string $identifier): void
    {
        echo '<form id="lostpasswordform" method="post" action="'.esc_url(network_site_url('wp-login.php?action=lostpassword','login_post')).'"><p><label for="user_login">'.esc_html(Language::text('Usuario o correo electrónico','Username or email')).'</label><input id="user_login" class="input" name="user_login" type="text" autocomplete="username" required value="'.esc_attr($identifier).'" /></p>';
        do_action('lostpassword_form');
        echo '<p class="submit"><button id="wp-submit" class="button button-primary button-large" type="submit">'.esc_html(Language::text('Enviar enlace de recuperación','Send recovery link')).'</button></p></form>';
    }
}
