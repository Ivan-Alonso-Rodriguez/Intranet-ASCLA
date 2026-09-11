<?php
namespace ASCLA\Core\Frontend;

/** Per-account WordPress locale; never changes the site's language for other members. */
final class Language
{
    public static function valid(mixed $value): string
    {
        if(!is_string($value))return '';
        return in_array($value,array_merge(['en_US','es_ES'],get_available_languages()),true)?$value:'';
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
        // WordPress omits its selector when no language packs exist. Keep ASCLA's two UI languages available.
        if(get_available_languages() || !empty($_GET['interim-login']))return;
        echo '<div class="language-switcher"><form id="language-switcher" method="get" action="'.esc_url(site_url('wp-login.php','login')).'"><label for="ascla-login-language">'.esc_html(self::text('Idioma','Language')).'</label><select id="ascla-login-language" name="wp_lang">';
        foreach(['es_ES'=>'Español','en_US'=>'English (United States)'] as $locale=>$name)echo '<option value="'.esc_attr($locale).'"'.selected(self::current(),$locale,false).'>'.esc_html($name).'</option>';
        echo '</select>';
        foreach(['action','redirect_to','interim-login','key','login'] as $key){
            $value=$_GET[$key]??($_POST[$key]??'');
            if(is_string($value) && $value!=='')echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        }
        echo '<button type="submit" class="button">'.esc_html(self::text('Cambiar','Change')).'</button></form></div>';
    }
    public static function boot(): void
    {
        add_action('wp_login',[self::class,'remember'],10,2);
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
        add_filter('login_language_dropdown_args',static function($args){$args['languages']=array_values(array_unique(array_merge($args['languages']??[],['es_ES','en_US'])));$args['selected']=self::current();return $args;});
        foreach(['login_form','lostpassword_form','resetpass_form','register_form'] as $hook)add_action($hook,static function(){echo '<input type="hidden" name="_ascla_locale" value="'.esc_attr(self::requested()).'">';});
        add_filter('language_attributes',static function($attributes){
            if(($GLOBALS['pagenow']??'')==='wp-login.php' || App::page())return preg_replace('/lang="[^"]*"/','lang="'.esc_attr(str_replace('_','-',self::current())).'"',$attributes);
            return $attributes;
        });
    }
}
