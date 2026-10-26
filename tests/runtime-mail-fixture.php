<?php
require_once '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local'||getenv('ASCLA_E2E_EPHEMERAL')!=='1') { exit(2); }
$input=json_decode(stream_get_contents(STDIN),true);
if (($input['action']??'')==='cleanup') {
    $user=get_userdata((int)($input['id']??0));
    if ($user&&str_starts_with($user->user_login,'runtime_mail_')) {
        require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user->ID);
    }
    echo '{"cleaned":true}';exit;
}
$login='runtime_mail_'.bin2hex(random_bytes(6));
$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(40),'role'=>'ascla_member','first_name'=>'Prueba','last_name'=>'Correo','locale'=>'es_ES']);
if (is_wp_error($id)) { exit(3); }
echo wp_json_encode(['id'=>$id,'login'=>$login,'email'=>$login.'@example.invalid']);
