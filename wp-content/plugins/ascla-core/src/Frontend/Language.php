<?php
namespace ASCLA\Core\Frontend;

/** Per-account WordPress locale; never changes the site's language for other members. */
final class Language
{
    public const SUPPORTED=['es_ES'=>'Español','en_US'=>'English'];
    public static function valid(mixed $value): string
    {
        if(!is_string($value))return '';
        return array_key_exists($value,self::SUPPORTED)?$value:'';
    }
    public static function requested(): string
    {
        return self::valid($_POST['_ascla_locale']??$_GET['wp_lang']??$_COOKIE['wp_lang']??'');
    }
    public static function current(): string
    {
        if(($GLOBALS['pagenow']??'')==='wp-login.php')return self::requested()?:'es_ES';
        return self::valid(get_user_meta(get_current_user_id(),'locale',true))?:'es_ES';
    }
    public static function english(): bool {return str_starts_with(self::current(),'en_');}
    public static function text(string $es,string $en): string {return self::english()?$en:$es;}
    public static function labels(): array
    {
        static $labels;return $labels??=json_decode(file_get_contents(ASCLA_PATH.'languages/en.json'),true)?:[];
    }
    public static function label(string $text): string {return self::english()?(self::labels()[$text]??$text):$text;}
    public static function remember(string $login,\WP_User $user): void
    {
        $locale=self::requested();
        if($locale!=='')update_user_meta($user->ID,'locale',$locale);
    }
    public static function selector(): void
    {
        // ASCLA owns the login selector so WordPress cannot append extra locales (for example a duplicate en_US).
        if(!empty($_GET['interim-login']))return;
        echo '<div class="language-switcher"><form id="language-switcher" method="get" action="'.esc_url(site_url('wp-login.php','login')).'"><label for="ascla-login-language">'.esc_html(self::text('Idioma','Language')).'</label><select id="ascla-login-language" name="wp_lang">';
        foreach(self::SUPPORTED as $locale=>$name)echo '<option value="'.esc_attr($locale).'"'.selected(self::current(),$locale,false).'>'.esc_html($name).'</option>';
        echo '</select>';
        foreach(['action','redirect_to','interim-login','key','login'] as $key){
            $value=$_GET[$key]??($_POST[$key]??'');
            if(is_string($value) && $value!=='')echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        }
        echo '<button type="submit" class="button">'.esc_html(self::text('Cambiar','Change')).'</button></form></div>';
    }
    public static function change(): void
    {
        if(!App::page() || ($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || !isset($_POST['_ascla_change_language']))return;
        if(!is_user_logged_in())return;
        check_admin_referer('ascla_change_language','_ascla_language_nonce');
        $locale=self::valid($_POST['_ascla_locale']??'');
        if($locale!=='')update_user_meta(get_current_user_id(),'locale',$locale);
        $redirect=wp_get_referer()?:get_permalink(get_queried_object_id());
        wp_safe_redirect($redirect?:home_url('/'));
        exit;
    }
    public static function changeAdmin(): void
    {
        if(!is_admin() || !is_user_logged_in() || ($_SERVER['REQUEST_METHOD']??'GET')!=='POST' || !isset($_POST['_ascla_change_language']))return;
        if(!current_user_can('ascla_admin_area'))return;
        check_admin_referer('ascla_change_language','_ascla_language_nonce');
        $locale=self::valid($_POST['_ascla_locale']??'');
        if($locale!=='')update_user_meta(get_current_user_id(),'locale',$locale);
        $redirect=wp_get_referer()?:admin_url('admin.php?page=ascla');
        wp_safe_redirect($redirect);
        exit;
    }
    public static function boot(): void
    {
        add_action('wp_login',[self::class,'remember'],10,2);
        add_action('template_redirect',[self::class,'change'],1);
        add_action('admin_init',[self::class,'changeAdmin'],1);
        add_action('login_footer',[self::class,'selector']);
        add_filter('determine_locale',static function($locale){
            if(($GLOBALS['pagenow']??'')==='wp-login.php')return self::requested()?:'es_ES';
            if(did_action('set_current_user'))return self::valid(get_user_meta(get_current_user_id(),'locale',true))?:$locale;
            return $locale;
        });
        add_filter('locale',static function($locale){
            if(did_action('set_current_user') && get_current_user_id())return self::valid(get_user_meta(get_current_user_id(),'locale',true))?:$locale;
            return $locale;
        });
        // Disable WordPress' native login selector: it may inject its own en_US option.
        add_filter('login_display_language_dropdown','__return_false');
        foreach(['login_form','lostpassword_form','resetpass_form'] as $hook)add_action($hook,static function(){echo '<input type="hidden" name="_ascla_locale" value="'.esc_attr(self::requested()).'">';});
        add_filter('language_attributes',static function($attributes){
            if(($GLOBALS['pagenow']??'')==='wp-login.php' || App::page())return preg_replace('/lang="[^"]*"/','lang="'.esc_attr(str_replace('_','-',self::current())).'"',$attributes);
            return $attributes;
        });
    }
}
