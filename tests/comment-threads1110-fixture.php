<?php
/** Synthetic conversations; CLI only, disposable loopback WordPress. */
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Disposable loopback required.'); }
use ASCLA\Core\Services\{Content,Settings};
$admin=get_user_by('login','prueba.ascla.administrador');
$ana=get_user_by('login','prueba.ascla.ana');$bruno=get_user_by('login','prueba.ascla.bruno');$carla=get_user_by('login','prueba.ascla.carla');
if (!$admin||!$ana||!$bruno||!$carla) { throw new RuntimeException('Base fixtures required.'); }
wp_set_current_user($admin->ID);Settings::save(['moderate_comments'=>false]);
$profile=(array)get_user_meta($carla->ID,'_ascla_profile',true);$profile['hidden']=['first_name'];update_user_meta($carla->ID,'_ascla_profile',$profile);
$posts=[];
$make=static function(int $post,int $user,string $body,int $parent=0,string $status='1'): int {
    return (int)wp_insert_comment(['comment_post_ID'=>$post,'user_id'=>$user,'comment_author'=>get_userdata($user)->display_name,'comment_content'=>$body,'comment_parent'=>$parent,'comment_approved'=>$status,'comment_date'=>'2026-10-24 09:00:00','comment_date_gmt'=>'2026-10-24 14:00:00']);
};
foreach (['hub','topic','forum','gallery','resource','event'] as $type) {
    foreach (get_posts(['post_type'=>'ascla_'.$type,'post_status'=>'any','author'=>$admin->ID,'title'=>'Conversaciones de nuestra comunidad · '.$type,'numberposts'=>-1]) as $old) wp_delete_post($old->ID,true);
    $meta=$type==='event'?['start'=>'2026-11-15T09:00:00-05:00','end'=>'2026-11-15T10:00:00-05:00','capacity'=>100,'location'=>'Comunidad ASCLA','modality'=>'Virtual']:[];
    $post=Content::save($type,['title'=>'Conversaciones de nuestra comunidad · '.$type,'body'=>'Un espacio para compartir experiencias, aprender y conectar.','status'=>'publish','meta'=>$meta]);
    $id=(int)$post['id'];$root=$make($id,$bruno->ID,'¿Qué experiencia les gustaría compartir con la comunidad?');$replies=[];$parent=$root;
    foreach (range(1,13) as $n) {
        $replies[]=$parent=$make($id,$n===2?$carla->ID:($n%2?$ana->ID:$bruno->ID),'Respuesta de la conversación '.$n,$parent);
    }
    $single=$make($id,$ana->ID,'Otro tema para conversar.');$make($id,$bruno->ID,'Gracias por compartir esta idea.',$single);
    foreach (range(1,10) as $n) $make($id,$ana->ID,'Aporte principal adicional '.$n);
    $pending=$make($id,$ana->ID,'Respuesta propia pendiente de revisión.',$root,'0');
    $posts[$type]=['id'=>$id,'root'=>$root,'replies'=>$replies,'single'=>$single,'pending'=>$pending];
}
$unavailable=Content::save('hub',['title'=>'Conversación con un comentario retirado','body'=>'Una respuesta conservada con destinatario no disponible.','status'=>'publish']);
$removed=$make($unavailable['id'],$carla->ID,'Contenido retirado que no debe mostrarse.',0,'trash');
$orphan=$make($unavailable['id'],$bruno->ID,'La conversación puede continuar.',$removed);
echo wp_json_encode(['origin'=>'comment-threads1110-local-fixture','posts'=>$posts,'hidden_author_id'=>$carla->ID,'unavailable'=>['post_id'=>$unavailable['id'],'comment_id'=>$orphan,'removed_id'=>$removed]]);
