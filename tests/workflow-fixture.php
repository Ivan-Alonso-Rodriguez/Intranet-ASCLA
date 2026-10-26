<?php
/** Local acceptance data only; this file does not run PHPUnit. */
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { exit(1); }
require_once ABSPATH.'wp-admin/includes/user.php';
require_once __DIR__.'/profile-fixture.php';
use ASCLA\Core\Services\{Content,Profiles,MicroLifecycle};
$input=json_decode(stream_get_contents(STDIN),true);
if (($input['action']??'')==='setup') {
    $users=[];$token=bin2hex(random_bytes(6));
    foreach (['administrator','ascla_executive','ascla_member','ascla_member'] as $index=>$role) {
        $login='workflow_'.$token.'_'.$index;$password=wp_generate_password(32);
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
        if (is_wp_error($id)) { exit(2); }
        wp_set_current_user($id);Profiles::save(ascla_test_profile(['first_name'=>'Flujo','last_name'=>'QA '.$index]));
        $users[]=['id'=>$id,'login'=>$login,'password'=>$password,'role'=>$role];
    }
    wp_set_current_user($users[1]['id']);$events=[];
    for ($index=0;$index<2;$index++) {
        $event=Content::save('event',['title'=>'Microevento QA '.$token.' '.$index,'body'=>'Propuesta de aceptación local.','status'=>'draft',
            'meta'=>['start'=>gmdate('c',time()+3*DAY_IN_SECONDS),'end'=>gmdate('c',time()+3*DAY_IN_SECONDS+3600),'capacity'=>4]]);
        $id=$event['id'];$meta=$event['meta']+['micro'=>true,'micro_min'=>2,'priority_hours'=>48,'invitees'=>[$users[2]['id'],$users[3]['id']],'invited'=>false];
        update_post_meta($id,'_ascla',MicroLifecycle::defaults($meta));MicroLifecycle::history($id,'pending');wp_update_post(['ID'=>$id,'post_status'=>'pending']);$events[]=$id;
    }
    update_option('ascla_workflow_'.$token,['settings'=>get_option('ascla_settings',[]),'users'=>array_column($users,'id'),'events'=>$events],false);
    echo wp_json_encode(['token'=>$token,'users'=>$users,'events'=>$events]);
} elseif (($input['action']??'')==='cleanup') {
    $token=preg_replace('/[^a-f0-9]/','',(string)($input['token']??''));$fixture=get_option('ascla_workflow_'.$token);
    if (!$fixture) { exit(3); }
    wp_set_current_user(1);update_option('ascla_settings',$fixture['settings']);
    foreach ($fixture['events'] as $id) { wp_delete_post($id,true); }
    $posts=get_posts(['post_type'=>array_map(static fn($type)=>'ascla_'.$type,array_keys(\ASCLA\Core\Domain\Catalog::TYPES)),
        'post_status'=>['draft','pending','publish','private','ascla_hidden','ascla_rejected','trash'],'author__in'=>$fixture['users'],'numberposts'=>-1]);
    foreach ($posts as $post) { wp_delete_post($post->ID,true); }
    foreach ($fixture['users'] as $id) { wp_delete_user($id,1); }
    delete_option('ascla_workflow_'.$token);\ASCLA\Core\Services\MicroPlanning::schedule();
    echo '{"cleaned":true}';
}
