<?php
/** Owned local proposals; no external AI, Calendar or email transport. */
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/user.php';require __DIR__.'/profile-fixture.php';
use ASCLA\Core\Services\{Settings,MicroEvents,MicroPlanning,MicroLifecycle,Content,EventReminders,ContentSchedule};
use ASCLA\Core\Repositories\Store;
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local WordPress required.'); }
add_filter('pre_wp_mail','__return_true');
$key='ascla_micro_flow108_fixture';$fixture=get_option($key);
if (getenv('ASCLA_FIXTURE_ACTION')==='run_jobs') {
    if (!$fixture) { throw new RuntimeException('Owned fixture required.'); }
    for ($i=0;$i<10 && Store::count('jobs',"user_id=%d AND kind='microevents' AND status='pending'",[$fixture['users']['admin']]);$i++) { ASCLA\Core\Jobs\Queue::run(); }
    echo wp_json_encode(['pending'=>Store::count('jobs',"user_id=%d AND kind='microevents' AND status='pending'",[$fixture['users']['admin']])]);return;
}
if (getenv('ASCLA_FIXTURE_ACTION')==='isolate_batch') {
    if (!$fixture) { throw new RuntimeException('Owned fixture required.'); }
    $batch='ascla_micro_'.MicroPlanning::period();$saved=get_option($batch);
    $fixture['global_batch']=['key'=>$batch,'exists'=>$saved!==false,'value'=>$saved];update_option($key,$fixture,false);delete_option($batch);
    echo wp_json_encode(['isolated'=>true]);return;
}
if (getenv('ASCLA_FIXTURE_ACTION')==='cleanup') {
    if ($fixture) {
        foreach ($fixture['posts'] as $id) {
            EventReminders::cancel($id);ContentSchedule::cancel($id);
            wp_clear_scheduled_hook('ascla_micro_tick',[$id]);wp_clear_scheduled_hook('ascla_event_offers',[$id]);
            Store::delete('registrations',['event_id'=>$id]);Store::delete('audit',['object_id'=>$id]);wp_delete_post($id,true);
        }
        foreach ($fixture['users'] as $id) {
            foreach (['relations','notifications','jobs'] as $table) { Store::delete($table,['user_id'=>$id]); }
            foreach (['deliveries','audit'] as $table) { Store::delete($table,['actor_id'=>$id]); }
            wp_clear_scheduled_hook('ascla_micro_candidate_changed',[$id]);wp_delete_user($id);
        }
        if (!empty($fixture['batch'])) { delete_option($fixture['batch']); }
        if (!empty($fixture['global_batch'])) { $batch=$fixture['global_batch'];if ($batch['exists']) { update_option($batch['key'],$batch['value'],false); }else { delete_option($batch['key']); } }
        update_option('ascla_settings',$fixture['settings'],false);update_option('ascla_group_history',$fixture['history'],false);delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if ($fixture) { throw new RuntimeException('Clean the preceding fixture first.'); }
$prefix='microqa_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);
$fixture=['users'=>[],'posts'=>[],'settings'=>get_option('ascla_settings',[]),'history'=>get_option('ascla_group_history',[])];update_option($key,$fixture,false);$actors=[];
foreach (['admin'=>'administrator','executive'=>'ascla_executive','member'=>'ascla_member','member2'=>'ascla_member','member3'=>'ascla_member','member4'=>'ascla_member','changed1'=>'ascla_member','changed2'=>'ascla_member','changed3'=>'ascla_member','changed4'=>'ascla_member'] as $name=>$role) {
    $login=$prefix.'_'.$name;$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['users'][$name]=$id;update_option($key,$fixture,false);$actors[$name]=['id'=>$id,'login'=>$login];
    update_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>'Prueba','last_name'=>$name,'microevents'=>true,'directory'=>false,'networking'=>false]));
    update_user_meta($id,'_ascla_email_notifications',['connections'=>false,'messages'=>false,'events'=>false,'support'=>false]);
}
wp_set_current_user($fixture['users']['admin']);Settings::save(['ai_mode'=>'mock','ai_provider'=>'mock','micro_min'=>2,'micro_capacity'=>4,'micro_limit'=>1,'micro_priority_hours'=>48]);
$group=array_map(static fn($name)=>$fixture['users'][$name],['member','member2','member3','member4']);sort($group);
$fixture['batch']='ascla_micro_demo-'.substr(hash('sha256',wp_json_encode($group)),0,12).'-'.MicroPlanning::period();update_option($key,$fixture,false);
$result=MicroEvents::create($group);$publish=$result['events'][0]??0;
if (!$publish) { throw new RuntimeException('Expected a real generated proposal.'); }
$fixture['posts'][]=$publish;update_option($key,$fixture,false);$posts=['publish'=>$publish];$baseMeta=get_post_meta($publish,'_ascla',true);
foreach (['pending','scheduled','changed','insufficient'] as $name) {
    $meta=$baseMeta;
    if (in_array($name,['changed','insufficient'],true)) { $meta['invitees']=array_map(static fn($n)=>$fixture['users'][$n],['changed1','changed2','changed3','changed4']); }
    if ($name==='insufficient') { $meta['micro_min']=3; }
    $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'draft','post_author'=>$fixture['users']['admin'],'post_title'=>$prefix.' · '.$name,'post_content'=>'Propuesta local para comprobar el ciclo de microeventos.'],true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['posts'][]=$id;update_option($key,$fixture,false);update_post_meta($id,'_ascla',$meta);MicroLifecycle::history($id,'pending');wp_update_post(['ID'=>$id,'post_status'=>'pending']);$posts[$name]=$id;
    if ($name==='scheduled') {
        Content::moderate($id,'approve','Agenda revisada en prueba local');
        Content::save('event',['title'=>get_the_title($id),'body'=>get_post_field('post_content',$id),'status'=>'scheduled','publish_at'=>gmdate('c',time()+3600)],$id);
    }
}
$profile=get_user_meta($fixture['users']['changed1'],'_ascla_profile',true);$profile['bio']='';update_user_meta($fixture['users']['changed1'],'_ascla_profile',$profile);
$profile=get_user_meta($fixture['users']['changed2'],'_ascla_profile',true);$profile['microevents']=false;update_user_meta($fixture['users']['changed2'],'_ascla_profile',$profile);
$regular=Content::save('event',['title'=>$prefix.' · institucional','body'=>'Evento local para comprobar el formulario de cancelación.','status'=>'publish','meta'=>['chatham'=>false,'start'=>gmdate('c',time()+DAY_IN_SECONDS),'end'=>gmdate('c',time()+DAY_IN_SECONDS+3600),'capacity'=>20,'modality'=>'Virtual']]);
$fixture['posts'][]=$regular['id'];update_option($key,$fixture,false);$posts['regular']=$regular['id'];
echo wp_json_encode(['actors'=>$actors,'password'=>$password,'posts'=>$posts,'group_size'=>count($baseMeta['invitees']),
    'invited_member'=>array_search($baseMeta['invitees'][0],$fixture['users'],true)]);
