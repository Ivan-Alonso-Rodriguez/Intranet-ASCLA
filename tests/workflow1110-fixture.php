<?php
/** Disposable actors and publications for profile, discussion and review acceptance. */
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require '/var/www/html/wp-load.php';require __DIR__.'/profile-fixture.php';
if (wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1'
    || !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Disposable loopback required.'); }
use ASCLA\Core\Services\{Settings,Content,MicroLifecycle};
add_filter('pre_wp_mail','__return_true');
$actors=[];
foreach(['member'=>'ascla_member','executive'=>'ascla_executive','candidate1'=>'ascla_member','candidate2'=>'ascla_member','candidate3'=>'ascla_member','candidate4'=>'ascla_member','replacement'=>'ascla_member'] as $key=>$role) {
    $login='workflow_'.bin2hex(random_bytes(5));$password=wp_generate_password(32,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','display_name'=>ucfirst($key).' de prueba','role'=>$role]);
    if (is_wp_error($id)) { throw new RuntimeException('Synthetic actor failed'); }
    update_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>ucfirst($key),'country'=>'Perú','microevents'=>true,'networking'=>true,'directory'=>true]));
    $actors[$key]=['id'=>$id,'login'=>$login,'password'=>$password];
}
$admin=get_user_by('login','ascla.admin');wp_set_current_user($admin->ID);
Settings::save(['ai_provider'=>'mock','micro_min'=>2,'micro_capacity'=>4]);
$post=Content::save('hub',['title'=>'Conversaciones que nos acercan','body'=>'Una publicación para comprobar la conversación con muchos comentarios.','status'=>'publish']);
for($n=1;$n<=32;$n++) {
    wp_insert_comment(['comment_post_ID'=>$post['id'],'user_id'=>$actors['member']['id'],'comment_author'=>'Persona de prueba','comment_content'=>'Comentario de prueba número '.$n,'comment_approved'=>1]);
}
wp_update_comment_count_now($post['id']);
$id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'draft','post_title'=>'Gobernanza y nuevas conexiones','post_content'=>'Una conversación con las personas seleccionadas por administración.','post_author'=>$admin->ID]);
$ids=array_map(static fn($key)=>$actors[$key]['id'],['candidate1','candidate2','candidate3','candidate4']);
$meta=MicroLifecycle::defaults(['micro'=>true,'invitees'=>$ids,'start'=>gmdate('c',time()+14*DAY_IN_SECONDS),'end'=>gmdate('c',time()+14*DAY_IN_SECONDS+3600),'capacity'=>4,'chatham'=>true,'modality'=>'Virtual','location'=>'Sala virtual','invited'=>false,'agenda'=>'Presentaciones y diálogo']);
update_post_meta($id,'_ascla',$meta);MicroLifecycle::history($id,'pending');wp_update_post(['ID'=>$id,'post_status'=>'pending']);
$legacy=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'draft','post_title'=>'Propuesta aprobada pendiente de publicación','post_content'=>'Representa una aprobación anterior al cambio de flujo.','post_author'=>$admin->ID]);
update_post_meta($legacy,'_ascla',$meta+['micro_approved'=>true]);MicroLifecycle::history($legacy,'approved');wp_update_post(['ID'=>$legacy,'post_status'=>'ascla_hidden']);
echo wp_json_encode(['base'=>home_url(),'actors'=>$actors,'hub'=>$post['id'],'micro'=>$id,'legacy'=>$legacy,'selected'=>$ids]);
