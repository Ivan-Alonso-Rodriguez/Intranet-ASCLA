<?php
namespace ASCLA\Core\Frontend;

/** WordPress hook callbacks for the ASCLA login presentation. */
final class LoginHooks
{
    public static function frontRobots(array $robots): array
    {
        if (Login::isFrontendPage()) { $robots['noindex']=true;$robots['nofollow']=true; }
        return $robots;
    }

    public static function sitemapArgs(array $args,string $postType): array
    {
        if ($postType!=='page') { return $args; }
        $id=Login::pageId();
        if ($id>0) { $args['post__not_in']=array_values(array_unique(array_merge((array)($args['post__not_in']??[]),[$id]))); }
        return $args;
    }

    public static function enqueueNativeAssets(): void
    {
        wp_enqueue_style('ascla-login',ASCLA_URL.'assets/login.css',['login'],ASCLA_VERSION);
    }

    public static function bodyClasses(array $classes): array
    {
        $classes=array_merge($classes,['ascla-login']);
        if (Login::nativeAdminContext()) { $classes[]='ascla-admin-login'; }
        return $classes;
    }

    public static function siteHtmlLink(): string
    {
        $label=Login::nativeAdminContext()?Language::text('Acceso a la comunidad ASCLA','ASCLA community access'):Language::label('Volver a ASCLA');
        return '<a href="'.esc_url(Login::url()).'">&larr; '.esc_html($label).'</a>';
    }

    public static function headerWelcome(): void
    {
        $asclaAdminContext=Login::nativeAdminContext();
        require_once ASCLA_PATH.'templates/login-welcome.php';
        unset($asclaAdminContext);
    }

    public static function footerHelp(): void
    {
        if (Login::nativeAdminContext()) {
            echo '<p class="ascla-login-help ascla-admin-login-help">'.esc_html(Language::text('Acceso reservado para la administración técnica de ASCLA. Los asociados deben ingresar desde /login/.','Reserved access for ASCLA technical administration. Members should sign in from /login/.')).'</p>';
            return;
        }
        echo '<p class="ascla-login-help">'.esc_html(Language::text('¿Aún no tienes una cuenta? Solicita tu acceso a la administración de ASCLA.','Need an account? Request access from ASCLA administration.')).'</p>';
    }
}
