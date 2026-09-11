<?php
namespace ASCLA\Core\Frontend;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Services\Access;
final class App
{
    public static function boot(): void
    {
        add_action('template_redirect',[self::class,'protect']);
        add_filter('template_include',static fn($template)=>self::page()?ASCLA_PATH.'templates/app.php':$template,99);
        add_action('wp_enqueue_scripts',static function () { if (self::page()) { self::assets(self::page()); } });
        add_shortcode('ascla_app',static function ($attrs) {
            if (!Access::member()) { return '<p>Inicie sesión para acceder a la comunidad ASCLA.</p>'; }
            self::assets(sanitize_key($attrs['page']??'intranet')); return '<div id="ascla-root"></div>';
        });
        add_filter('show_admin_bar',static fn($show)=>self::page()?false:$show);
        add_action('admin_init',static function () {
            if (Access::member()&&!current_user_can('ascla_moderate')&&!wp_doing_ajax()&&basename($_SERVER['PHP_SELF']??'')!=='admin-post.php') { wp_safe_redirect(Catalog::url('intranet')); exit; }
        });
        Login::boot();
        add_filter('wp_robots',static function ($robots) { if (self::page()) { $robots['noindex']=true; $robots['nofollow']=true; } return $robots; });
    }
    public static function page(): string
    {
        if (!is_page()) { return ''; }
        return (string)get_post_meta(get_queried_object_id(),'_ascla_page',true);
    }
    public static function protect(): void
    {
        if (!self::page()) { return; }
        if (!is_user_logged_in()) { auth_redirect(); }
        if (!Access::member()) { wp_die('Esta cuenta no tiene acceso a la comunidad ASCLA. Contacte al administrador.','ASCLA',['response'=>403]); }
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

    public static function assets(string $page): void
    {
        wp_enqueue_style('ascla-app',ASCLA_URL.'assets/app.css',[],ASCLA_VERSION);
        wp_enqueue_script('ascla-content',ASCLA_URL.'assets/content-ui.js',[],ASCLA_VERSION,true);
        wp_enqueue_script('ascla-notifications',ASCLA_URL.'assets/notifications-ui.js',[],ASCLA_VERSION,true);
        wp_enqueue_script('ascla-navigation',ASCLA_URL.'assets/navigation.js',[],ASCLA_VERSION,true);
        wp_enqueue_script('ascla-app',ASCLA_URL.'assets/app.js',['ascla-content','ascla-notifications','ascla-navigation'],ASCLA_VERSION,true);
        $pages=[]; foreach (Catalog::PAGES as $slug=>$label) { $pages[$slug]=['label'=>$label,'url'=>Catalog::url($slug)]; }
        wp_localize_script('ascla-app','ASCLA',['api'=>esc_url_raw(rest_url('ascla/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'page'=>$page,'pages'=>$pages,'icons'=>Icons::PATHS,'logo'=>ASCLA_URL.'assets/ascla-logo.png','logout'=>wp_logout_url(wp_login_url()),'adminUrl'=>admin_url('admin.php?page=ascla'),'mediaUrl'=>admin_url('admin-post.php?action=ascla_media&id=')]);
    }
}
