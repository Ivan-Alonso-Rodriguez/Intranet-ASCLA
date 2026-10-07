<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;

final class EventReminders
{
    public static function sanitize(array &$meta,array $input,int $id): void
    {
        $old=(array)($id?get_post_meta($id,'_ascla',true):[]);$previous=$old['reminder_hours']??[24,1];
        $hours=$input['reminder_hours']??$meta['reminder_hours']??[24,1];
        Access::require(is_array($hours) && count($hours)>=1 && count($hours)<=5,'Indica entre uno y cinco recordatorios.',400);
        foreach ($hours as $hour) { Access::require(filter_var($hour,FILTER_VALIDATE_INT)!==false && $hour>=1 && $hour<=720,'Anticipación de recordatorio no válida.',400); }
        $hours=array_values(array_unique(array_map('intval',$hours)));rsort($hours);
        if ($hours!==$previous) {
            Access::require(current_user_can('ascla_manage'),'Solo administración puede configurar los recordatorios.',403);
            Access::require(empty($meta['micro']) || !$id || (empty($old['invited']) && get_post_status($id)!=='publish'),'Los recordatorios deben quedar fijados antes de publicar.',409);
        }
        $meta['reminder_hours']=$hours;
    }
    public static function cancel(int $id): void
    {
        foreach ((array)(get_post_meta($id,'_ascla_reminder_schedule',true)?:[]) as $hours) { wp_clear_scheduled_hook('ascla_event_reminder',[$id,(int)$hours]); }
    }
    public static function schedule(int $id,array $meta): void
    {
        self::cancel($id);
        $hours=(array)($meta['reminder_hours']??[24,1]);
        update_post_meta($id,'_ascla_reminder_schedule',$hours);
        if (get_post_status($id)!=='publish' || !empty($meta['cancelled']) || empty($meta['start'])) { return; }
        foreach ($hours as $hour) {
            $at=strtotime($meta['start'])-(int)$hour*HOUR_IN_SECONDS;
            if ($at>time()) { wp_schedule_single_event($at,'ascla_event_reminder',[$id,(int)$hour]); }
        }
    }
    public static function send(int $id,int $hours): void
    {
        $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
        if (!$post || $post->post_status!=='publish' || !empty($meta['cancelled']) || strtotime($meta['start']??'')<=time()) { return; }
        if (strtotime($meta['start'])-$hours*HOUR_IN_SECONDS>time()+60) { return; }
        foreach (Store::rows('registrations',"event_id=%d AND status='accepted'",[$id],'ORDER BY id') as $row) {
            $uid=(int)$row['user_id'];if (!Access::member($uid)) { continue; }
            Notifications::once($uid,'event-reminder:'.$id.':'.$meta['start'].':'.$hours,'event_reminder',
                'Recordatorio: “'.$post->post_title.'” comienza el '.wp_date('d/m/Y H:i',strtotime($meta['start'])).'.',
                Catalog::url('eventos',['item'=>$id]),['type'=>'post','id'=>$id]);
        }
    }
}
