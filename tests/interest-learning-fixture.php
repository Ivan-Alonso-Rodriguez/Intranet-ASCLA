<?php
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\{Content,Profiles};
use ASCLA\Core\Repositories\Store;
if(PHP_SAPI!=='cli'||wp_get_environment_type()!=='local'||getenv('ASCLA_E2E_EPHEMERAL')!=='1')throw new RuntimeException('Disposable local WordPress required.');
$key='ascla_interest_learning_fixture';$f=get_option($key);
if(getenv('ASCLA_FIXTURE_ACTION')==='cleanup'){
    if($f){
        foreach($f['posts'] as $id){if(str_starts_with((string)get_post_field('post_title',$id),$f['prefix'])){wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);Store::delete('relations',['target_id'=>$id]);}}
        foreach($f['users'] as $id){$u=get_userdata($id);if($u&&str_starts_with($u->user_login,$f['prefix'])){Store::delete('relations',['user_id'=>$id]);Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);foreach($f['posts'] as $post){delete_transient('ascla_read_'.$id.'_'.$post);}wp_delete_user($id);}}
        foreach(Store::rows('notifications','id>%d',[$f['notification_cursor']],'') as $row){$context=json_decode($row['context']??'',true)?:[];if(in_array((int)($context['actor']??0),$f['users'],true)&&in_array((int)($context['id']??0),$f['posts'],true))Store::delete('notifications',['id'=>(int)$row['id']]);}
        delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if($f)throw new RuntimeException('Clean the preceding fixture first.');
$main=get_user_by('login','ascla.admin');if(!$main||!user_can($main,'ascla_manage'))throw new RuntimeException('Local administrator required.');
global $wpdb;$prefix='learnqa_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);
$f=['prefix'=>$prefix,'posts'=>[],'users'=>[],'notification_cursor'=>(int)$wpdb->get_var('SELECT MAX(id) FROM '.Store::table('notifications'))];update_option($key,$f,false);
$member=wp_insert_user(['user_login'=>$prefix,'user_pass'=>$password,'user_email'=>$prefix.'@example.invalid','display_name'=>'Lucía Mendoza · QA','role'=>'ascla_member']);$f['users'][]=$member;update_option($key,$f,false);
$catalogs=Profiles::catalogs();$terms=get_terms(['taxonomy'=>'ascla_interest','hide_empty'=>false,'number'=>2]);if(is_wp_error($terms)||count($terms)<2)throw new RuntimeException('Two catalog interests required.');$term=$terms[0];$manual=(int)$terms[1]->term_id;
update_user_meta($member,'_ascla_profile',['first_name'=>'Lucía','last_name'=>'Mendoza · QA','position'=>'Especialista en cumplimiento','company'=>'Organización de prueba','city'=>'Lima','country'=>'Perú','bio'=>'Cuenta temporal para comprobar recomendaciones basadas en interacciones.','interests'=>[$manual],'industries'=>[(int)$catalogs['industry'][0]['id']],'goals'=>[(int)$catalogs['goal'][0]['id']],'directory'=>true,'networking'=>false]);
wp_set_current_user($main->ID);
foreach(['hub','resource','resource','hub'] as $i=>$type){
    $saved=Content::save($type,['title'=>$prefix.' · '.['Gobierno corporativo en la práctica','Guía para fortalecer el directorio','Herramientas de gestión de riesgos','Conversación protegida'][$i],'body'=>'Recurso de prueba para explorar prácticas de gobernanza, compartir experiencias y fortalecer la comunidad profesional.','status'=>'publish','interest'=>[(int)$term->term_id],'meta'=>['chatham'=>$i===3]]);
    $f['posts'][]=(int)$saved['id'];update_option($key,$f,false);
}
echo wp_json_encode(['prefix'=>$prefix,'user'=>$member,'login'=>$prefix,'password'=>$password,'source'=>$f['posts'][0],'resource'=>$f['posts'][1],'reading'=>$f['posts'][2],'protected'=>$f['posts'][3],'topic'=>$term->name,'topic_id'=>(int)$term->term_id,'manual_id'=>$manual]);
