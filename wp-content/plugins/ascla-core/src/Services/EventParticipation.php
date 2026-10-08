<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Repositories\WordPressWrites;

/** RSVP history and time-limited offers share the event lock with registrations. */
final class EventParticipation
{
    public static function changedSchedule(array $before,array $after): bool
    {
        foreach (['start','end','modality'] as $key) {
            if (($before[$key]??'')!==($after[$key]??'')) { return true; }
        }
        return false;
    }

    public static function validateChange(int $id,array $before,array &$after,array $input): void
    {
        $after['change_reason']=self::changedSchedule($before,$after)?trim(Access::text($input['change_reason']??'',1000)):($before['change_reason']??'');
        if ($id && get_post_status($id)==='publish' && !empty($before['micro']) && self::changedSchedule($before,$after)) {
            Access::require($after['change_reason']!=='','Indica el motivo de la reprogramación del microevento.',400);
        }
    }

    public static function reconfirm(int $id,array $before,array $after): void
    {
        if (!self::changedSchedule($before,$after)) { return; }
        Store::atomic('event:'.$id,static function()use($id,$before,$after){
            $rows=Store::rows('registrations',"event_id=%d AND status IN ('accepted','offered')",[$id],'ORDER BY id');
            $history=(array)(get_post_meta($id,'_ascla_rsvp_history',true)?:[]);
            $fields=array_flip(['start','end','modality']);
            $history[]=['at'=>gmdate('c'),'actor'=>get_current_user_id(),'reason'=>$after['change_reason']??'',
                'before'=>array_intersect_key($before,$fields),'after'=>array_intersect_key($after,$fields),'registrations'=>$rows];
            WordPressWrites::meta('post',$id,'_ascla_rsvp_history',$history);
            foreach ($rows as $row) { Store::update('registrations',['status'=>'reconfirm'],['id'=>(int)$row['id']]); }
            WordPressWrites::deleteMeta('post',$id,'_ascla_waitlist_offers');
            wp_clear_scheduled_hook('ascla_event_offers',[$id]);
        });
    }

    public static function offer(int $id,int $registration,array $meta): void
    {
        $hours=max(1,min(168,(int)($meta['offer_hours']??24)));
        $deadline=min(time()+$hours*HOUR_IN_SECONDS,strtotime(!empty($meta['micro'])?($meta['registration_deadline']??$meta['start']):$meta['end']));
        $offers=(array)(get_post_meta($id,'_ascla_waitlist_offers',true)?:[]);
        $offers[$registration]=$deadline;
        WordPressWrites::meta('post',$id,'_ascla_waitlist_offers',$offers);
        $history=(array)(get_post_meta($id,'_ascla_waitlist_offer_history',true)?:[]);
        $history[]=['registration_id'=>$registration,'offered_at'=>gmdate('c'),'expires_at'=>gmdate('c',$deadline)];
        WordPressWrites::meta('post',$id,'_ascla_waitlist_offer_history',$history);
        self::reschedule($id);
    }

    private static function reschedule(int $id): void
    {
        $offers=(array)(get_post_meta($id,'_ascla_waitlist_offers',true)?:[]);$deadlines=[];
        foreach (Store::rows('registrations',"event_id=%d AND status='offered'",[$id],'') as $row) {
            $deadline=(int)($offers[(int)$row['id']]??0);
            if ($deadline>time()) { $deadlines[]=$deadline; }
        }
        $next=wp_next_scheduled('ascla_event_offers',[$id]);$earliest=$deadlines?min($deadlines):0;
        if ((int)$next===$earliest) { return; }
        wp_clear_scheduled_hook('ascla_event_offers',[$id]);
        if ($earliest) { wp_schedule_single_event($earliest,'ascla_event_offers',[$id]); }
    }

    public static function expire(int $id,array $meta): void
    {
        $offers=(array)(get_post_meta($id,'_ascla_waitlist_offers',true)?:[]);
        foreach (Store::rows('registrations',"event_id=%d AND status='offered'",[$id],'ORDER BY id') as $row) {
            $rid=(int)$row['id'];
            if (!isset($offers[$rid])) { self::offer($id,$rid,$meta);continue; }
            if ((int)$offers[$rid]<=time()) { Store::update('registrations',['status'=>'expired'],['id'=>$rid]); }
        }
        self::reschedule($id);
    }

    public static function deadline(int $id,int $registration): ?string
    {
        $offers=(array)(get_post_meta($id,'_ascla_waitlist_offers',true)?:[]);
        return isset($offers[$registration])?gmdate('c',(int)$offers[$registration]):null;
    }
}
