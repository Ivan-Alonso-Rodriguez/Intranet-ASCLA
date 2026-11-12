<?php
/** Owned local discussions for comment-counter regression; never used on remote WordPress. */
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local WordPress required.'); }
$key='ascla_comment_count108_fixture';$fixture=get_option($key);
if (getenv('ASCLA_FIXTURE_ACTION')==='cleanup') {
    if ($fixture) {
        foreach ($fixture['posts'] as $id) { wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]); }
        foreach ($fixture['users'] as $id) {
            foreach (['deliveries','audit'] as $table) { Store::delete($table,['actor_id'=>$id]); }
            foreach (['notifications','jobs'] as $table) { Store::delete($table,['user_id'=>$id]); }
            wp_delete_user($id);
        }
        $settings=(array)get_option('ascla_settings',[]);
        if ($fixture['had_moderation']) { $settings['moderate_comments']=$fixture['moderation']; } else { unset($settings['moderate_comments']); }
        update_option('ascla_settings',$settings,false);delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if ($fixture) { throw new RuntimeException('Clean the preceding fixture first.'); }
$settings=(array)get_option('ascla_settings',[]);$prefix='counterqa_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);
$fixture=['users'=>[],'posts'=>[],'had_moderation'=>array_key_exists('moderate_comments',$settings),'moderation'=>$settings['moderate_comments']??null];update_option($key,$fixture,false);
$settings['moderate_comments']=true;update_option('ascla_settings',$settings,false);$actors=[];
foreach (['administrator','ascla_member'] as $role) {
    $login=$prefix.'_'.$role;$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['users'][$role]=$id;update_option($key,$fixture,false);$actors[$role]=['login'=>$login,'password'=>$password];
    update_user_meta($id,'_ascla_email_notifications',['connections'=>false,'messages'=>false,'events'=>false,'support'=>false]);
}
$posts=[];
foreach ([['main','topic',0],['other','topic',2],['large','topic',101],['hub','hub',0]] as [$name,$type,$count]) {
    $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_status'=>'publish','post_author'=>$fixture['users']['administrator'],'post_title'=>$prefix.' · '.$name,'post_content'=>'Conversación local para comprobar el contador.'],true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['posts'][]=$id;update_option($key,$fixture,false);update_post_meta($id,'_ascla',['chatham'=>false]);
    wp_defer_comment_counting(true);
    try { for ($i=0;$i<$count;$i++) { wp_insert_comment(['comment_post_ID'=>$id,'user_id'=>$fixture['users']['administrator'],'comment_author'=>'Equipo de prueba','comment_content'=>'Aporte de prueba '.($i+1),'comment_approved'=>1]); } }
    finally { wp_defer_comment_counting(false); }
    $posts[$name]=['id'=>$id,'title'=>get_the_title($id),'count'=>$count];
}
echo wp_json_encode(['prefix'=>$prefix,'actors'=>$actors,'posts'=>$posts]);
