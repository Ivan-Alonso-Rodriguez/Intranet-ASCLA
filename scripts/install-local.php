<?php
// Configura una instalacion de WordPress ya creada y activa ASCLA Core.
// Ejecutar mediante scripts/init-wordpress.sh para que tambien instale WordPress
// cuando la base de datos aun esta vacia.

if (!is_blog_installed()) {
    WP_CLI::error('WordPress aun no esta instalado. Ejecute scripts/init-wordpress.sh.');
}

$port = getenv('ASCLA_HTTP_PORT') ?: '8088';
$siteUrl = trim((string) getenv('ASCLA_SITE_URL'));
if ($siteUrl === '') {
    $siteUrl = 'http://localhost:' . $port;
}
$siteUrl = rtrim($siteUrl, '/');

update_option('home', $siteUrl);
update_option('siteurl', $siteUrl);
update_option('timezone_string', 'America/Lima');
update_option('blog_public', 0);
update_option('permalink_structure', '/%postname%/');

require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (!is_plugin_active('ascla-core/ascla-core.php')) {
    $result = activate_plugin('ascla-core/ascla-core.php');
    if (is_wp_error($result)) {
        WP_CLI::error($result->get_error_message());
    }
}

if (!class_exists('ASCLA\\Core\\Database\\Installer')) {
    WP_CLI::error('ASCLA Core no pudo cargarse correctamente.');
}

ASCLA\Core\Database\Installer::activate(false);

WP_CLI::success('WordPress configurado y ASCLA Core activo en ' . $siteUrl . '.');
