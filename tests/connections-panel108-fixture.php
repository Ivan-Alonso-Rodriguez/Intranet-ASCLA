<?php
/** Owned local connection/request states for the compact directory panel. */
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\ConversationRequests;
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local WordPress required.'); }
$key='ascla_connections_panel108_fixture';$fixture=get_option($key);
if (getenv('ASCLA_FIXTURE_ACTION')==='cleanup') {
    if ($fixture) {
        foreach ($fixture['users'] as $id) {
            foreach (Store::rows('participants','user_id=%d',[$id],'') as $p) {
                Store::delete('messages',['conversation_id'=>(int)$p['conversation_id']]);Store::delete('participants',['conversation_id'=>(int)$p['conversation_id']]);Store::delete('conversations',['id'=>(int)$p['conversation_id']]);
            }
            foreach (['relations'] as $table) { Store::delete($table,['user_id'=>$id]);Store::delete($table,['target_id'=>$id]); }
            foreach (['notifications','jobs','user_interests'] as $table) { Store::delete($table,['user_id'=>$id]); }
            foreach (['audit','deliveries'] as $table) { Store::delete($table,['actor_id'=>$id]); }
            wp_delete_user($id);
        }
        delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if ($fixture) { throw new RuntimeException('Clean the preceding fixture first.'); }
$prefix='panelnet_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);$fixture=['users'=>[]];update_option($key,$fixture,false);
$names=['María Torres','Iván Rodríguez','Estudiantes Utec','Elena García','Carlos Muñoz','Rosa Méndez','Marisol Rojas','Andrés Salas','Perfil sin conexiones'];
foreach ($names as $i=>$name) {
    $login=$prefix.'_'.$i;$id=wp_insert_user(['user_login'=>$login,'display_name'=>$name,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>'ascla_member']);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['users'][]=$id;update_option($key,$fixture,false);
    update_user_meta($id,'_ascla_profile',['first_name'=>$name,'last_name'=>'','position'=>$i===2?'CARGO_RESERVADO_PANEL':'Especialista en gobierno corporativo','company'=>$i===2?'EMPRESA_RESERVADA_PANEL':'Organización ASCLA','networking'=>true,'directory'=>true,'hidden'=>$i===2?['position','company']:[]]);
    update_user_meta($id,'_ascla_email_notifications',['connections'=>false,'messages'=>false,'events'=>false,'support'=>false]);
}
$ids=$fixture['users'];$me=$ids[0];$rows=[];
foreach ([[1,0,'connect','Hola, me gustaría compartir experiencias en gobierno corporativo.'],[2,0,'connect',''],[0,3,'connected',''],[4,0,'connected',''],[0,5,'connect','Te invito a conectar con nuestra comunidad.'],[0,6,'block','']] as [$from,$to,$kind,$message]) {
    $rows[]=Store::insert('relations',['user_id'=>$ids[$from],'target_id'=>$ids[$to],'kind'=>$kind,'detail'=>$message,'created_at'=>gmdate('Y-m-d H:i:s')]);
}
wp_set_current_user($me);ConversationRequests::request($ids[1],'Hola Iván, quisiera conversar sobre buenas prácticas del directorio.');
wp_set_current_user($ids[7]);ConversationRequests::request($me,'Me gustaría conversar sobre formación para los asociados.');
echo wp_json_encode(['login'=>$prefix.'_0','empty_login'=>$prefix.'_8','password'=>$password,'users'=>$ids,'relations'=>$rows,'names'=>$names]);
