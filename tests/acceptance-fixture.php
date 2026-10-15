<?php
/** Synthetic fixtures for CLI or the separate administrative QA helper; never shipped in ASCLA Core. */
if (PHP_SAPI!=='cli' && (!defined('ASCLA_QA_WEB_SETUP') || ASCLA_QA_WEB_SETUP!==true)) { http_response_code(403);exit; }
if (!defined('ABSPATH')) { require '/var/www/html/wp-load.php'; }
require_once __DIR__.'/profile-fixture.php';
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\Profiles;

$web=defined('ASCLA_QA_WEB_SETUP') && ASCLA_QA_WEB_SETUP===true && current_user_can('manage_options');
if (!$web && (PHP_SAPI!=='cli' || getenv('ASCLA_ACCEPTANCE_ENV')!=='qa')) { throw new RuntimeException('Explicit QA acceptance environment required.'); }
$key='ascla_acceptance_fixture';
$run=$web?ASCLA_QA_RUN:(getenv('ASCLA_ACCEPTANCE_RUN')?:'manual');
if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$run)) { throw new RuntimeException('Invalid acceptance run.'); }
if (($web?ASCLA_QA_ACTION:getenv('ASCLA_FIXTURE_ACTION'))==='cleanup') {
    $fixture=get_option($key);if (!$fixture) { echo "No acceptance fixtures.\n";return; }
    if (($fixture['run']??'manual')!==$run) { echo "No fixtures owned by this run.\n";return; }
    $ids=array_column($fixture['actors'],'id');
    foreach ($ids as $id) { $u=get_userdata($id);if (!$u || !str_starts_with($u->user_login,$fixture['prefix'])) { throw new RuntimeException('Unexpected fixture owner.'); } }
    foreach (get_posts(['post_type'=>array_map(static fn($type)=>'ascla_'.$type,array_keys(ASCLA\Core\Domain\Catalog::TYPES)),'post_status'=>'any','author__in'=>$ids,'numberposts'=>-1]) as $post) {
        foreach (['registrations','attendance'] as $table) { Store::delete($table,['event_id'=>$post->ID]); }
        wp_delete_post($post->ID,true);
    }
    foreach (Store::rows('conversations','created_by IN ('.implode(',',array_map('intval',$ids)).')',[],'') as $conversation) {
        foreach (['messages','participants'] as $table) { Store::delete($table,['conversation_id'=>$conversation['id']]); }
        Store::delete('conversations',['id'=>$conversation['id']]);
    }
    foreach ($ids as $id) {
        foreach (['relations'=>'user_id','notifications'=>'user_id','audit'=>'actor_id','jobs'=>'user_id'] as $table=>$column) { Store::delete($table,[$column=>$id]); }
        Store::delete('relations',['target_id'=>$id]);wp_delete_user($id);
    }
    delete_option($key);echo "Acceptance fixtures removed.\n";return;
}
if (get_option($key)) { throw new RuntimeException('Previous acceptance fixtures need cleanup.'); }
$prefix='ascla_qa_'.bin2hex(random_bytes(5)).'_';$password=wp_generate_password(32,false);$actors=[];
// Only these synthetic accounts participate. The journal omits their temporary password.
$journal=['run'=>$run,'prefix'=>$prefix,'actors'=>[]];
if (!add_option($key,$journal,'','no')) { throw new RuntimeException('Another acceptance run is active.'); }
foreach (['admin'=>'administrator','a'=>'ascla_member','b'=>'ascla_member','ui_a'=>'ascla_member','ui_b'=>'ascla_member'] as $name=>$role) {
    $login=$prefix.$name;$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role,'display_name'=>'QA '.$name.' '.$prefix]);
    if (is_wp_error($id)) { throw new RuntimeException('Could not create test account.'); }
    $actors[$name]=['id'=>$id,'login'=>$login,'password'=>$password];
    $journal['actors'][$name]=['id'=>$id];update_option($key,$journal,false);
    wp_set_current_user($id);Profiles::save(ascla_test_profile(['first_name'=>'QA','last_name'=>$name.' '.$prefix,'networking'=>true,'directory'=>true]));
    update_user_meta($id,'locale','es_ES');
}
echo wp_json_encode(['base'=>home_url(),'prefix'=>$prefix,'actors'=>$actors]).PHP_EOL;
