<?php
/** Disposable loopback-only mock for real WordPress workers and browser acceptance. Not shipped. */
if (PHP_SAPI!=='cli') { exit(1); }
require '/var/www/html/wp-load.php';
if (wp_get_environment_type()!=='local'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['127.0.0.1','localhost'],true)) { throw new RuntimeException('Disposable loopback WordPress required'); }
$file=WP_CONTENT_DIR.'/mu-plugins/ascla-supadata1110-fixture.php';
if (($argv[1]??'')==='stats') {
    $requests=(array)get_option('ascla_sd_fixture_requests',[]);
    echo wp_json_encode(['simulated_provider'=>true,'requests'=>$requests,'creates'=>count(array_filter($requests,static fn($url)=>str_contains($url,'?url='))),'polls'=>count(array_filter($requests,static fn($url)=>str_contains($url,'/job-fixture-long')))])."\n";exit;
}
if (in_array($argv[1]??'',['reset','cleanup'],true)) {
    $account=hash_hmac('sha256','fixture-supadata-local-only',wp_salt('auth'));
    foreach (['es','en'] as $lang) { delete_transient('ascla_sd_'.hash('sha256',$account.'|ASCLA_SD001|'.$lang)); }
    delete_transient('ascla_sd_pending_'.hash('sha256',$account.'|ASCLA_SD001'));delete_transient('ascla_sd_rate_'.$account);
    delete_option('ascla_sd_fixture_count');delete_option('ascla_sd_fixture_requests');
    if (($argv[1]??'')==='reset') { echo "Local transcript fixture reset.\n";exit; }
}
if (($argv[1]??'')==='cleanup') { if(is_file($file)){unlink($file);}delete_option('ascla_sd_fixture_count');delete_option('ascla_sd_fixture_requests');exit; }
if (is_file($file)) { throw new RuntimeException('Fixture already installed'); }
wp_mkdir_p(dirname($file));
$source= <<<'PHP'
<?php
if (wp_get_environment_type()!=='local') { return; }
add_filter('pre_http_request',static function($pre,$args,$url){
    if (str_contains($url,'youtube.com/')&&str_contains($url,'ASCLA_SD001')) { return ['response'=>['code'=>200],'body'=>'{"lengthSeconds":"7500"}','headers'=>[],'cookies'=>[]]; }
    if (!str_starts_with($url,'https://api.supadata.ai/v1/transcript')||($args['headers']['x-api-key']??'')!=='fixture-supadata-local-only') { return $pre; }
    $requests=(array)get_option('ascla_sd_fixture_requests',[]);$requests[]=$url;update_option('ascla_sd_fixture_requests',$requests,false);
    if (!str_contains($url,'/job-fixture-long')) { return ['response'=>['code'=>202],'body'=>'{"jobId":"job-fixture-long"}','headers'=>[],'cookies'=>[]]; }
    $count=(int)get_option('ascla_sd_fixture_count',0)+1;update_option('ascla_sd_fixture_count',$count,false);
    if ($count<2) { $data=['status'=>'active']; }
    else {
        $content=[];for($i=0;$i<1500;$i++){$content[]=['offset'=>$i*5000,'duration'=>5000,'text'=>'La junta supervisa los riesgos y las responsabilidades. '.str_repeat('Gobierno corporativo. ',5).($i===1499?'MARCADOR_FINAL_2_HORAS':'')];}
        $data=['status'=>'completed','content'=>$content,'lang'=>'es'];
    }
    return ['response'=>['code'=>200],'body'=>wp_json_encode($data),'headers'=>[],'cookies'=>[]];
},10,3);
PHP;
file_put_contents($file,$source);
delete_option('ascla_sd_fixture_count');delete_option('ascla_sd_fixture_requests');
delete_transient('ascla_yt_public_'.hash('sha256','abcdefghijk'));
echo "Local Supadata fixture installed.\n";
