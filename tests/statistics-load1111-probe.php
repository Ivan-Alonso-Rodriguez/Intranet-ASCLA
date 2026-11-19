<?php
/** Bounded read-only analytics profiling on a disposable local WordPress. */
define('SAVEQUERIES',true);
require '/var/www/html/wp-load.php';
if(PHP_SAPI!=='cli'||wp_get_environment_type()!=='local'||getenv('ASCLA_E2E_EPHEMERAL')!=='1')exit(1);
use ASCLA\Core\Services\Statistics;
use ASCLA\Core\Repositories\StatisticsCache;
wp_set_current_user(get_user_by('login','ascla.admin')->ID);
StatisticsCache::invalidate();$rows=[];
foreach([[],['page'=>2],['event_page'=>2],[],['page'=>2],['event_page'=>2],[],['page'=>2],['event_page'=>2]] as $filter){
    wp_cache_flush();$wpdb->queries=[];$begin=hrtime(true);$data=Statistics::dashboard($filter);$queries=$wpdb->queries;
    $rows[]=['filter'=>$filter,'ms'=>round((hrtime(true)-$begin)/1e6,3),'queries'=>count($queries),'sql_ms'=>round(array_sum(array_column($queries,1))*1000,3),'summary'=>$data['summary']];
}
echo wp_json_encode(['scope'=>'Nine alternating dashboard requests, identical dataset, current account and permissions; request cache flushed between samples.','samples'=>$rows]);
