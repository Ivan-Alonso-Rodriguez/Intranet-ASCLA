<?php
/** Reset test counters between independent runs, never during an active scan. */
if (PHP_SAPI!=='cli') { exit(1); }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local QA only.'); }
$fixture=json_decode(stream_get_contents(STDIN),true);
foreach ((array)($fixture['actors']??[]) as $actor) {
    $user=get_userdata((int)($actor['id']??0));
    if (!$user || !str_starts_with($user->user_login,'qa_role_') || $user->user_login!==($actor['login']??'')) { throw new RuntimeException('Owned synthetic actors required.'); }
}
if (count((array)($fixture['actors']??[]))!==4) { throw new RuntimeException('Complete fixture required.'); }
global $wpdb;
$names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",$wpdb->esc_like('_transient_ascla_ts_').'%'));
foreach ($names as $name) { delete_transient(substr($name,strlen('_transient_'))); }
echo 'Local fixture authentication counters reset between scans; protection settings unchanged.'.PHP_EOL;
