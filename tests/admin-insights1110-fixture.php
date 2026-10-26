<?php
/** Synthetic dashboard records; only for the disposable loopback WordPress. */
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) throw new RuntimeException('Disposable loopback required.');
use ASCLA\Core\Services\{Settings,Attendance,MicroLifecycle,Statistics};
use ASCLA\Core\Repositories\Store;
$admin=get_user_by('login','prueba.ascla.administrador');
$members=array_map(static fn($n)=>(int)get_user_by('login','prueba.ascla.'.$n)->ID,['ana','bruno','carla']);
wp_set_current_user($admin->ID);Settings::save(['ai_mode'=>'mock','ai_provider'=>'mock','micro_min'=>2,'micro_capacity'=>4]);
if (getenv('ASCLA_FIXTURE_ACTION')==='repair_finished') {
    foreach (get_posts(['post_type'=>'ascla_event','post_status'=>'publish','author'=>$admin->ID,'title'=>'Experiencias compartidas en la gestión de riesgos']) as $post) MicroLifecycle::history($post->ID,'finished','Encuentro sintético finalizado.');
    echo '{}';return;
}
$topic=(int)wp_insert_term('Gobierno corporativo y gestión de riesgos · panel de prueba','ascla_interest')['term_id'];
$other=(int)wp_insert_term('Sostenibilidad y colaboración · panel de prueba','ascla_interest')['term_id'];
$empty=(int)wp_insert_term('Sin eventos · panel de prueba','ascla_interest')['term_id'];
$events=[];
foreach ([-3,-8,-15,-100,-115] as $i=>$offset) {
    $start=time()+$offset*DAY_IN_SECONDS;
    $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>['Gobierno corporativo en la práctica','Compartir experiencias para gestionar riesgos','Sostenibilidad: de la estrategia a la acción','Conversación del período anterior','Aprendizajes de nuestra comunidad'][$i],'post_author'=>$admin->ID]);
    update_post_meta($id,'_ascla',['start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600),'chatham'=>false,'capacity'=>30,'modality'=>'Virtual']);
    wp_set_object_terms($id,[$i===2?$other:$topic],'ascla_interest');$events[]=$id;
    foreach ($members as $uid) Store::insert('registrations',['event_id'=>$id,'user_id'=>$uid,'status'=>'accepted','created_at'=>current_time('mysql',true)]);
    $present=match($i){0=>[$members[0],$members[1]],1=>[$members[0],$members[2]],2,3=>[$members[0]],default=>[]};
    foreach ($present as $uid) Attendance::save($id,['user_id'=>$uid,'status'=>'present','minutes'=>50]);
    if ($i!==1) Attendance::complete($id,true);
}
$micro=[];
foreach (['pending','published','cancelled','finished'] as $i=>$state) {
    $start=time()+($state==='finished'?-400:14)*DAY_IN_SECONDS;
    $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'draft','post_title'=>['Riesgos e innovación: una conversación entre asociados','Conexiones que impulsan la sostenibilidad','Gobernanza e inteligencia artificial: nuevas perspectivas','Experiencias compartidas en la gestión de riesgos'][$i],'post_author'=>$admin->ID]);
    $meta=MicroLifecycle::defaults(['micro'=>true,'invitees'=>$members,'start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600),'capacity'=>4,'chatham'=>true,'modality'=>'Virtual','location'=>'Sala virtual','agenda'=>'Presentaciones y conversación guiada.','invited'=>false,'micro_approved'=>$state==='published'||$state==='finished']);
    if ($state==='cancelled') {$meta['cancelled']=true;$meta['cancellation_reason']='Se revisará la disponibilidad del grupo.';}
    update_post_meta($id,'_ascla',$meta);MicroLifecycle::history($id,$state,'Estado sintético del encuentro para comprobar su presentación.');
    wp_update_post(['ID'=>$id,'post_status'=>$state==='pending'?'pending':($state==='cancelled'?'ascla_hidden':'publish')]);MicroLifecycle::history($id,$state,'Estado sintético para la presentación.');$micro[$state]=$id;
}
echo wp_json_encode(['origin'=>'admin-insights1110-local-fixture','events'=>$events,'micro'=>$micro,'topics'=>['main'=>$topic,'other'=>$other,'empty'=>$empty],'expected_summary'=>Statistics::dashboard([])['summary']]);
