<?php
/** Fault injection only in the disposable local acceptance database. */
require '/var/www/html/wp-load.php';
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { exit(1); }
$input=json_decode(stream_get_contents(STDIN),true);global $wpdb;
$trigger=$wpdb->prefix.'ascla_e2e_atomic_fault';
$clear=static function()use($wpdb,$trigger):void{
    foreach ([$trigger,$trigger.'_meta_insert',$trigger.'_meta_update'] as $name) { $wpdb->query("DROP TRIGGER IF EXISTS `$name`"); }
};
if (($input['action']??'')==='disable') { $clear();echo '{"disabled":true}';exit; }
$id=absint($input['user']??0);$user=get_userdata($id);
if (!$user || !str_starts_with($user->user_login,'atomic_')) { exit(2); }
if (in_array($input['action']??'',['seed-overlap','check-overlap'],true)) {
    $target=absint($input['post']??0);$store=\ASCLA\Core\Repositories\Store::class;
    $where=['user_id'=>$id,'target_id'=>$target,'kind'=>'like','reason'=>'atomic_overlap'];
    if ($input['action']==='seed-overlap') {
        $account=get_userdata($target);
        if (!$account || !str_starts_with($account->user_login,'atomic_created_')) { exit(2); }
        $store::insert('relations',$where+['created_at'=>current_time('mysql',true)]);echo '{"seeded":true}';exit;
    }
    $count=$store::count('relations','user_id=%d AND target_id=%d AND kind=%s AND reason=%s',array_values($where));
    $store::delete('relations',$where);echo wp_json_encode(['preserved'=>$count]);exit;
}
if (($input['action']??'')==='expire-offer') {
    $post=get_post(absint($input['post']??0));
    if (!$post || $post->post_type!=='ascla_event' || (int)$post->post_author!==$id) { exit(2); }
    $offers=(array)get_post_meta($post->ID,'_ascla_waitlist_offers',true);
    foreach ($offers as &$deadline) { $deadline=time()-1; }unset($deadline);
    update_post_meta($post->ID,'_ascla_waitlist_offers',$offers);
    echo '{"expired":true}';exit;
}
if (($input['action']??'')==='cleanup-contact') {
    foreach (get_posts(['post_type'=>'ascla_contact','post_status'=>'any','author'=>$id,'numberposts'=>-1]) as $post) {
        if (str_starts_with($post->post_title,'Cambio autorizado ')) { wp_delete_post($post->ID,true); }
    }
    echo '{"cleaned":true}';exit;
}
if (($input['action']??'')==='mail-count') {
    $email=(string)($input['email']??$user->user_email);
    if (!preg_match('/^atomic_[a-z0-9_]+@example\.invalid$/i',$email)) { exit(2); }
    $response=wp_remote_get('http://mailpit:8025/api/v1/search?query='.rawurlencode('to:'.$email));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) { exit(4); }
    $data=json_decode(wp_remote_retrieve_body($response),true);
    echo wp_json_encode(['count'=>count($data['messages']??[])]);exit;
}
$clear();
if (($input['mode']??'')==='metadata') {
    foreach (['insert','update'] as $operation) {
        $name=$trigger.'_meta_'.$operation;$operation=strtoupper($operation);
        $sql="CREATE TRIGGER `$name` BEFORE $operation ON `{$wpdb->postmeta}` FOR EACH ROW BEGIN IF NEW.meta_key='_ascla' AND (SELECT post_author FROM `{$wpdb->posts}` WHERE ID=NEW.post_id)=$id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ASCLA isolated metadata fault'; END IF; END";
        if ($wpdb->query($sql)===false) { $clear();exit(3); }
    }
    echo '{"enabled":true}';exit;
}
$table=\ASCLA\Core\Repositories\Store::table('audit');
$actions=($input['mode']??'')==='professional'?"'professional_profile_updated'":"'event_registration','connection_removed','member_blocked','comment_moderation','report_reviewed','report_deleted','moderation','content_saved','content_trashed','event_invited','event_cancelled','member_created','member_updated','member_deleted','contact_status','contact_archived','contact_assigned'";
$condition=($input['mode']??'')==='schedule'?"NEW.action='content_scheduled_published' AND (SELECT post_author FROM `{$wpdb->posts}` WHERE ID=NEW.object_id)=$id":"NEW.actor_id=$id AND NEW.action IN ($actions)";
$sql="CREATE TRIGGER `$trigger` BEFORE INSERT ON `$table` FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ASCLA isolated acceptance fault'; END IF; END";
if ($wpdb->query($sql)===false) { fwrite(STDERR,'Could not install local fault trigger');exit(3); }
echo '{"enabled":true}';
