<?php
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local') { exit(2); }
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\{Notifications,Profiles,Messaging};
use ASCLA\Core\Repositories\Store;
if (($argv[1]??'')==='cleanup') {
    $data=json_decode($argv[2],true);
    foreach ($data['posts'] as $id) { wp_delete_post((int)$id,true); }
    Store::delete('conversations',['id'=>$data['conversation']]);
    Store::delete('participants',['conversation_id'=>$data['conversation']]);
    Store::delete('messages',['conversation_id'=>$data['conversation']]);
    foreach ($data['users'] as $id) {
        foreach (['notifications','jobs','relations'] as $table) { Store::delete($table,['user_id'=>$id]); }
        wp_delete_user((int)$id);
    }
    exit;
}
$key=bin2hex(random_bytes(5)); $password=wp_generate_password(30,false); $users=[];
foreach (['Elena Torres','Diego Salazar'] as $i=>$name) {
    $users[]=wp_insert_user(['user_login'=>'notice_ui_'.$key.'_'.$i,'user_pass'=>$password,'display_name'=>$name,'role'=>'ascla_member']);
    wp_set_current_user(end($users)); Profiles::save(['first_name'=>explode(' ',$name)[0],'last_name'=>explode(' ',$name)[1],'directory'=>true,'networking'=>true]);
}
$posts=[];
foreach (['hub'=>'Cómo preparar una junta con mejores decisiones','event'=>'Conversatorio: gobernanza e inteligencia artificial','resource'=>'Guía para el seguimiento de acuerdos'] as $type=>$title) {
    $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_title'=>$title,'post_content'=>'Contenido ficticio para comprobar los destinos de las notificaciones.','post_status'=>'publish','post_author'=>$users[1]]); $posts[]=$id;
    if ($type==='event') { update_post_meta($id,'_ascla',['start'=>gmdate('c',time()+86400),'end'=>gmdate('c',time()+90000),'capacity'=>10,'modality'=>'Virtual']); }
}
wp_set_current_user($users[1]); $conversation=Messaging::start($users[0]);
Messaging::send((int)$conversation['id'],'Hola Elena, conversemos sobre el seguimiento de acuerdos de la junta.');
$job=Store::insert('jobs',['user_id'=>$users[0],'kind'=>'answer','payload'=>wp_json_encode(['question'=>'¿Cómo mejorar el seguimiento de acuerdos?']),'status'=>'completed','result'=>wp_json_encode(['answer'=>'Define responsables y plazos claros, y revisa el avance en cada sesión. Respuesta ficticia de prueba.','sources'=>[['id'=>$posts[2],'title'=>'Guía para el seguimiento de acuerdos','url'=>ASCLA\Core\Domain\Catalog::url('centro-conocimiento',['item'=>$posts[2]])]],'mode'=>'DEMO MODE']),'created_at'=>current_time('mysql',true)]);
Notifications::send($users[0],'welcome','Bienvenida');
Notifications::send($users[0],'comment','Comentario','',['type'=>'post','id'=>$posts[0],'actor'=>$users[1]]);
Notifications::send($users[0],'job','Aviso antiguo');
Notifications::send($users[0],'connection','Conexión','',['type'=>'profile','id'=>$users[1]]);
Notifications::send($users[0],'resource','Recurso','',['type'=>'post','id'=>$posts[2]]);
Notifications::send($users[0],'event','Evento','',['type'=>'post','id'=>$posts[1]]);
Notifications::send($users[0],'reaction','Reacción','',['type'=>'post','id'=>$posts[0],'actor'=>$users[1]]);
Notifications::send($users[0],'job','Respuesta','',['type'=>'job','id'=>$job]);
wp_set_current_user($users[0]);
echo wp_json_encode(['login'=>'notice_ui_'.$key.'_0','password'=>$password,'users'=>$users,'posts'=>$posts,'conversation'=>(int)$conversation['id'],'job'=>$job,'notices'=>Notifications::list()]);
