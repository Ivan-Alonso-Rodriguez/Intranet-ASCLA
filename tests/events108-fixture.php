<?php
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || getenv('ASCLA_E2E_EPHEMERAL')!=='1' || wp_get_environment_type()!=='local') {
    throw new RuntimeException('Disposable environment required.');
}
$admin=get_user_by('login','ascla.admin');
if (!$admin || !user_can($admin,'ascla_manage')) {
    throw new RuntimeException('Test administrator required.');
}
$prefix='Eventos108 '.bin2hex(random_bytes(4));
$start=time()-3*DAY_IN_SECONDS;
$ids=[];
for ($i=0;$i<19;$i++) {
    $id=wp_insert_post([
        'post_type'=>'ascla_event','post_status'=>'publish','post_author'=>$admin->ID,
        'post_title'=>$prefix.' anterior '.$i,'post_content'=>'Evento histórico para pruebas de paginación y asistencia.',
    ],true);
    if (is_wp_error($id) || !$id) { throw new RuntimeException('Could not create historical event fixture.'); }
    update_post_meta($id,'_ascla',[
        'start'=>gmdate('c',$start),'end'=>gmdate('c',$start+HOUR_IN_SECONDS),
        'modality'=>'Virtual','url'=>'https://example.invalid/meeting','chatham'=>false,
    ]);
    $ids[]=$id;
}
echo wp_json_encode(['prefix'=>$prefix,'ids'=>$ids]).PHP_EOL;
