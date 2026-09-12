<?php
/**
 * Plugin Name: ASCLA Core
 * Description: Intranet profesional ASCLA: comunidad, conocimiento y networking.
 * Version: 1.7.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: ASCLA
 * License: GPL-2.0-or-later
 * Text Domain: ascla-core
 */
defined('ABSPATH') || exit;
define('ASCLA_VERSION', '1.8.0');
define('ASCLA_PATH', plugin_dir_path(__FILE__));
define('ASCLA_URL', plugin_dir_url(__FILE__));
spl_autoload_register(static function (string $class): void {
    $prefix = 'ASCLA\\Core\\';
    if (str_starts_with($class, $prefix)) {
        $file = ASCLA_PATH . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
register_activation_hook(__FILE__, [ASCLA\Core\Database\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('ascla_jobs');
    wp_clear_scheduled_hook('ascla_jobs_continue');
    wp_clear_scheduled_hook('ascla_monthly');
    wp_clear_scheduled_hook('ascla_discovery');
    flush_rewrite_rules();
});
add_action('plugins_loaded', [ASCLA\Core\Plugin::class, 'boot']);
