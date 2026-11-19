<?php
/** CLI-only fixtures for the isolated WordPress used by Bruno and Playwright. */
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Local isolated WordPress required.'); }
require __DIR__.'/profile-fixture.php';
add_filter('pre_wp_mail',static fn()=>true);
wp_set_current_user(1);
ASCLA\Core\Services\Settings::save(['ai_provider'=>'mock']);
$actors=[];
foreach (['Ana'=>'ascla_member','Bruno'=>'ascla_member','Carla'=>'ascla_member','Administrador'=>'administrator'] as $name=>$role) {
    $login='prueba.ascla.'.strtolower($name);
    if (username_exists($login)) { throw new RuntimeException('Fixture already exists; use its private access file.'); }
    $password=wp_generate_password(32,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','role'=>$role,'display_name'=>$name.' Prueba ASCLA']);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    wp_set_current_user($id);
    ASCLA\Core\Services\Profiles::save(ascla_test_profile(['first_name'=>$name,'last_name'=>'Prueba ASCLA','directory'=>true,'networking'=>true,'microevents'=>true]));
    update_user_meta($id,'locale','es_ES');
    $actors[$name]=['id'=>$id,'login'=>$login,'password'=>$password];
}
echo wp_json_encode(['origin'=>'ascla-local-test-users','base'=>home_url(),'browser_channel'=>'msedge','assistant_provider'=>'mock','actors'=>$actors]);
