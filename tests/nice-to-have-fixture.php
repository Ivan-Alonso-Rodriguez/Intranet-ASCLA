<?php
/** Synthetic load for RNF-010; only an explicitly disposable local installation. */
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { exit(1); }
require_once __DIR__.'/profile-fixture.php';
use ASCLA\Core\Repositories\{StatisticsCache,Store};
use ASCLA\Core\Services\{Profiles,Statistics};

$input=json_decode(stream_get_contents(STDIN),true)?:[];
$admin=get_user_by('login','ascla.admin');
if (!$admin || !$admin->has_cap('ascla_manage')) { exit(2); }
wp_set_current_user($admin->ID);
$action=$input['action']??'';
if ($action==='invalidate') { StatisticsCache::invalidate();echo '{}';return; }
if ($action==='measure') {
    $samples=[];
    for ($n=0;$n<6;$n++) {
        if ($n===0) { StatisticsCache::invalidate(); }
        $queries=$wpdb->num_queries;$start=hrtime(true);$data=Statistics::dashboard([]);
        $samples[]=['kind'=>$n===0?'cold':'warm','ms'=>round((hrtime(true)-$start)/1e6,3),'queries'=>$wpdb->num_queries-$queries];
    }
    echo wp_json_encode(['samples'=>$samples,'summary'=>$data['summary'],'community'=>$data['community'],'dataset'=>get_option('ascla_nice_load')]);return;
}
if ($action!=='setup' || get_option('ascla_nice_load')) { exit(3); }
$stamp=current_time('mysql',true);$hash=wp_hash_password(wp_generate_password(40));$users=[];
// No reusable plaintext passwords exist for the synthetic load accounts.
for ($n=0;$n<250;$n++) {
    $login='nice_load_'.bin2hex(random_bytes(6));
    $wpdb->insert($wpdb->users,['user_login'=>$login,'user_pass'=>$hash,'user_nicename'=>$login,'user_email'=>$login.'@example.invalid','display_name'=>'Asociado de prueba '.$n,'user_registered'=>$stamp]);
    $id=(int)$wpdb->insert_id;$users[]=$id;
    add_user_meta($id,$wpdb->prefix.'capabilities',['ascla_member'=>true]);
    add_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>'Asociado','last_name'=>'Prueba '.$n]));
}
$topic=(int)Profiles::catalogs()['interest'][0]['id'];$events=[];$registrations=0;$attendances=0;
for ($n=0;$n<100;$n++) {
    $start=time()-(2+$n%60)*DAY_IN_SECONDS;
    $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'Encuentro de prueba '.($n+1),'post_author'=>$admin->ID]);
    $events[]=$id;
    update_post_meta($id,'_ascla_start',gmdate('c',$start));update_post_meta($id,'_ascla_end',gmdate('c',$start+3600));
    update_post_meta($id,'_ascla_attendance_complete',1);wp_set_object_terms($id,[$topic],'ascla_interest');
    for ($p=0;$p<50;$p++) {
        $uid=$users[($n*17+$p)%250];
        $wpdb->insert(Store::table('registrations'),['event_id'=>$id,'user_id'=>$uid,'status'=>'accepted','created_at'=>$stamp]);++$registrations;
        if ($p<30) {
            $wpdb->insert(Store::table('attendance'),['event_id'=>$id,'user_id'=>$uid,'status'=>'present','minutes'=>45,'source'=>'manual','recorded_by'=>$admin->ID,'updated_at'=>$stamp]);++$attendances;
        }
    }
}
for ($n=0;$n<200;$n++) {
    $wpdb->insert(Store::table('relations'),['user_id'=>$users[$n],'target_id'=>$users[$n+1],'kind'=>'connected','created_at'=>$stamp]);
    $wpdb->insert(Store::table('audit'),['actor_id'=>$users[$n],'action'=>'connection_accepted','object_id'=>$wpdb->insert_id,'detail'=>'{}','created_at'=>$stamp]);
}
for ($n=0;$n<40;$n++) {
    $id=wp_insert_post(['post_type'=>$n%2?'ascla_hub':'ascla_topic','post_status'=>'publish','post_title'=>'Aporte comunitario de prueba '.$n,'post_author'=>$users[$n]]);
    for ($p=0;$p<5;$p++) { wp_insert_comment(['comment_post_ID'=>$id,'user_id'=>$users[$n+1],'comment_content'=>'Comentario sintético para medir estadísticas.','comment_approved'=>1]); }
}
$dataset=['synthetic_users'=>250,'finished_events'=>100,'registrations'=>$registrations,'attendances'=>$attendances,'connections'=>200,'publications'=>40,'comments'=>200];
update_option('ascla_nice_load',$dataset,false);StatisticsCache::invalidate();echo wp_json_encode($dataset);
