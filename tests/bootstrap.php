<?php
$testUrl=parse_url(getenv('ASCLA_SITE_URL')?:'http://localhost:8088');
$_SERVER['HTTP_HOST']=($testUrl['host']??'localhost').(isset($testUrl['port'])?':'.$testUrl['port']:'');
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['SERVER_NAME']=$testUrl['host']??'localhost';
require getenv('ASCLA_WP_ROOT')?:'/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local') { throw new RuntimeException('Tests only run against a local disposable WordPress.'); }
require_once ABSPATH.'wp-admin/includes/user.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
ASCLA\Core\Database\Installer::activate(false);
