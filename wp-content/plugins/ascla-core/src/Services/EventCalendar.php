<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Integrations\GoogleOAuth;
use ASCLA\Core\Jobs\Deliveries;
use ASCLA\Core\Repositories\{Store,WordPressWrites};

/** Reconcile calendar delivery against current participation, including delayed retries. */
final class EventCalendar
{
    private static function organizer(int $id): int
    {
        $user=absint(get_post_meta($id,'_ascla_google_organizer_user',true));
        return $user && GoogleOAuth::connected($user) && get_post_meta($id,'_ascla_google_organizer_event',true)!==''?$user:0;
    }

    public static function queue(int $id): void
    {
        $user=get_current_user_id();
        if (!GoogleOAuth::connected($user) && !self::organizer($id)) { return; }
        $delivery=Deliveries::enqueue('calendar',['id'=>$id,'participation'=>true]);
        WordPressWrites::meta('user',$user,'_ascla_calendar_delivery_'.$id,$delivery);
    }

    /** Update existing personal copies when the organizer changes or cancels an event. */
    public static function queuePersonalUpdates(int $id,bool $includeAccepted=false): void
    {
        $original=get_current_user_id();
        try {
            foreach (Store::rows('registrations','event_id=%d',[$id],'ORDER BY id ASC') as $row) {
                $user=(int)$row['user_id'];
                $restore=$includeAccepted && $row['status']==='accepted' && GoogleOAuth::connected($user);
                if (!Access::member($user) || (!$restore && get_user_meta($user,'_ascla_google_event_'.$id,true)==='')) { continue; }
                wp_set_current_user($user);self::queue($id);
            }
        } finally { wp_set_current_user($original); }
    }

    public static function retry(int $id): array
    {
        $detail=Events::detail($id);
        Access::require($detail['registered']==='accepted' && !$detail['cancelled'] && !$detail['is_past'],'Confirma tu inscripción a un evento activo.',409);
        Access::limit('calendar_retry',5,60);
        Store::atomic('event:'.$id,static fn()=>self::queue($id));
        return Events::detail($id);
    }

    public static function status(int $id): string
    {
        $user=get_current_user_id();
        $delivery=Store::one('deliveries',absint(get_user_meta($user,'_ascla_calendar_delivery_'.$id,true)));
        if ($delivery && in_array($delivery['status'],['pending','processing','failed'],true)) {
            return $delivery['status']==='failed'?'failed':'pending';
        }
        if (self::organizer($id)===$user) { return 'organizer'; }
        if (get_user_meta($user,'_ascla_google_event_'.$id,true)!=='') { return 'synced'; }
        return (string)get_user_meta($user,'_ascla_calendar_status_'.$id,true);
    }

    public static function deliver(int $id): bool
    {
        return Store::lock('event:'.$id,static function()use($id) {
            $user=get_current_user_id();$post=get_post($id);
            if (!$post || $post->post_type!=='ascla_event' || !Access::member($user)) { return false; }
            $meta=(array)get_post_meta($id,'_ascla',true);
            $registration=Store::rows('registrations','event_id=%d AND user_id=%d',[$id,$user],'LIMIT 1')[0]??[];
            $active=$post->post_status==='publish' && empty($meta['cancelled']) && strtotime($meta['end']??'')>time();
            $organizer=self::organizer($id);
            if ($organizer && $active) {
                // A previously personal copy must not coexist with an organizer invitation.
                if ($organizer!==$user && GoogleOAuth::connected($user) && get_user_meta($user,'_ascla_google_event_'.$id,true)!=='') {
                    GoogleOAuth::calendar($id,'cancel');
                }
                GoogleOAuth::syncOrganizerEvent($id,$organizer,true);
                WordPressWrites::meta('user',$user,'_ascla_calendar_status_'.$id,'invited');
                return true;
            }
            if (!GoogleOAuth::connected($user) || $post->post_status!=='publish') { return false; }
            $save=$active && ($registration['status']??'')==='accepted';
            GoogleOAuth::calendar($id,$save?'save':'cancel');
            WordPressWrites::meta('user',$user,'_ascla_calendar_status_'.$id,$save?'synced':'');
            return true;
        });
    }
}
