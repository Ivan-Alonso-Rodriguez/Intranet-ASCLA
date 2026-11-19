<?php
/** Owned local actors and support history for the Contact/theme/access regression. */
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
use ASCLA\Core\Repositories\Store;
if (PHP_SAPI!=='cli' || wp_get_environment_type()!=='local' || getenv('ASCLA_E2E_EPHEMERAL')!=='1') { throw new RuntimeException('Disposable local WordPress required.'); }
$key='ascla_contact_layout108_fixture';$fixture=get_option($key);
if (getenv('ASCLA_FIXTURE_ACTION')==='cleanup') {
    if ($fixture) {
        $posts=get_posts(['post_type'=>'ascla_contact','post_status'=>'any','author__in'=>array_values($fixture['users']),'numberposts'=>-1,'fields'=>'ids']);
        foreach ($posts as $id) {
            foreach (Store::rows('notifications','kind IN (%s,%s,%s)',['support_request','support_received','support_update']) as $notice) {
                $context=json_decode($notice['context']??'{}',true);
                if (($context['type']??'')==='post' && (int)($context['id']??0)===$id) { Store::delete('deliveries',['delivery_key'=>'notice:'.$notice['id']]);Store::delete('notifications',['id'=>(int)$notice['id']]); }
            }
            wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);
        }
        foreach ($fixture['users'] as $id) {
            foreach (['deliveries','audit'] as $table) { Store::delete($table,['actor_id'=>$id]); }
            foreach (['notifications','user_interests','jobs'] as $table) { Store::delete($table,['user_id'=>$id]); }
            wp_delete_user($id);
        }
        delete_option($key);
    }
    echo wp_json_encode(['cleaned'=>true]);return;
}
if ($fixture) { throw new RuntimeException('Clean the preceding fixture first.'); }
$fixture=['users'=>[],'prefix'=>'contactqa_'.bin2hex(random_bytes(4))];$password=wp_generate_password(32);$actors=[];
update_option($key,$fixture,false);
foreach (['administrator','ascla_executive','ascla_moderator','ascla_member'] as $role) {
    $login=$fixture['prefix'].'_'.$role;
    $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$password,'role'=>$role]);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $fixture['users'][$role]=$id;update_option($key,$fixture,false);$actors[$role]=['login'=>$login,'password'=>$password];
    update_user_meta($id,'_ascla_profile',['first_name'=>'María','last_name'=>'Torres','position'=>'Analista','company'=>'Organización de prueba','directory'=>false]);
    update_user_meta($id,'_ascla_email_notifications',['connections'=>false,'messages'=>false,'events'=>false,'support'=>false]);
}
$id=wp_insert_post(['post_type'=>'ascla_contact','post_status'=>'private','post_author'=>$fixture['users']['ascla_member'],'post_title'=>'Actualización de mis datos profesionales','post_content'=>'Necesito actualizar mi cargo y organización.'],true);
if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
update_post_meta($id,'_ascla_support_assignee',$fixture['users']['administrator']);
update_post_meta($id,'_ascla',['description'=>'Perfil','request_version'=>2,'request_status'=>'resolved','request_history'=>[
    ['kind'=>'response','from'=>'open','to'=>'progress','at'=>gmdate('c'),'actor'=>'Equipo ASCLA','actor_id'=>$fixture['users']['administrator'],'response'=>'Estamos revisando tu solicitud.'],
    ['kind'=>'response','from'=>'progress','to'=>'resolved','at'=>gmdate('c'),'actor'=>'Equipo ASCLA','actor_id'=>$fixture['users']['administrator'],'response'=>'Tus datos se actualizaron correctamente.'],
]]);
echo wp_json_encode(['actors'=>$actors,'prefix'=>$fixture['prefix'],'request'=>$id]);
