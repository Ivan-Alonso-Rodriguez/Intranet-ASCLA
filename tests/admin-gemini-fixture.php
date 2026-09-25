<?php
require '/var/www/html/wp-load.php';
if(PHP_SAPI!=='cli'||wp_get_environment_type()!=='local')exit(1);
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\Profiles;
use ASCLA\Core\Repositories\Store;
$in=json_decode(stream_get_contents(STDIN),true);
if(($in['action']??'')==='setup') {
    $token=bin2hex(random_bytes(12));$option='ascla_test_admin_'.$token;
    add_option($option,['settings'=>get_option('ascla_settings',false),'key'=>get_option('ascla_secret_ai_key',false),'openai_key'=>get_option('ascla_secret_openai_key',false),'deepseek_key'=>get_option('ascla_secret_deepseek_key',false)],'','no');
    $users=[];
    foreach(['administrator','ascla_moderator','ascla_member'] as $i=>$role) {
        $login='journey_admin_'.bin2hex(random_bytes(6));$pass=wp_generate_password(32);$name=['Ana Administración','Marcos Moderación','Lucía Asociada'][$i];
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$pass,'role'=>$role,'display_name'=>$name]);wp_set_current_user($id);
        Profiles::save(['first_name'=>explode(' ',$name)[0],'last_name'=>explode(' ',$name)[1],'networking'=>true,'hidden'=>[]]);
        $users[]=['id'=>$id,'login'=>$login,'password'=>$pass,'name'=>$name];
    }
    delete_option('ascla_secret_ai_key');delete_option('ascla_secret_openai_key');delete_option('ascla_secret_deepseek_key');$settings=ASCLA\Core\Services\Settings::get();$settings['ai_mode']='mock';$settings['ai_provider']='mock';$settings['ai_model']='';$settings['openai_model']='gpt-5.6-luna';$settings['deepseek_model']='deepseek-flash';update_option('ascla_settings',$settings,false);
    echo wp_json_encode(['token'=>$token,'users'=>$users]);
} elseif(($in['action']??'')==='cleanup') {
    $token=(string)($in['token']??'');if(!preg_match('/^[a-f0-9]{24}$/D',$token))exit(2);$option='ascla_test_admin_'.$token;$old=get_option($option,false);if(!$old)exit(3);
    $ids=array_map('intval',(array)($in['users']??[]));if(count($ids)!==3)exit(4);
    foreach($ids as $id){$user=get_userdata($id);if(!$user||!str_starts_with($user->user_login,'journey_admin_'))exit(5);}
    foreach($ids as $id) {
        foreach(get_posts(['post_type'=>array_map(static fn($t)=>'ascla_'.$t,array_keys(ASCLA\Core\Domain\Catalog::TYPES)),'post_status'=>['private','draft','publish','pending','ascla_hidden','ascla_rejected','trash'],'numberposts'=>-1,'author'=>$id]) as $post)wp_delete_post($post->ID,true);
        foreach(['notifications','jobs','relations','media'] as $t)Store::delete($t,['user_id'=>$id]);wp_delete_user($id);
    }
    foreach(['settings'=>'ascla_settings','key'=>'ascla_secret_ai_key','openai_key'=>'ascla_secret_openai_key','deepseek_key'=>'ascla_secret_deepseek_key'] as $key=>$name){if($old[$key]===false)delete_option($name);else update_option($name,$old[$key],false);}
    delete_option($option);echo '{}';
}
