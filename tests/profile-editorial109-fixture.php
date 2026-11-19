<?php
if (PHP_SAPI!=='cli' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { exit(2); }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local') { exit(2); }
$fixture=json_decode(stream_get_contents(STDIN),true);
$a=(int)$fixture['actors']['Asociado']['id'];$b=(int)$fixture['actors']['Ejecutivo']['id'];
foreach ([$a,$b] as $id) {
    $user=get_userdata($id);
    if (!$user || !str_starts_with($user->user_login,'qa_role_')) { throw new RuntimeException('Owned QA actors required.'); }
}
use ASCLA\Core\Repositories\Store;
foreach (['conversation_request','connected'] as $kind) Store::insert('relations',['user_id'=>$a,'target_id'=>$b,'kind'=>$kind,'created_at'=>current_time('mysql',true)]);
wp_set_current_user($a);
$fixture['conversation_id']=ASCLA\Core\Services\Messaging::start($b)['id'];
echo wp_json_encode($fixture);
