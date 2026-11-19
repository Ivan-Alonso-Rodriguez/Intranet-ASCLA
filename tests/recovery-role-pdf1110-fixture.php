<?php
/** Synthetic accounts for disposable loopback UI verification only. Never shipped in the plugin. */
if (PHP_SAPI!=='cli') { exit(1); }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Disposable loopback required'); }
require_once __DIR__.'/profile-fixture.php';
wp_set_current_user(1);ASCLA\Core\Services\Settings::save(['ai_provider'=>'mock','youtube_mode'=>'mock','turnstile_enabled'=>false]);
$password=wp_generate_password(32,false);$login='executive_'.bin2hex(random_bytes(5));
$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'display_name'=>'Elena Ejecutiva','role'=>'ascla_executive']);
wp_set_current_user($id);ASCLA\Core\Services\Profiles::save(ascla_test_profile(['first_name'=>'Elena','last_name'=>'Ejecutiva']));
echo wp_json_encode(['origin'=>'recovery-role-pdf1110-local-fixture','executive'=>['id'=>$id,'login'=>$login,'password'=>$password],'known_email'=>get_user_by('login','prueba.ascla.ana')->user_email])."\n";
