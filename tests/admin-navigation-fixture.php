<?php
/** Synthetic accounts for the administrative navigation regression, local only. */
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local') { throw new RuntimeException('Local disposable WordPress required.'); }
require_once ABSPATH.'wp-admin/includes/user.php';
require_once __DIR__.'/profile-fixture.php';
use ASCLA\Core\Services\Profiles;
use ASCLA\Core\Repositories\Store;
add_filter('pre_wp_mail',static fn()=>true);
$input=json_decode(stream_get_contents(STDIN),true)?:[];
if (($input['action']??'setup')==='cleanup') {
    $actors=$input['actors']??[];
    if (!$actors) { throw new RuntimeException('Missing owned fixture accounts.'); }
    foreach ($actors as $actor) {
        $user=get_userdata((int)($actor['id']??0));
        if (!$user || !str_starts_with($user->user_login,'qa_role_') || $user->user_login!==($actor['login']??'')) { throw new RuntimeException('Unexpected fixture owner.'); }
    }
    foreach ($actors as $actor) {
        $id=(int)$actor['id'];
        foreach (['relations'=>'user_id','notifications'=>'user_id','audit'=>'actor_id','jobs'=>'user_id'] as $table=>$column) { Store::delete($table,[$column=>$id]); }
        Store::delete('relations',['target_id'=>$id]);wp_delete_user($id);
    }
    echo "Fixture accounts removed.\n";return;
}
$actors=[];
foreach (['Administrador'=>'administrator','Asociado'=>'ascla_member','Moderador'=>'ascla_moderator','Ejecutivo'=>'ascla_executive'] as $name=>$role) {
    $login='qa_role_'.strtolower($name).'_'.bin2hex(random_bytes(4));$password=wp_generate_password(32,false);
    $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','role'=>$role,'display_name'=>$name.' Prueba']);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    wp_set_current_user($id);Profiles::save(ascla_test_profile(['first_name'=>$name,'last_name'=>'Prueba','directory'=>true,'networking'=>true,'microevents'=>true]));
    update_user_meta($id,'locale','es_ES');$actors[$name]=['id'=>$id,'login'=>$login,'password'=>$password];
}
echo wp_json_encode(['base'=>untrailingslashit(home_url()),'actors'=>$actors]).PHP_EOL;
