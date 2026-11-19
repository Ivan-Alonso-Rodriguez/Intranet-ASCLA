<?php
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\Content;
use ASCLA\Core\Repositories\Store;
if(PHP_SAPI!=='cli'||wp_get_environment_type()!=='local'||getenv('ASCLA_E2E_EPHEMERAL')!=='1')throw new RuntimeException('Disposable local WordPress required.');
$key='ascla_ally_search_fixture';$f=get_option($key);
if(getenv('ASCLA_FIXTURE_ACTION')==='cleanup'){
    if($f){
        foreach($f['posts'] as $id){if(str_starts_with((string)get_post_field('post_title',$id),$f['prefix'])){wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);Store::delete('relations',['target_id'=>$id]);}}
        foreach($f['users'] as $id){$u=get_userdata($id);if($u&&str_starts_with($u->user_login,$f['prefix'])){Store::delete('audit',['actor_id'=>$id]);Store::delete('notifications',['user_id'=>$id]);Store::delete('relations',['user_id'=>$id]);wp_delete_user($id);}}
        delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if($f)throw new RuntimeException('Clean the preceding fixture first.');
$main=get_user_by('login','ascla.admin');if(!$main||!user_can($main,'ascla_manage'))throw new RuntimeException('Local administrator required.');
$prefix='allysearchqa_'.bin2hex(random_bytes(4));$password=wp_generate_password(32);$type='Convenio académico · '.$prefix;
$f=['prefix'=>$prefix,'users'=>[],'posts'=>[]];update_option($key,$f,false);
$member=wp_insert_user(['user_login'=>$prefix,'user_pass'=>$password,'user_email'=>$prefix.'@example.invalid','display_name'=>'Asociado de prueba de búsqueda','role'=>'ascla_member']);$f['users'][]=$member;update_option($key,$f,false);
wp_set_current_user($main->ID);$published=[];
foreach(range(1,25) as $i){
    $status=match($i){22=>'inactive',23=>'draft',default=>'publish'};
    $alliance=match($i){24=>'Socio estratégico',25=>'',default=>$type};
    $saved=Content::save('ally',['title'=>$prefix.' · Organización '.$i,'body'=>$i===25?'Descripción exclusiva '.$prefix:'Organización que colabora con ASCLA y ofrece formación profesional.','status'=>$status,'meta'=>['alliance_type'=>$alliance,'benefits'=>'Acceso a encuentros de formación y recursos institucionales.']]);
    $f['posts'][]=(int)$saved['id'];if($i<=21)$published[]=(int)$saved['id'];update_option($key,$f,false);
}
echo wp_json_encode(['prefix'=>$prefix,'login'=>$prefix,'password'=>$password,'user'=>$member,'type'=>$type,'published'=>$published,'inactive'=>$f['posts'][21],'draft'=>$f['posts'][22],'strategic'=>$f['posts'][23],'fallback'=>$f['posts'][24]]);
