<?php
// Isolated, reversible volume benchmark against the bundled local database.
define('SAVEQUERIES',true);
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local') { throw new RuntimeException('Local environment required'); }
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Services\{Content,Profiles,Matching,Knowledge};
$member=get_user_by('login','demo.asociado');wp_set_current_user($member->ID);
$posts=[];$users=[];$results=[];$run=bin2hex(random_bytes(6));$oldRevision=get_option('ascla_profile_revision',0);
function measureVolume(string $name,callable $callback): array {
    global $wpdb;$wpdb->queries=[];wp_cache_flush();$start=hrtime(true);$value=$callback();$elapsed=(hrtime(true)-$start)/1e6;
    $times=array_column($wpdb->queries,1);
    return ['operation'=>$name,'ms'=>round($elapsed,2),'queries'=>count($times),'sql_ms'=>round(array_sum($times)*1000,2),'max_query_ms'=>round(($times?max($times):0)*1000,2),'slow_queries_over_100ms'=>count(array_filter($times,static fn($s)=>$s>.1)),'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2),'returned'=>is_array($value)?count($value['items']??$value['sources']??$value):null];
}
try {
    foreach ([[0,0],[100,30],[600,120]] as [$postCount,$userCount]) {
        while(count($posts)<$postCount){$n=count($posts);$id=wp_insert_post(['post_type'=>'ascla_resource','post_title'=>'Volume '.$run.' '.$n,'post_content'=>$n===0?'BENCH_GOV_RARE_8349 documenta la supervisión y seguimiento de riesgos.':str_repeat('El directorio comparte aprendizajes sobre responsabilidad y cumplimiento. ',30),'post_status'=>'publish','post_author'=>$member->ID]);$posts[]=$id;update_post_meta($id,'_ascla',['resource_type'=>$n%2?'Documento':'Artículo','chatham'=>false]);}
        while(count($users)<$userCount){$n=count($users);$id=wp_insert_user(['user_login'=>'volume.'.$run.'.'.$n,'user_pass'=>wp_generate_password(40),'user_email'=>'volume.'.$run.'.'.$n.'@example.invalid','display_name'=>'Volume Ficticio '.$n,'role'=>'ascla_member']);if(is_wp_error($id))throw new RuntimeException('Cannot create volume fixture');$users[]=$id;$profile=Profiles::raw($member->ID);unset($profile['id'],$profile['name'],$profile['photo_id']);$profile['first_name']='Volume';$profile['last_name']='Ficticio '.$n;$profile['directory']=true;$profile['networking']=true;update_user_meta($id,'_ascla_profile',$profile);}
        $measurements=[];
        foreach(['directory'=>static fn()=>Profiles::directory(),'resources'=>static fn()=>Content::listing('resource',['resource_type'=>'Documento']),'matching_cold'=>static fn()=>Matching::recommendations(),'matching_warm'=>static fn()=>Matching::recommendations(),'knowledge_old_source'=>static fn()=>Knowledge::answer('BENCH_GOV_RARE_8349')] as $name=>$callback){$measurements[]=measureVolume($name,$callback);}
        $results[]=['extra_resources'=>$postCount,'extra_members'=>$userCount,'measurements'=>$measurements];
    }
    echo wp_json_encode(['date'=>gmdate('c'),'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'scope'=>'Service calls; SAVEQUERIES; cold object cache each operation; SQL >100 ms is slow; same PHP process peak is cumulative','profiles'=>$results],JSON_PRETTY_PRINT)."\n";
} finally {
    foreach($posts as $id)wp_delete_post($id,true);
    foreach($users as $id)wp_delete_user($id);
    update_option('ascla_profile_revision',$oldRevision,false);
    wp_set_current_user(0);
}
