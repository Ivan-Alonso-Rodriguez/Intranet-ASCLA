<?php
/** Synthetic profiling fixture. Never available through a WordPress HTTP route. */
define('SAVEQUERIES',true);
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { exit(1); }
require_once __DIR__.'/profile-fixture.php';
use ASCLA\Core\Services\{Content,Profiles,Matching,Notifications,Statistics,Settings};
use ASCLA\Core\Repositories\{Store,StatisticsCache};
$in=json_decode(stream_get_contents(STDIN),true)?:[];$admin=get_user_by('login','ascla.admin');$member=get_user_by('login','demo.asociado');
if (!$admin || !$member || !get_option('ascla_nice_load')) { exit(2); }
if (($in['action']??'')==='augment') {
    if (get_option('ascla_optimization_fixture')) { exit(3); }
    wp_set_current_user($admin->ID);Settings::save(['ai_provider'=>'mock']);
    $actors=[];
    foreach (range(1,32) as $n) {
        $login='opt_stress_'.bin2hex(random_bytes(5));$password=wp_generate_password(32);
        $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','display_name'=>'Estrés '.$n,'role'=>'ascla_member']);
        if (is_wp_error($id)) { throw new RuntimeException('Cannot create disposable actor'); }
        update_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>'Estrés','last_name'=>(string)$n]));
        $actors[]=['id'=>$id,'login'=>$login,'password'=>$password,'role'=>'ascla_member'];
    }
    foreach (range(1,800) as $n) {
        $id=wp_insert_post(['post_type'=>'ascla_resource','post_status'=>'publish','post_title'=>'Recurso de rendimiento '.$n,'post_content'=>'OPT_RARE_KNOWLEDGE_7319 · Supervisión y gobernanza. '.str_repeat('Contenido sintético para probar consultas. ',8),'post_author'=>$admin->ID]);
        update_post_meta($id,'_ascla',['resource_type'=>'Artículo','chatham'=>false]);
    }
    $actor=(int)$actors[0]['id'];wp_set_current_user($actor);
    foreach (range(1,300) as $n) { Store::insert('notifications',['user_id'=>$actor,'kind'=>'event','label'=>'Aviso sintético '.$n,'url'=>'','context'=>'{}','created_at'=>current_time('mysql',true)]+($n%2?['read_at'=>current_time('mysql',true)]:[])); }
    wp_set_current_user($admin->ID);
    $event=Content::save('event',['title'=>'Cupo concurrente · rendimiento','body'=>'Dos plazas para comprobar ausencia de sobreventa.','status'=>'publish','meta'=>['start'=>gmdate('c',time()+14*DAY_IN_SECONDS),'end'=>gmdate('c',time()+14*DAY_IN_SECONDS+3600),'capacity'=>2,'location'=>'Lima','modality'=>'Presencial','chatham'=>false]]);
    $fixture=['origin'=>'ascla-local-optimization-fixture','actors'=>$actors,'event'=>$event['id'],'dataset'=>get_option('ascla_nice_load')+['extra_resources'=>800,'stress_actors'=>32,'extra_notifications'=>300]];
    update_option('ascla_optimization_fixture',$fixture,false);echo wp_json_encode($fixture);return;
}
if (($in['action']??'')==='measure') {
    wp_set_current_user($member->ID);delete_user_meta($member->ID,'_ascla_recommendation_batch');
    $operations=[
        'directory'=>static fn()=>Profiles::directory(),
        'resource_list'=>static fn()=>Content::listing('resource'),
        'resource_search'=>static fn()=>Content::listing('resource',['q'=>'OPT_RARE_KNOWLEDGE_7319']),
        'matching_cold'=>static fn()=>Matching::recommendations(),
        'matching_warm'=>static fn()=>Matching::recommendations(),
        'notification_summary'=>static fn()=>Notifications::summary(),
        'statistics_cold'=>static function()use($admin){wp_set_current_user($admin->ID);StatisticsCache::invalidate();return Statistics::dashboard([]);},
        'statistics_warm'=>static fn()=>Statistics::dashboard([]),
    ];$rows=[];
    foreach ($operations as $name=>$callback) {
        wp_cache_flush();$wpdb->queries=[];$start=hrtime(true);$result=$callback();$ms=(hrtime(true)-$start)/1e6;
        $queries=$wpdb->queries;$fingerprint=[];
        if ($name==='directory'||str_starts_with($name,'resource_')) { $fingerprint=['ids'=>array_column($result['items'],'id'),'total'=>$result['total']]; }
        elseif(str_starts_with($name,'matching_')) { $fingerprint=['ids'=>array_column($result,'id'),'scores'=>array_column(array_column($result,'affinity'),'score')]; }
        elseif(str_starts_with($name,'statistics_')) { $fingerprint=['summary'=>$result['summary'],'community'=>$result['community']]; }
        else { $fingerprint=$result; }
        usort($queries,static fn($a,$b)=>$b[1]<=>$a[1]);
        $rows[]=['operation'=>$name,'ms'=>round($ms,3),'queries'=>count($queries),'sql_ms'=>round(array_sum(array_column($queries,1))*1000,3),'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2),'fingerprint'=>$fingerprint,'slowest_sql_ms'=>array_map(static fn($q)=>round($q[1]*1000,3),array_slice($queries,0,5))];
    }
    echo wp_json_encode(['environment'=>['wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'opcache_enabled'=>(bool)ini_get('opcache.enable'),'native_plugin_volume'=>true],'dataset'=>get_option('ascla_optimization_fixture')['dataset'],'measurements'=>$rows]);return;
}
exit(4);
