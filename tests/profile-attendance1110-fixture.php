<?php
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1' || !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Disposable loopback environment required'); }
require __DIR__.'/profile-fixture.php';
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\Profiles;
use ASCLA\Core\Repositories\Store;
$actors=[];
foreach (get_users(['search'=>'profile_csv_*','search_columns'=>['user_login'],'number'=>100]) as $previous) {
    if (preg_match('/^profile_csv_[a-f0-9]{10}(?:_edited)?$/D',$previous->user_login)) {
        Store::delete('media',['user_id'=>$previous->ID]);wp_delete_user($previous->ID);
    }
}
foreach (['member'=>['María Alejandra','Contreras'],'other'=>['Pedro Enrique','Villanueva']] as $kind=>$parts) {
    $login='profile_csv_'.bin2hex(random_bytes(5));$password=wp_generate_password(30,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','first_name'=>$parts[0],'last_name'=>$parts[1],'display_name'=>implode(' ',$parts),'role'=>'ascla_member']);
    $file=Store::insert('media',['user_id'=>$id,'post_id'=>0,'name'=>'test-portrait.png','mime'=>'image/png','bytes'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0XkAAAAASUVORK5CYII='),'created_at'=>current_time('mysql',true)]);
    update_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>$parts[0],'last_name'=>$parts[1],'photo_id'=>$file,'country'=>'Perú','city'=>'Lima','member_type'=>'independent','directory'=>true,'revision'=>0]));
    $actors[$kind]=['id'=>$id,'login'=>$login,'password'=>$password,'email'=>$login.'@example.invalid'];
}
$admin=get_user_by('login','ascla.admin');wp_set_current_user($admin->ID);
$event=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_author'=>$admin->ID,'post_title'=>'Asistencia por correo y nombre','post_content'=>'Evento sintético para revisar importación']);
update_post_meta($event,'_ascla',['start'=>'2026-09-25T13:00:00Z','end'=>'2026-09-25T14:30:00Z','chatham'=>false]);
update_post_meta($event,'_ascla_start','2026-09-25T13:00:00Z');update_post_meta($event,'_ascla_end','2026-09-25T14:30:00Z');
echo wp_json_encode(['base'=>home_url(),'actors'=>$actors,'event'=>$event]);
