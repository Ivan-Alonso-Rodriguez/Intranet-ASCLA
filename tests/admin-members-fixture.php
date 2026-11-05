<?php
/** Owned synthetic data for the administrative UI regression. Never available on a remote installation. */
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local environment required.'); }
$key='ascla_admin_members_ui_fixture';$fixture=get_option($key);
if (getenv('ASCLA_FIXTURE_ACTION')==='cleanup') {
    if ($fixture) {
        foreach($fixture['posts'] as $id) { wp_delete_post($id,true); }
        foreach($fixture['terms'] as $id) { wp_delete_term($id,'ascla_interest'); }
        foreach($fixture['users'] as $id) {
            foreach(['relations','registrations','attendance','user_interests','notifications'] as $table) { Store::delete($table,['user_id'=>$id]); }
            Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]).PHP_EOL;return;
}
if ($fixture) { throw new RuntimeException('Clean the preceding fixture first.'); }
$admin=get_user_by('login','ascla.admin');
if (!$admin || !user_can($admin,'ascla_manage')) { throw new RuntimeException('Local fixture administrator required.'); }
wp_set_current_user($admin->ID);
$fixture=['users'=>[],'posts'=>[],'terms'=>[],'prefix'=>'panelqa_'.bin2hex(random_bytes(4))];update_option($key,$fixture,false);
$remember=static function()use(&$fixture,$key): void { update_option($key,$fixture,false); };
foreach(['María Torres · QA','Perfil sin actividad · QA'] as $i=>$name) {
    $login=$fixture['prefix'].'_'.$i;
    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','display_name'=>$name,'user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
    $fixture['users'][]=$id;$remember();update_user_meta($id,'_ascla_profile',['first_name'=>$name,'last_name'=>'','position'=>'Gerente de cumplimiento','company'=>'Organización de prueba','directory'=>true]);
}
$user=$fixture['users'][0];
foreach(['Gobierno corporativo · QA','Gestión de riesgos · QA'] as $name) {
    $term=wp_insert_term($name.' '.$fixture['prefix'],'ascla_interest');$fixture['terms'][]=(int)$term['term_id'];$remember();
}
$profile=(array)get_user_meta($user,'_ascla_profile',true);$profile['interests']=$fixture['terms'];update_user_meta($user,'_ascla_profile',$profile);
$titles=['Gobernanza y decisiones del directorio','Riesgos emergentes en la organización','Buenas prácticas de cumplimiento','Tendencias en gobierno corporativo'];
for($i=0;$i<24;$i++) {
    $date=gmdate('Y-m-d H:i:s',time()-($i+1)*DAY_IN_SECONDS);
    $id=wp_insert_post(['post_type'=>'ascla_resource','post_status'=>'publish','post_title'=>$titles[$i%4].' · QA '.($i+1),'post_author'=>$user,'post_content'=>'Contenido sintético para una verificación local.','post_date_gmt'=>$date,'post_date'=>get_date_from_gmt($date)]);
    $fixture['posts'][]=$id;$remember();update_post_meta($id,'_ascla',['chatham'=>false]);wp_set_object_terms($id,[$fixture['terms'][$i%2]],'ascla_interest');
    Store::insert('relations',['user_id'=>$user,'target_id'=>$id,'kind'=>'like','created_at'=>$date]);
    if($i<3) { wp_insert_comment(['comment_post_ID'=>$id,'user_id'=>$user,'comment_content'=>'Un aporte para la comunidad · QA','comment_approved'=>1]); }
}
echo wp_json_encode(['user'=>$user,'empty_user'=>$fixture['users'][1],'query'=>$fixture['prefix'],'likes'=>24,'publications'=>24,'comments'=>3]).PHP_EOL;
