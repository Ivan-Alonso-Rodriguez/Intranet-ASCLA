<?php
namespace ASCLA\Core;

final class Plugin
{
    public static function boot(): void
    {
        add_action('init', [Domain\Catalog::class, 'register']);
        add_action('init', [Database\Installer::class, 'upgrade'], 20);
        add_filter('posts_search',[Repositories\ContentQuery::class,'search'],10,2);
        Frontend\App::boot();
        Admin\Panel::boot();
        Rest\Router::boot();
        Jobs\Queue::boot();
        Services\Media::boot();
        add_action('added_post_meta',[Services\Content::class,'indexMeta'],10,4);
        add_action('updated_post_meta',[Services\Content::class,'indexMeta'],10,4);
        Integrations\GoogleOAuth::boot();
        add_action('transition_post_status', [Services\Content::class, 'published'], 10, 3);
        add_filter('wp_insert_post_data', [Services\Content::class, 'guardPublication'], 10, 2);
        add_filter('preprocess_comment', static function ($comment) {
            if (str_starts_with((string)get_post_type((int)($comment['comment_post_ID']??0)),'ascla_')) {
                wp_die('Utilice el formulario de comentarios de la comunidad ASCLA.','ASCLA',['response'=>403]);
            }
            return $comment;
        });
        add_filter('comment_feed_where',[Repositories\Store::class,'privateCommentFeedFilter']);
        add_filter('widget_comments_args', static function ($args) {
            $types=(array)($args['post_type']??get_post_types());
            $args['post_type']=array_values(array_filter($types,static fn($type)=>!str_starts_with($type,'ascla_')))?:['__ascla_no_public_comments__'];
            return $args;
        });
        add_filter('wp_sitemaps_post_types', static function ($types) {
            foreach (Domain\Catalog::TYPES as $key => $label) { unset($types['ascla_' . $key]); }
            return $types;
        });
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if (str_starts_with($request->get_route(), '/ascla/v1/')) {
                $response->header('Cache-Control', 'private, no-store, max-age=0');
                $response->header('Vary', 'Cookie');
            }
            return $response;
        }, 10, 3);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('ascla seed', static function () {
                $password = getenv('ASCLA_DEMO_PASSWORD');
                if (!$password || strlen($password) < 12) { \WP_CLI::error('Configure ASCLA_DEMO_PASSWORD (12 caracteres mínimo).'); }
                Services\Demo::seed($password);
                \WP_CLI::success('Demo idempotente preparada. Usuario: demo.asociado.');
            });
            \WP_CLI::add_command('ascla migrate', static function () { Database\Installer::activate(false); \WP_CLI::success('Migraciones aplicadas.'); });
            \WP_CLI::add_command('ascla jobs', [Jobs\Queue::class, 'run']);
        }
    }
}
