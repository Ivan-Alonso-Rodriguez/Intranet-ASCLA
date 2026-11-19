<?php
/** Owned local member and completed conversations, never installed on a remote site. */
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local WordPress required.'); }
$key='ascla_assistant_reset108_fixture';$fixture=get_option($key);
if (getenv('ASCLA_FIXTURE_ACTION')==='cleanup') {
    if ($fixture) {
        foreach (['deliveries','audit'] as $table) { Store::delete($table,['actor_id'=>$fixture['user']]); }
        foreach (['jobs','notifications'] as $table) { Store::delete($table,['user_id'=>$fixture['user']]); }
        wp_delete_user($fixture['user']);delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if ($fixture) { throw new RuntimeException('Clean the preceding fixture first.'); }
$prefix='assistantqa_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);
$user=wp_insert_user(['user_login'=>$prefix,'user_email'=>$prefix.'@example.invalid','user_pass'=>$password,'role'=>'ascla_member']);
if (is_wp_error($user)) { throw new RuntimeException($user->get_error_message()); }
$fixture=['user'=>$user];update_option($key,$fixture,false);
update_user_meta($user,'_ascla_email_notifications',['connections'=>false,'messages'=>false,'events'=>false,'support'=>false]);
$thread='chat_'.$prefix;$otherThread='chat_other_'.$prefix;$jobs=[];
foreach ([$thread,$thread,$otherThread] as $i=>$itemThread) {
    $jobs[]=Store::insert('jobs',['kind'=>'answer','user_id'=>$user,'status'=>'completed','payload'=>wp_json_encode(['thread'=>$itemThread,'question'=>'Pregunta anterior '.$i]),'result'=>wp_json_encode(['answer'=>'Respuesta anterior '.$i.' · '.$prefix,'sources'=>[],'mode'=>'Historial sintético local']),'created_at'=>current_time('mysql',true)]);
}
echo wp_json_encode(['user'=>$user,'login'=>$prefix,'password'=>$password,'thread'=>$thread,'other_thread'=>$otherThread,'jobs'=>$jobs,'marker'=>$prefix]);
