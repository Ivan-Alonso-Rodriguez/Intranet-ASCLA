<?php
/** Synthetic profiles for the fixed twelve-profile Directory layout. */
require '/var/www/html/wp-load.php';
require_once __DIR__.'/profile-fixture.php';
if(PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || parse_url(home_url(),PHP_URL_HOST)!=='ascla-directory.test') {
    throw new RuntimeException('Only the isolated directory regression environment is accepted.');
}
update_option('WPLANG','es_ES');$prefix='directory_'.bin2hex(random_bytes(5));$viewer=[];
foreach (range(0,13) as $n) {
    $login=$prefix.'_'.$n;$password=bin2hex(random_bytes(24));
    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>'ascla_member']);
    if(is_wp_error($id)) { throw new RuntimeException('Could not create synthetic member.'); }
    wp_set_current_user($id);ASCLA\Core\Services\Profiles::save(ascla_test_profile(['first_name'=>'Persona','last_name'=>sprintf('%02d',$n),'country'=>'Perú','company'=>$prefix,'directory'=>$n!==0]));
    update_user_meta($id,'locale','es_ES');
    if($n===0) { $viewer=['id'=>$id,'login'=>$login,'password'=>$password]; }
}
// Exercise the upgrade path without changing or deleting existing settings.
update_option('ascla_settings',array_merge(ASCLA\Core\Services\Settings::get(),['directory_page_size'=>15]),false);
file_put_contents('/tmp/directory-private-access.json',wp_json_encode(['base'=>home_url(),'prefix'=>$prefix,'viewer'=>$viewer],JSON_UNESCAPED_SLASHES));
echo 'Synthetic directory profiles ready.'.PHP_EOL;
