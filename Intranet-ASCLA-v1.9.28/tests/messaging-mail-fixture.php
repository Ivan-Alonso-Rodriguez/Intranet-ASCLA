<?php
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local') { exit(1); }
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\Profiles;
use ASCLA\Core\Repositories\Store;
$in=json_decode(stream_get_contents(STDIN),true);
$action=$in['action']??'';
if ($action==='setup') {
    $users=[];
    foreach (['Alba','Bruno','Celia','Admin'] as $i=>$name) {
        $login='journey_mailmsg_'.bin2hex(random_bytes(6)); $password=wp_generate_password(32);
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$i===3?'administrator':'ascla_member','display_name'=>$name.' Pruebas']);
        if (is_wp_error($id)) { exit(2); }
        wp_set_current_user($id); Profiles::save(['first_name'=>$name,'last_name'=>'Pruebas','hidden'=>[],'networking'=>true]);
        $users[]=['id'=>$id,'login'=>$login,'password'=>$password,'email'=>$login.'@example.invalid'];
    }
    echo wp_json_encode($users);
} elseif (in_array($action,['history','cleanup'],true)) {
    $ids=array_map('intval',$in['users']??[]);
    if (!$ids) exit(3);
    foreach($ids as $id) { $user=get_userdata($id); if (!$user || !str_starts_with($user->user_login,'journey_mailmsg_')) exit(4); }
    if ($action==='history') {
        $conversation=(int)$in['conversation'];
        foreach($ids as $id) if (!Store::count('participants','conversation_id=%d AND user_id=%d',[$conversation,$id])) exit(5);
        foreach(range(1,130) as $i) Store::insert('messages',['conversation_id'=>$conversation,'sender_id'=>$ids[0],'body'=>'Historial de prueba '.$i,'created_at'=>current_time('mysql',true)]);
    } else {
        $conversations=[];
        foreach ($ids as $id) foreach(Store::rows('participants','user_id=%d',[$id]) as $p) $conversations[]=(int)$p['conversation_id'];
        foreach(array_unique($conversations) as $cid) { foreach(['messages','participants'] as $t) Store::delete($t,['conversation_id'=>$cid]); Store::delete('conversations',['id'=>$cid]); }
        foreach($ids as $id) { foreach(['notifications','relations','media'] as $t) Store::delete($t,['user_id'=>$id]); wp_delete_user($id); }
    }
    echo '{}';
}
