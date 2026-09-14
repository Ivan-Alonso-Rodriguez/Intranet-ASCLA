<?php
// Temporary prerequisite for older regression suites; not part of the distributed plugin.
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local') exit(1);
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\Connections;
$input=json_decode(stream_get_contents(STDIN),true);$a=(int)($input['a']??0);$b=(int)($input['b']??0);
foreach([$a,$b] as $id) { $user=get_userdata($id); if (!$user || !(str_starts_with($user->user_login,'demo.') || $user->user_login==='ivan.alonso2602')) exit(2); }
if (($input['action']??'')==='setup') {
    $state=Connections::between($a,$b); if ($state['blocked']) exit(3);
    $created=$state['state']!=='connected';
    $id=$created?Store::insert('relations',['user_id'=>$a,'target_id'=>$b,'kind'=>'connected','created_at'=>current_time('mysql',true)]):$state['connection_id'];
    echo wp_json_encode(['a'=>$a,'b'=>$b,'id'=>$id,'created'=>$created]);
} elseif (!empty($input['created'])) {
    $row=Store::one('relations',(int)$input['id']);
    if (!$row || (int)$row['user_id']!==$a || (int)$row['target_id']!==$b || $row['kind']!=='connected') exit(4);
    Store::delete('relations',['id'=>(int)$input['id']]); echo '{}';
} else echo '{}';
