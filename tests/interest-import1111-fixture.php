<?php
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local'||getenv('ASCLA_E2E_EPHEMERAL')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true))throw new RuntimeException('Disposable loopback environment required');
require __DIR__.'/profile-fixture.php';
require_once ABSPATH.'wp-admin/includes/user.php';
foreach(get_users(['search'=>'interest1111_*','search_columns'=>['user_login'],'number'=>100]) as $previous){
    if(preg_match('/^interest1111_[a-f0-9]{10}$/D',$previous->user_login))wp_delete_user($previous->ID);
}
$actors=[];
foreach(['member'=>['María Alejandra','Valverde'],'other'=>['Pedro Enrique','Villanueva']] as $kind=>$parts){
    $login='interest1111_'.bin2hex(random_bytes(5));$password=wp_generate_password(30,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','first_name'=>$parts[0],'last_name'=>$parts[1],'display_name'=>implode(' ',$parts),'role'=>'ascla_member']);
    update_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>$parts[0],'last_name'=>$parts[1],'bio'=>'Biografía sintética conservada','interests'=>[]]));
    $actors[$kind]=['id'=>$id,'login'=>$login,'password'=>$password,'email'=>$login.'@example.invalid'];
}
wp_set_current_user(get_user_by('login','ascla.admin')->ID);
$term=ASCLA\Core\Domain\InterestCatalog::create('Ciberseguridad');
echo wp_json_encode(['base'=>home_url(),'actors'=>$actors,'topic'=>$term['id']]);
