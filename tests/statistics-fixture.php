<?php
require '/var/www/html/wp-load.php';
if(PHP_SAPI!=='cli' || wp_get_environment_type()!=='local')exit(1);
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\{Content,Profiles,Attendance,Reports};
use ASCLA\Core\Repositories\Store;
$in=json_decode(stream_get_contents(STDIN),true);
if(($in['action']??'')==='setup') {
    $users=[];
    foreach(['administrator','ascla_executive','ascla_moderator','ascla_member'] as $role) {
        $login='journey_stats_'.bin2hex(random_bytes(6));$password=wp_generate_password(32);$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role,'display_name'=>'Estadísticas '.$role]);
        if(is_wp_error($id))exit(2);wp_set_current_user($id);Profiles::save(['first_name'=>'Estadísticas','last_name'=>$role]);
        if($role==='ascla_executive')update_user_meta($id,'locale','en_US');
        $users[]=['id'=>$id,'login'=>$login,'email'=>$login.'@example.invalid','password'=>$password,'role'=>$role];
    }
    wp_set_current_user($users[0]['id']);
    update_option('ascla_stats_fixture_'.$users[0]['id'],get_option('ascla_settings'),false);
    ASCLA\Core\Services\Settings::save(['ai_provider'=>'mock']);
    $t=wp_insert_term('Prueba estadísticas '.bin2hex(random_bytes(4)),'ascla_interest');$topic=(int)$t['term_id'];
    $events=[];foreach([-3,-95] as $offset) {
        $start=time()+$offset*DAY_IN_SECONDS;$id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'ChatGPT e IA para directorios · prueba 1.10.1','post_content'=>'Taller sobre inteligencia artificial.','post_author'=>$users[0]['id']]);
        update_post_meta($id,'_ascla',['start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600),'chatham'=>false]);wp_set_object_terms($id,[$topic],'ascla_interest');$events[]=$id;
        foreach([2,3] as $index)Store::insert('registrations',['event_id'=>$id,'user_id'=>$users[$index]['id'],'status'=>'accepted','created_at'=>current_time('mysql',true)]);
        Attendance::save($id,['user_id'=>$users[3]['id'],'status'=>'present','minutes'=>20]);
    }
    $hub=Content::save('hub',['title'=>'Publicación con reporte · prueba 1.10.1','body'=>'Contenido que se conservará tras eliminar el reporte.','status'=>'publish']);
    wp_set_current_user($users[3]['id']);Content::report($hub['id'],'other','Reporte de prueba');$report=Store::rows('relations','target_id=%d AND kind=%s',[$hub['id'],'report'],'LIMIT 1')[0];
    $open=Content::save('contact',['title'=>'Solicitud pendiente · prueba 1.10.1','body'=>'Solicitud de prueba']);$closed=Content::save('contact',['title'=>'Solicitud resuelta · prueba 1.10.1','body'=>'Solicitud de prueba']);
    wp_set_current_user($users[0]['id']);ASCLA\Core\Services\Administration::contactStatus($closed['id'],'closed');
    echo wp_json_encode(['users'=>$users,'events'=>$events,'topic'=>$topic,'topicName'=>get_term($topic,'ascla_interest')->name,'eventStart'=>get_post_meta($events[0],'_ascla_start',true),'hub'=>$hub['id'],'report'=>(int)$report['id'],'open'=>$open['id'],'closed'=>$closed['id']]);
} elseif(($in['action']??'')==='cleanup') {
    $ids=array_map('intval',$in['users']??[]);if(!$ids)exit(3);
    foreach($ids as $id){$u=get_userdata($id);if(!$u || !str_starts_with($u->user_login,'journey_stats_'))exit(4);}
    $backup='ascla_stats_fixture_'.$ids[0];$settings=get_option($backup);if(is_array($settings))update_option('ascla_settings',$settings,false);delete_option($backup);
    foreach($ids as $id) {
        foreach(get_posts(['author'=>$id,'post_type'=>array_map(static fn($type)=>'ascla_'.$type,array_keys(ASCLA\Core\Domain\Catalog::TYPES)),'post_status'=>array_keys(get_post_stati()),'numberposts'=>-1]) as $p){Store::delete('registrations',['event_id'=>$p->ID]);wp_delete_post($p->ID,true);}
        foreach(Store::rows('imports','actor_id=%d',[$id],'ORDER BY id') as $i){Store::delete('import_rows',['import_id'=>(int)$i['id']]);Store::delete('imports',['id'=>(int)$i['id']]);}
        foreach(['relations','registrations','notifications','jobs','attendance'] as $t)Store::delete($t,['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
    }
    $term=get_term((int)($in['topic']??0),'ascla_interest');if($term && !is_wp_error($term) && str_starts_with($term->name,'Prueba estadísticas '))wp_delete_term($term->term_id,'ascla_interest');
    echo '{}';
}
