<?php
namespace ASCLA\Core\Frontend;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Services\Access;
final class App
{
    private static function enqueuePageAssets(): void
    {
        $page=self::page();
        if ($page) { self::assets($page); }
    }

    private static function shortcode(array $attrs): string
    {
        if (!Access::member()) { return '<p>Inicie sesión para acceder a la comunidad ASCLA.</p>'; }
        self::assets(sanitize_key($attrs['page']??'intranet'));
        return '<div id="ascla-root"></div>';
    }

    private static function redirectBackendUser(): void
    {
        if (!is_user_logged_in() || current_user_can('manage_options') || wp_doing_ajax() || basename($_SERVER['PHP_SELF']??'')==='admin-post.php') { return; }
        $url=Login::url();
        if (Access::member()) {
            $url=current_user_can('ascla_admin_area')?self::adminUrl():Catalog::url('intranet');
        }
        wp_safe_redirect($url);exit;
    }

    private static function robots(array $robots): array
    {
        if (self::page()) { $robots['noindex']=true;$robots['nofollow']=true; }
        return $robots;
    }

    private static function sitemapArgs(array $args,string $postType): array
    {
        if($postType!=='page'){return $args;}
        $private=array_map('intval',array_values((array)get_option('ascla_pages',[])));$admin=(int)get_option('ascla_admin_front_page',0);
        if($admin>0){$private[]=$admin;}
        $args['post__not_in']=array_values(array_unique(array_merge((array)($args['post__not_in']??[]),$private)));
        return $args;
    }

    public static function boot(): void
    {
        add_action('template_redirect',[self::class,'protect']);
        add_filter('template_include',static fn($template)=>self::page()?ASCLA_PATH.'templates/app.php':$template,99);
        add_action('wp_enqueue_scripts',static fn()=>self::enqueuePageAssets());
        add_shortcode('ascla_app',static fn($attrs)=>self::shortcode((array)$attrs));
        add_filter('show_admin_bar',static fn($show)=>self::page()?false:$show);
        add_action('admin_init',static fn()=>self::redirectBackendUser());
        Login::boot();
        add_filter('wp_robots',static fn($robots)=>self::robots($robots));
        add_filter('wp_sitemaps_posts_query_args',static fn(array $args,string $postType): array=>self::sitemapArgs($args,$postType),10,2);
    }

    public static function page(): string
    {
        if (!is_page()) { return ''; }
        return (string)get_post_meta(get_queried_object_id(),'_ascla_page',true);
    }
    public static function protect(): void
    {
        if (!self::page()) { return; }
        if (!is_user_logged_in()) {
            $target=get_permalink(get_queried_object_id())?:Catalog::url(self::page());
            $query=[];
            foreach((array)$_GET as $key=>$value){
                if(is_scalar($value)){$query[sanitize_key((string)$key)]=sanitize_text_field(wp_unslash((string)$value));}
            }
            if($query){$target=add_query_arg($query,$target);}
            Login::rememberTarget($target);
            wp_safe_redirect(Login::url());
            exit;
        }
        if (!Access::member()) { wp_die('Esta cuenta no tiene acceso a la comunidad ASCLA. Contacte al administrador.','ASCLA',['response'=>403]); }
        if (self::page()==='admin' && !current_user_can('ascla_admin_area')) { wp_die('Esta cuenta no tiene permisos de administración ASCLA.','ASCLA',['response'=>403]); }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
        nocache_headers(); header('X-Robots-Tag: noindex, nofollow'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
    }
    public static function shell(): void
    {
        load_template(ASCLA_PATH.'templates/shell.php',false,[
            'page'=>self::page(),
            'profile'=>\ASCLA\Core\Services\Profiles::visible(get_current_user_id()),
            'settings'=>\ASCLA\Core\Services\Settings::get()
        ]);
    }

    public static function adminUrl(array $args=[]): string
    {
        $id=(int)get_option('ascla_admin_front_page',0);
        $base=home_url('/administracion/');
        if($id>0 && get_post_status($id) && get_post_status($id)!=='trash'){
            $permalink=get_permalink($id);
            if($permalink){$base=$permalink;}
        }
        return add_query_arg($args,$base);
    }

    private static function assetVersion(string $relative): string
    {
        $path=ASCLA_PATH.ltrim($relative,'/');
        $hash=is_file($path)?substr((string)hash_file('sha256',$path),0,12):'missing';
        return ASCLA_VERSION.'.'.$hash;
    }

    public static function assets(string $page): void
    {
        wp_enqueue_style('ascla-app',ASCLA_URL.'assets/app.css',[],self::assetVersion('assets/app.css'));
        wp_enqueue_script('ascla-image-editor',ASCLA_URL.'assets/image-editor.js',[],self::assetVersion('assets/image-editor.js'),true);
        wp_enqueue_script('ascla-content',ASCLA_URL.'assets/content-ui.js',[],self::assetVersion('assets/content-ui.js'),true);
        wp_enqueue_script('ascla-notifications',ASCLA_URL.'assets/notifications-ui.js',[],self::assetVersion('assets/notifications-ui.js'),true);
        wp_enqueue_script('ascla-live-toasts',ASCLA_URL.'assets/live-toasts.js',[],self::assetVersion('assets/live-toasts.js'),true);
        wp_enqueue_script('ascla-navigation',ASCLA_URL.'assets/navigation.js',[],self::assetVersion('assets/navigation.js'),true);
        wp_enqueue_script('ascla-imports',ASCLA_URL.'assets/interest-imports.js',[],self::assetVersion('assets/interest-imports.js'),true);
        wp_enqueue_script('ascla-statistics',ASCLA_URL.'assets/admin-statistics.js',[],self::assetVersion('assets/admin-statistics.js'),true);
        wp_enqueue_script('ascla-app',ASCLA_URL.'assets/app.js',['ascla-imports','ascla-statistics','ascla-image-editor','ascla-content','ascla-notifications','ascla-live-toasts','ascla-navigation'],self::assetVersion('assets/app.js'),true);
        $pages=[]; foreach (Catalog::PAGES as $slug=>$label) { $pages[$slug]=['label'=>Language::label($label),'url'=>Catalog::url($slug)]; }
        $logo=add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo.png');
        $logoWhite=add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo-white.png');
        wp_localize_script('ascla-app','ASCLA',['locale'=>str_replace('_','-',Language::current()),'userLocale'=>Language::current(),'language'=>Language::english()?'en':'es','translations'=>Language::english()?Language::labels():[],'languageOptions'=>Language::SUPPORTED,'languageNonce'=>wp_create_nonce('ascla_change_language'),'api'=>esc_url_raw(rest_url('ascla/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'page'=>$page,'pages'=>$pages,'icons'=>Icons::PATHS,'logo'=>$logo,'logoWhite'=>$logoWhite,'logout'=>wp_logout_url(Login::url()),'adminUrl'=>self::adminUrl(),'mediaUrl'=>admin_url('admin-post.php?action=ascla_media&id=')]);
    }
}
