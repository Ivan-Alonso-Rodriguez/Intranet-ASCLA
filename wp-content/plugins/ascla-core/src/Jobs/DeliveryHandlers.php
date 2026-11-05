<?php
namespace ASCLA\Core\Jobs;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\{Access,NotificationEmail};
use ASCLA\Core\Integrations\GoogleOAuth;

final class DeliveryHandlers
{
    public static function queueCalendar(int $id,bool $reactivated=false): bool
    {
        $post=get_post($id);
        if (!$post) { return false; }
        $queued=false;
        foreach ([absint(get_post_meta($id,'_ascla_google_organizer_user',true)),(int)$post->post_author,get_current_user_id()] as $user) {
            if (GoogleOAuth::connected($user)) { Deliveries::enqueue('calendar',['id'=>$id]);$queued=true;break; }
        }
        // Restore the organizer first, so members receive its invitation instead of duplicate copies.
        \ASCLA\Core\Services\EventCalendar::queuePersonalUpdates($id,$reactivated);
        return $queued;
    }
    public static function send(array $row,array $payload): bool
    {
        return match($row['kind']) {
            'cache'=>\ASCLA\Core\Repositories\CacheRecovery::apply($payload),
            'notification'=>DeliveryMail::identified(static fn()=>self::notification((int)$payload['id']),$row['delivery_key']),
            'calendar'=>!empty($payload['participation'])?\ASCLA\Core\Services\EventCalendar::deliver((int)$payload['id']):self::calendar((int)$payload['id']),
            'mail'=>self::mail($row,$payload),
            default=>throw new DeliveryException('Entrega no válida.'),
        };
    }
    private static function mail(array $row,array $payload): bool
    {
        if (strtotime($row['created_at'].' UTC')<time()-DAY_IN_SECONDS) { return false; }
        if (!DeliveryMail::send($payload,$row['delivery_key'])) { throw new DeliveryException('Correo pendiente de entrega.'); }
        return true;
    }
    private static function notification(int $id): bool
    {
        $notice=Store::one('notifications',$id);
        if (!$notice || !Access::member((int)$notice['user_id'])) { return false; }
        $account=get_userdata((int)$notice['user_id']);
        $context=json_decode($notice['context']??'',true)?:[];
        if (!$account || !is_email($account->user_email) || !self::currentOffer($notice,$context)) { return false; }
        if (!NotificationEmail::send((int)$notice['user_id'],$notice['kind'],$notice['label'],$notice['url'],$context)) { throw new DeliveryException('Correo pendiente de entrega.'); }
        return true;
    }
    private static function currentOffer(array $notice,array $context): bool
    {
        if ($notice['kind']!=='event_waitlist_available') { return true; }
        $event=(int)($context['id']??0);
        $registration=Store::rows('registrations',"event_id=%d AND user_id=%d AND status='offered'",[$event,(int)$notice['user_id']],'LIMIT 1')[0]??null;
        $deadline=$registration?\ASCLA\Core\Services\EventParticipation::deadline($event,(int)$registration['id']):null;
        return $deadline && strtotime($deadline)>time();
    }
    private static function calendar(int $id): bool
    {
        return Store::lock('event:'.$id,static function() use($id) {
            clean_post_cache($id);$post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
            if (!$post || $post->post_type!=='ascla_event') { return false; }
            // Reconcile the current state, so a delayed update never resurrects a cancelled event.
            if (!empty($meta['cancelled'])) { return !empty(GoogleOAuth::cancelOrganizerEvent($id)['available']); }
            $allowed=$post->post_status==='publish' && strtotime((string)($meta['end']??''))>time() && Access::member() && Access::canPublish();
            if ($allowed) { GoogleOAuth::syncOrganizerEvent($id,0,true); }
            return $allowed;
        });
    }
}
