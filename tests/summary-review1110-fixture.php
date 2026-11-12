<?php
/** Local synthetic data for summary clarity and editorial review. */
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
if (getenv('ASCLA_FIXTURE_ACTION')==='reset_reviews') {
    require '/var/www/html/wp-load.php';
    if (wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) throw new RuntimeException('Disposable loopback required.');
    $admin=get_user_by('login','prueba.ascla.administrador');wp_set_current_user($admin->ID);
    foreach (get_posts(['post_type'=>'ascla_hub','post_status'=>'any','author'=>$admin->ID,'title'=>'Ideas para fortalecer nuestra comunidad']) as $post) wp_update_post(['ID'=>$post->ID,'post_status'=>'pending']);
    foreach (get_posts(['post_type'=>'ascla_hub','post_status'=>'publish','author'=>$admin->ID,'title'=>'Una conversación abierta para la comunidad']) as $post) {
        foreach (get_comments(['post_id'=>$post->ID,'status'=>'all']) as $comment) {
            if (in_array($comment->comment_content,['Me gustaría compartir una experiencia con la comunidad.','Podemos complementar esta idea con ejemplos de nuestros encuentros.'],true)) wp_set_comment_status($comment->comment_ID,'hold');
        }
    }
    echo '{}';return;
}
ob_start();require __DIR__.'/admin-insights1110-fixture.php';$base=json_decode(ob_get_clean(),true);
if (!is_array($base)||$base['origin']!=='admin-insights1110-local-fixture') throw new RuntimeException('Local fixture required.');
use ASCLA\Core\Services\{Content,Attendance};
use ASCLA\Core\Repositories\Store;
$admin=get_user_by('login','prueba.ascla.administrador');$ana=get_user_by('login','prueba.ascla.ana');$bruno=get_user_by('login','prueba.ascla.bruno');
wp_set_current_user($admin->ID);
$zeroTopic=(int)wp_insert_term('Encuentros sin asistencias registradas · prueba de resumen','ascla_interest')['term_id'];$zero=[];
foreach ([-40,-45,-50,-55,-60] as $index=>$offset) {
    $start=time()+$offset*DAY_IN_SECONDS;
    $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'Encuentro para comprobar registros pendientes '.($index+1),'post_author'=>$admin->ID]);
    update_post_meta($id,'_ascla',['start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600),'chatham'=>false]);wp_set_object_terms($id,[$zeroTopic],'ascla_interest');$zero[]=$id;
    if ($index<2) foreach ([$ana->ID,$bruno->ID,(int)get_user_by('login','prueba.ascla.carla')->ID] as $uid) Store::insert('registrations',['event_id'=>$id,'user_id'=>$uid,'status'=>'accepted','created_at'=>current_time('mysql',true)]);
    if ($index===0) Attendance::complete($id,true);
}
$make=static function(string $type,string $status,string $title,string $body)use($admin):int{
    $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_status'=>$status,'post_title'=>$title,'post_content'=>$body,'post_author'=>$admin->ID]);update_post_meta($id,'_ascla',['chatham'=>true]);return $id;
};
$pendingHub=$make('hub','pending','Ideas para fortalecer nuestra comunidad','Compartimos una propuesta para conectar a los asociados y aprender de sus experiencias. Revisa el contenido completo antes de aprobar su publicación.');
$pendingGallery=$make('gallery','pending','Momentos de colaboración entre asociados','Un álbum con los encuentros y las conversaciones que construyen nuestra comunidad.');
$draftHub=$make('hub','draft','Borrador de conversación sobre gobernanza','Contenido en preparación. Se conserva separado de las solicitudes de aprobación.');
$draftResource=$make('resource','draft','Guía de trabajo en preparación','Este recurso todavía se está editando.');
$published=$make('hub','publish','Una conversación abierta para la comunidad','Publicación sintética con comentarios y un reporte para verificar la revisión de contenido.');
$comments=[];
foreach ([$ana,$bruno] as $i=>$actor) $comments[]=(int)wp_insert_comment(['comment_post_ID'=>$published,'user_id'=>$actor->ID,'comment_author'=>$actor->display_name,'comment_content'=>['Me gustaría compartir una experiencia con la comunidad.','Podemos complementar esta idea con ejemplos de nuestros encuentros.'][$i],'comment_approved'=>'0']);
wp_set_current_user($ana->ID);Content::report($published,'other','Reporte sintético para comprobar la revisión sin eliminar la publicación.');
$report=Store::rows('relations','target_id=%d AND kind=%s',[$published,'report'],'ORDER BY id DESC LIMIT 1')[0];
echo wp_json_encode(['origin'=>'summary-review1110-local-fixture','base'=>$base,'zero_topic'=>$zeroTopic,'zero_events'=>$zero,'pending'=>['hub'=>$pendingHub,'gallery'=>$pendingGallery],'drafts'=>[$draftHub,$draftResource],'published'=>$published,'comments'=>$comments,'report_id'=>(int)$report['id']]);
