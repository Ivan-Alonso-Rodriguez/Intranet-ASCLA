<?php
/** Local-only fixtures for tests/release103.cjs. Never run against production. */
require __DIR__.'/bootstrap.php';
use ASCLA\Core\Services\{Settings,TurnstileState};
use ASCLA\Core\Repositories\Store;
$stateFile=sys_get_temp_dir().'/ascla-release103-fixtures.json';
$mode=$argv[1]??'';
if ($mode==='setup') {
    if (file_exists($stateFile)) { throw new RuntimeException('Clean up the previous release103 fixtures first.'); }
    add_filter('pre_wp_mail','__return_true');
    $state=['settings'=>Settings::get(),'users'=>[],'month'=>get_option('ascla_micro_'.wp_date('Y-m')),'history'=>get_option('ascla_group_history',[])];
    foreach (['admin'=>'administrator','member'=>'ascla_member','executive'=>'ascla_executive','locked'=>'ascla_member'] as $name=>$role) {
        $login='browser103_'.$name.'_'.bin2hex(random_bytes(4));$password=wp_generate_password(28);
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'display_name'=>'000QA103 '.$name,'first_name'=>'QA103','last_name'=>$name,'role'=>$role]);
        if (is_wp_error($id)) { throw new RuntimeException('Could not create disposable fixture'); }
        $state['users'][$name]=['id'=>$id,'login'=>$login,'password'=>$password];
        update_user_meta($id,'_ascla_profile',['first_name'=>'QA103','last_name'=>$name,'position'=>'Analista','company'=>'ASCLA','directory'=>true,'networking'=>true,'microevents'=>true,'hidden'=>$name==='locked'?['position']:[]]);
    }
    wp_set_current_user($state['users']['admin']['id']);
    Settings::save(['turnstile_enabled'=>false,'micro_approval'=>true,'ai_provider'=>'mock']);
    file_put_contents($stateFile,wp_json_encode($state));
    echo wp_json_encode($state['users']);
} elseif ($mode==='cleanup') {
    if (!file_exists($stateFile)) { echo "No fixtures to clean.\n";exit; }
    $state=json_decode(file_get_contents($stateFile),true);
    wp_set_current_user($state['users']['admin']['id']);
    foreach ($state['users'] as $user) {
        foreach (get_posts(['post_type'=>'any','post_status'=>['publish','private','pending','draft','trash'],'author'=>$user['id'],'numberposts'=>-1]) as $post) {
            Store::delete('registrations',['event_id'=>$post->ID]);wp_delete_post($post->ID,true);
        }
        foreach (['jobs','notifications','relations'] as $table) { Store::delete($table,['user_id'=>$user['id']]); }
        TurnstileState::clearState('login',$user['login']);
        wp_delete_user($user['id']);
    }
    update_option('ascla_settings',$state['settings'],false);
    $key='ascla_micro_'.wp_date('Y-m');
    if ($state['month']===false) { delete_option($key); } else { update_option($key,$state['month'],false); }
    update_option('ascla_group_history',$state['history'],false);
    unlink($stateFile);echo "Release 1.10.3 fixtures cleaned.\n";
} else { throw new RuntimeException('Use setup or cleanup'); }
