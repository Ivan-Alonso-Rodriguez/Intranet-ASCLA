<?php
/** Activity samples only for the disposable local UI laboratory. */
if (PHP_SAPI!=='cli') { exit(1); }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local' || !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Disposable local site required.'); }
$ana=get_user_by('login','prueba.ascla.ana');$admin=get_user_by('login','prueba.ascla.administrador');
if (!$ana || !$admin) { throw new RuntimeException('Shared local fixture required.'); }
wp_set_current_user($admin->ID);
$samples=[['event_updated','Se actualizó el horario del encuentro de noviembre'],['resource','Nuevas ideas para preparar la próxima junta'],['moderation','Tu publicación fue aprobada'],['connection','Una nueva conexión en tu comunidad']];
for ($i=0;$i<24;$i++) {
    [$kind,$label]=$samples[$i%count($samples)];
    ASCLA\Core\Repositories\Store::insert('notifications',['user_id'=>$ana->ID,'kind'=>$kind,'label'=>$label,'url'=>'','context'=>'{}','created_at'=>gmdate('Y-m-d H:i:s',time()-$i*4500),'read_at'=>$i>7?gmdate('Y-m-d H:i:s'):null]);
}
echo "24 avisos locales preparados.\n";
