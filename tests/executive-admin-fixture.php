<?php
/** Disposable actors for executive/administrator parity, never production accounts. */
require '/var/www/html/wp-load.php';
require_once __DIR__.'/profile-fixture.php';
if(PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || parse_url(home_url(),PHP_URL_HOST)!=='ascla-executive.test') {
    throw new RuntimeException('Only the isolated executive regression environment is accepted.');
}
if(($argv[1]??'')==='cleanup') {
    require_once ABSPATH.'wp-admin/includes/user.php';
    $fixture=json_decode(file_get_contents('/tmp/executive-private-access.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($fixture['actors'] as $actor) {
        $user=get_userdata($actor['id']);
        if(!$user || $user->user_login!==$actor['login'] || !str_starts_with($user->user_login,$fixture['prefix'].'_')) { throw new RuntimeException('Synthetic actor mismatch.'); }
    }
    foreach($fixture['actors'] as $actor) { wp_delete_user($actor['id']); }
    unlink('/tmp/executive-private-access.json');echo 'Synthetic actors removed.'.PHP_EOL;exit;
}
update_option('WPLANG','es_ES');$actors=[];$prefix='executive_'.bin2hex(random_bytes(5));
foreach(['admin'=>'administrator','executive'=>'ascla_executive','member'=>'ascla_member'] as $key=>$role) {
    $login=$prefix.'_'.$key;$password=bin2hex(random_bytes(24));
    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
    if(is_wp_error($id)) { throw new RuntimeException('Could not create synthetic actor.'); }
    wp_set_current_user($id);ASCLA\Core\Services\Profiles::save(ascla_test_profile(['first_name'=>'Prueba','last_name'=>ucfirst($key),'country'=>'Perú']));
    update_user_meta($id,'locale','es_ES');$actors[$key]=['id'=>$id,'login'=>$login,'password'=>$password];
}
file_put_contents('/tmp/executive-private-access.json',wp_json_encode(['base'=>home_url(),'actors'=>$actors,'prefix'=>$prefix],JSON_UNESCAPED_SLASHES));
echo 'Executive regression actors ready.'.PHP_EOL;
