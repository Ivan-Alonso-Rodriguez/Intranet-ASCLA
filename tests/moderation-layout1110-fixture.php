<?php
/** Reset only the named synthetic moderation records in the disposable loopback site. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type() !== 'local' || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Disposable loopback required.');
}
$admin = get_user_by('login', 'prueba.ascla.administrador');
$ana = get_user_by('login', 'prueba.ascla.ana');
if (!$admin || !$ana) { throw new RuntimeException('Synthetic accounts required.'); }
wp_set_current_user($admin->ID);
foreach ([
    'ascla_hub' => 'Ideas para fortalecer nuestra comunidad',
    'ascla_gallery' => 'Momentos de colaboración entre asociados',
] as $type => $title) {
    foreach (get_posts(['post_type' => $type, 'post_status' => 'any', 'author' => $admin->ID, 'title' => $title]) as $post) {
        wp_update_post(['ID' => $post->ID, 'post_status' => 'pending']);
    }
}
foreach (get_posts(['post_type' => 'ascla_hub', 'post_status' => 'publish', 'author' => $admin->ID, 'title' => 'Una conversación abierta para la comunidad']) as $post) {
    foreach (get_comments(['post_id' => $post->ID, 'status' => 'all']) as $comment) {
        if (in_array($comment->comment_content, ['Me gustaría compartir una experiencia con la comunidad.', 'Podemos complementar esta idea con ejemplos de nuestros encuentros.'], true)) {
            wp_set_comment_status($comment->comment_ID, 'hold');
        }
    }
    foreach (\ASCLA\Core\Repositories\Store::rows('relations', 'user_id=%d AND target_id=%d AND kind=%s', [$ana->ID, $post->ID, 'report']) as $report) {
        if ($report['detail'] === 'Reporte sintético para comprobar la revisión sin eliminar la publicación.') {
            \ASCLA\Core\Repositories\Store::update('relations', ['reviewed_at' => null, 'reviewed_by' => 0], ['id' => $report['id']]);
        }
    }
}
echo "Synthetic moderation records reset.\n";
