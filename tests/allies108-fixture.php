<?php
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\Content;
use ASCLA\Core\Repositories\Store;
if(PHP_SAPI!=='cli'||wp_get_environment_type()!=='local'||getenv('ASCLA_E2E_EPHEMERAL')!=='1')throw new RuntimeException('Disposable local WordPress required.');
$key='ascla_allies108_fixture';$fixture=get_option($key);
if(getenv('ASCLA_FIXTURE_ACTION')==='cleanup'){
 if($fixture){foreach($fixture['posts'] as $id){wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);Store::delete('relations',['target_id'=>$id]);}foreach($fixture['users'] as $id){Store::delete('audit',['actor_id'=>$id]);Store::delete('notifications',['user_id'=>$id]);wp_delete_user($id);}delete_option($key);}echo wp_json_encode(['cleaned'=>true]);return;
}
if($fixture)throw new RuntimeException('Clean the preceding fixture first.');
$main=get_user_by('login','ascla.admin');if(!$main||!user_can($main,'ascla_manage'))throw new RuntimeException('Local administrator required.');
$prefix='alliesqa_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);
$fixture=['prefix'=>$prefix,'posts'=>[],'users'=>[]];update_option($key,$fixture,false);
foreach(['administrator','ascla_member'] as $i=>$role){$login=$prefix.'_'.$i;$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);$fixture['users'][]=$id;update_option($key,$fixture,false);}
$data=[];
foreach(['publish','publish','inactive','draft'] as $i=>$status){wp_set_current_user($i>1?$fixture['users'][0]:$main->ID);$saved=Content::save('ally',['title'=>$prefix.' · '.['Red de Directorios','Centro de Formación','Convenio regional','Alianza en preparación'][$i],'body'=>'Organización que impulsa el gobierno corporativo y el intercambio profesional.','status'=>$status,'meta'=>['alliance_type'=>'Convenio','url'=>'https://example.org/','benefits'=>'Acceso a encuentros y formación especializada para miembros de ASCLA.','conditions'=>'Membresía activa y registro previo. Cupos sujetos a disponibilidad.','initiatives'=>'Programa de formación e intercambio de buenas prácticas.','contact_name'=>'Equipo de alianzas','contact_email'=>'alianzas@example.invalid','contact_phone'=>'+51 987 654 321']]);$fixture['posts'][]=(int)$saved['id'];$data[]=(int)$saved['id'];update_option($key,$fixture,false);}
echo wp_json_encode(['prefix'=>$prefix,'active'=>$data[0],'second'=>$data[1],'inactive'=>$data[2],'draft'=>$data[3],'member'=>['login'=>$prefix.'_1','password'=>$password]]);
