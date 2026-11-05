<?php
/** Synthetic accounts and content for the draft/forum/gallery browser regression. */
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
require_once __DIR__.'/profile-fixture.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || parse_url(home_url(),PHP_URL_HOST)!=='ascla-ui573.test') {
    throw new RuntimeException('Only the isolated UI regression environment is accepted.');
}
update_option('WPLANG','es_ES');
$prefix='ui573_'.bin2hex(random_bytes(4));$actors=[];
foreach (['executive'=>'ascla_executive','admin'=>'administrator','member'=>'ascla_member'] as $key=>$role) {
    $login=$prefix.'_'.$key;$password=bin2hex(random_bytes(24));
    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
    if (is_wp_error($id)) throw new RuntimeException('Synthetic user could not be created.');
    wp_set_current_user($id);
    ASCLA\Core\Services\Profiles::save(ascla_test_profile(['first_name'=>'Prueba','last_name'=>ucfirst($key),'company'=>'QA ASCLA']));
    update_user_meta($id,'locale','es_ES');
    $actors[$key]=['id'=>$id,'login'=>$login,'password'=>$password];
}
wp_set_current_user($actors['executive']['id']);
$forum=ASCLA\Core\Services\Content::save('forum',['title'=>$prefix.' Foro visible','body'=>'Un espacio para compartir experiencias de gobierno corporativo.','status'=>'publish']);
$media=ASCLA\Core\Repositories\Store::insert('media',['user_id'=>$actors['executive']['id'],'post_id'=>0,'name'=>'gallery.png','mime'=>'image/png','bytes'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jlecAAAAASUVORK5CYII='),'created_at'=>current_time('mysql',true)]);
$gallery=ASCLA\Core\Services\Content::save('gallery',['title'=>$prefix.' Galería visible','body'=>'Galería de prueba para abrir desde su portada.','status'=>'publish','meta'=>['media_ids'=>[$media]]]);
echo wp_json_encode(['base'=>home_url(),'prefix'=>$prefix,'actors'=>$actors,'forum'=>$forum['id'],'gallery'=>$gallery['id']],JSON_UNESCAPED_SLASHES).PHP_EOL;
