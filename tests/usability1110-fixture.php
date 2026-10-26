<?php
/** Prepare activity and a connected chat exclusively in a disposable loopback site. */
if (PHP_SAPI!=='cli') { exit(1); }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local' || !in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Disposable local site required.'); }
$ana=get_user_by('login','prueba.ascla.ana');$bruno=get_user_by('login','prueba.ascla.bruno');
if (!$ana || !$bruno) { throw new RuntimeException('Shared-flow fixture required.'); }
wp_set_current_user($ana->ID);
if (!ASCLA\Core\Services\Connections::areConnected($ana->ID,$bruno->ID)) {
    $state=ASCLA\Core\Services\Connections::request($bruno->ID);
    wp_set_current_user($bruno->ID);ASCLA\Core\Services\Connections::respond((int)$state['request_id'],'accept');
}
wp_set_current_user($ana->ID);
$chat=ASCLA\Core\Services\Messaging::start($bruno->ID);
for ($i=0;$i<25;$i++) ASCLA\Core\Services\Notifications::send($ana->ID,'test','Notificación de prueba '.$i,'');
echo wp_json_encode(['conversation'=>$chat['id']]);
