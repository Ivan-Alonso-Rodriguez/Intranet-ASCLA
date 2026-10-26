<?php
if (!defined('ABSPATH')) { require '/var/www/html/wp-load.php'; }
require_once __DIR__.'/profile-fixture.php';
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
if(wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1'){throw new RuntimeException('Disposable test site required.');}
if(getenv('ASCLA_FIXTURE_ACTION')==='cleanup'){
    $fixture=get_option('ascla_privacy108_fixture');if(!is_array($fixture)){echo "No temporary fixtures.\n";return;}
    foreach($fixture['users'] as $uid){$u=get_userdata($uid);if(!$u || !str_starts_with($u->user_login,'privacy108_')){throw new RuntimeException('Unexpected fixture owner.');}}
    foreach(Store::rows('imports','actor_id=%d',[$fixture['users'][0]],'ORDER BY id') as $row){Store::delete('import_rows',['import_id'=>$row['id']]);Store::delete('imports',['id'=>$row['id']]);}
    foreach(['registrations','attendance'] as $table)Store::delete($table,['event_id'=>$fixture['event']]);wp_delete_post($fixture['event'],true);
    foreach($fixture['users'] as $id){Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);}
    delete_option('ascla_privacy108_fixture');if(is_file('/tmp/privacy108.env'))unlink('/tmp/privacy108.env');echo "Temporary fixtures removed.\n";return;
}
$env=['ASCLA_ADMIN_PASSWORD'=>getenv('ASCLA_ADMIN_PASSWORD'),'ASCLA_DEMO_PASSWORD'=>getenv('ASCLA_DEMO_PASSWORD')];
$envFile=getenv('ASCLA_FIXTURE_ENV');if($envFile){foreach(file($envFile,FILE_IGNORE_NEW_LINES) as $line){if(str_contains($line,'=')){[$key,$value]=explode('=',$line,2);$env[$key]=$value;}}}
foreach(['ASCLA_ADMIN_PASSWORD','ASCLA_DEMO_PASSWORD'] as $key){if(!is_string($env[$key]) || strlen($env[$key])<12){throw new RuntimeException('Test credentials required.');}}
if(get_option('ascla_privacy108_fixture')){throw new RuntimeException('Previous fixtures require cleanup.');}
$suffix=bin2hex(random_bytes(5));$users=[];$logins=[];
add_filter('pre_wp_mail','__return_true');
foreach(['administrator','ascla_member','ascla_member'] as $index=>$role){
    $name='privacy108_'.($index===0?'admin_':'member_').$index.'_'.$suffix;$logins[]=$name;
    $id=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>$env[$index===0?'ASCLA_ADMIN_PASSWORD':'ASCLA_DEMO_PASSWORD'],'role'=>$role]);$users[]=$id;
    update_user_meta($id,'locale','es_ES');update_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>'Persona','last_name'=>$suffix.'_'.$index,'directory'=>$index!==2]));
}
wp_set_current_user($users[0]);$title='participante · gobernanza '.$suffix;
$id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_author'=>$users[0],'post_title'=>$title,'post_content'=>'Juan Pérez compartió sus acuerdos.']);
update_post_meta($id,'_ascla',['micro'=>true,'chatham'=>true,'public_at'=>time()-10,'start'=>gmdate('c',time()+3*DAY_IN_SECONDS),'end'=>gmdate('c',time()+3*DAY_IN_SECONDS+3600),'registration_deadline'=>gmdate('c',time()+2*DAY_IN_SECONDS),'capacity'=>10,'modality'=>'Virtual','identities'=>'Juan Pérez','agenda_ai'=>['title'=>$title,'theme'=>'gobernanza','objective'=>'Compartir ideas.','duration_minutes'=>30,'agenda'=>[['minutes'=>15,'topic'=>'Experiencias'],['minutes'=>15,'topic'=>'Próximos pasos']],'icebreaker'=>'Una pregunta para conversar.','closing_question'=>'Qué acuerdos llevar.'],'micro_approved'=>true]);
foreach(array_slice($users,1) as $uid)Store::insert('registrations',['event_id'=>$id,'user_id'=>$uid,'status'=>'accepted','created_at'=>current_time('mysql',true)]);
$fixture=['users'=>$users,'admin_login'=>$logins[0],'member_login'=>$logins[1],'member_email'=>get_userdata($users[1])->user_email,'event'=>$id,'legacy'=>$title,'title'=>'Círculo ASCLA · gobernanza '.$suffix,'site_url'=>home_url()];update_option('ascla_privacy108_fixture',$fixture,false);echo json_encode($fixture).PHP_EOL;
