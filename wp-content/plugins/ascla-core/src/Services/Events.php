<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Calendar;
final class Events
{
    public static function detail(int $id): array
    {
        $post=Content::get($id); Access::require($post->post_type==='ascla_event','Evento no encontrado.',404);
        $meta=(array)get_post_meta($id,'_ascla',true); $item=Content::serialize($post);
        $item['registered']=Store::rows('registrations','event_id=%d AND user_id=%d',[$id,get_current_user_id()],'LIMIT 1')[0]['status']??'none';
        $item['attending']=Store::count('registrations',"event_id=%d AND status='accepted'",[$id]);
        $item['google_url']=Calendar::google($post->post_title,$meta,!empty($meta['chatham'])?'Sesión bajo la Regla de Chatham House.':$post->post_content);
        $item['ics']=Calendar::ics($id,$post->post_title,$meta,'Evento privado ASCLA.',wp_parse_url(home_url(),PHP_URL_HOST));
        if (current_user_can('ascla_moderate')) {
            $item['participants']=array_map(static function ($r) { $u=get_userdata($r['user_id']); return ['id'=>(int)$r['user_id'],'name'=>$u?$u->display_name:'Miembro','status'=>$r['status']]; },Store::rows('registrations','event_id=%d',[$id]));
        }
        return $item;
    }
    public static function register(int $id,string $status): array
    {
        Access::require(in_array($status,['accepted','declined','cancelled'],true),'Estado no válido.',400);
        $post=Content::get($id); Access::require($post->post_type==='ascla_event'&&$post->post_status==='publish','Evento no disponible.',400);
        $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require(strtotime($meta['end'])>time(),'El evento ya finalizó.',400);
        Store::lock('event:'.$id,static function () use($id,$meta,$status) {
            $existing=Store::rows('registrations','event_id=%d AND user_id=%d',[$id,get_current_user_id()],'LIMIT 1')[0]??null;
            if ($status==='accepted' && ($existing['status']??'')!=='accepted' && !empty($meta['capacity'])) {
                Access::require(Store::count('registrations',"event_id=%d AND status='accepted'",[$id])<(int)$meta['capacity'],'No quedan cupos disponibles.',409);
            }
            if ($existing) { Store::update('registrations',['status'=>$status],['id'=>$existing['id']]); }
            else { Store::insert('registrations',['event_id'=>$id,'user_id'=>get_current_user_id(),'status'=>$status,'created_at'=>current_time('mysql',true)]); }
        });
        Audit::record('event_registration',$id,$status); return self::detail($id);
    }
}
