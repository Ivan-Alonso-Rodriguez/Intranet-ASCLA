<?php
/** Owned local content for retiring the optional conversation-follow action. */
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local WordPress required.'); }
$key='ascla_follow_removal108_fixture';$fixture=get_option($key);
if (getenv('ASCLA_FIXTURE_ACTION')==='cleanup') {
    if ($fixture) {
        foreach ($fixture['posts'] as $id) { wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]); }
        foreach ($fixture['users'] as $id) {
            foreach (['relations','notifications','jobs'] as $table) { Store::delete($table,['user_id'=>$id]); }
            foreach (['deliveries','audit'] as $table) { Store::delete($table,['actor_id'=>$id]); }
            wp_delete_user($id);
        }
        delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if ($fixture) { throw new RuntimeException('Clean the preceding fixture first.'); }
$prefix='followqa_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);
$fixture=['users'=>[],'posts'=>[]];update_option($key,$fixture,false);
foreach (['administrator','ascla_member'] as $role) {
    $login=$prefix.'_'.$role;$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['users'][$role]=$id;update_option($key,$fixture,false);
    update_user_meta($id,'_ascla_email_notifications',['connections'=>false,'messages'=>false,'events'=>false,'support'=>false]);
}
$posts=[];wp_set_current_user($fixture['users']['administrator']);
foreach (['hub','forum','topic','resource','gallery','event','ally'] as $type) {
    $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_status'=>'publish','post_author'=>$fixture['users']['administrator'],'post_title'=>$prefix.' · '.$type,'post_content'=>'Contenido local para verificar comentarios y Me gusta sin seguimiento.'],true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['posts'][]=$id;update_option($key,$fixture,false);
    $meta=['chatham'=>false];
    if ($type==='event') { $meta+=['start'=>gmdate('c',time()+DAY_IN_SECONDS),'end'=>gmdate('c',time()+DAY_IN_SECONDS+3600),'capacity'=>20,'modality'=>'Virtual']; }
    update_post_meta($id,'_ascla',$meta);
    Store::insert('relations',['user_id'=>$fixture['users']['ascla_member'],'target_id'=>$id,'kind'=>'follow','created_at'=>current_time('mysql',true)]);
    $posts[$type]=['id'=>$id,'title'=>get_the_title($id),'page'=>ASCLA\Core\Services\Content::page($type)];
}
echo wp_json_encode(['login'=>$prefix.'_ascla_member','password'=>$password,'posts'=>$posts]);
