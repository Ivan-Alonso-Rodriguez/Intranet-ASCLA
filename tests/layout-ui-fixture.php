<?php
/** Isolated data for the unified forum feed, inline file library and member actions. */
require '/var/www/html/wp-load.php';
require_once __DIR__.'/profile-fixture.php';
if(PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || parse_url(home_url(),PHP_URL_HOST)!=='ascla-layout.test') {
    throw new RuntimeException('Only the isolated layout regression environment is accepted.');
}
update_option('WPLANG','es_ES');$prefix='layout_'.bin2hex(random_bytes(5));$actors=[];
foreach(['admin'=>['administrator','Administrador'],'executive'=>['ascla_executive','Ejecutivo'],'member'=>['ascla_member','Ana'],'other'=>['ascla_member','Bruno']] as $key=>[$role,$name]) {
    $login=$prefix.'_'.$key;$password=bin2hex(random_bytes(24));
    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
    if(is_wp_error($id)) { throw new RuntimeException('Could not create synthetic actor.'); }
    wp_set_current_user($id);ASCLA\Core\Services\Profiles::save(ascla_test_profile(['first_name'=>$name,'last_name'=>'Prueba ASCLA','country'=>'Perú','company'=>$prefix]));
    update_user_meta($id,'locale','es_ES');$actors[$key]=['id'=>$id,'login'=>$login,'password'=>$password];
}
wp_set_current_user($actors['executive']['id']);
$forum=ASCLA\Core\Services\Content::save('forum',['title'=>$prefix.' Foro de prueba','body'=>'Foro integrado con las publicaciones de la comunidad.','status'=>'publish']);
$topic=ASCLA\Core\Services\Content::save('topic',['title'=>$prefix.' Publicación de prueba','body'=>'Una publicación dentro de la misma lista.','parent'=>$forum['id'],'status'=>'publish']);
ASCLA\Core\Services\Content::save('forum',['title'=>$prefix.' Borrador privado','body'=>'No visible en Comunidad.','status'=>'draft']);
$file=function(int $owner,string $name): int { return ASCLA\Core\Repositories\Store::insert('media',['user_id'=>$owner,'post_id'=>0,'name'=>$name,'mime'=>'text/plain','bytes'=>'Archivo sintético de prueba.','created_at'=>current_time('mysql',true)]); };
$own=$file($actors['member']['id'],$prefix.'-archivo-personal.txt');
$file($actors['admin']['id'],$prefix.'-archivo-admin.txt');$files=[];
for($i=1;$i<=21;$i++) { $files[]=$file($actors['other']['id'],$prefix.'-archivo-'.sprintf('%02d',$i).'.txt'); }
file_put_contents('/tmp/layout-private-access.json',wp_json_encode(['base'=>home_url(),'prefix'=>$prefix,'actors'=>$actors,'forum'=>$forum['id'],'topic'=>$topic['id'],'files'=>$files,'own_file'=>$own],JSON_UNESCAPED_SLASHES));
echo 'Synthetic layout actors, posts and files ready.'.PHP_EOL;
