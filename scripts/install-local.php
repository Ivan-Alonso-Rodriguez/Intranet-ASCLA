<?php
// Executed with: docker compose run --rm cli wp eval-file /opt/ascla-scripts/install-local.php
if (!is_blog_installed()) {
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $password=getenv('ASCLA_ADMIN_PASSWORD');
    if (!$password || strlen($password)<12) { WP_CLI::error('Set ASCLA_ADMIN_PASSWORD in .env'); }
    wp_install('ASCLA · Comunidad profesional','ascla.admin','admin@example.invalid',false,'',$password);
}
update_option('home','http://localhost:8088'); update_option('siteurl','http://localhost:8088');
update_option('timezone_string','America/Lima'); update_option('blog_public',0);
update_option('permalink_structure','/%postname%/');
require_once ABSPATH.'wp-admin/includes/plugin.php';
$result=activate_plugin('ascla-core/ascla-core.php');
if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
ASCLA\Core\Database\Installer::activate(false);
WP_CLI::success('WordPress local instalado y ASCLA Core activo.');
