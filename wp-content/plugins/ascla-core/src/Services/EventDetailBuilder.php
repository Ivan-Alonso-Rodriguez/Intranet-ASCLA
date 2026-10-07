<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Calendar;
use ASCLA\Core\Repositories\Store;

/** Build viewer-specific event details without increasing Events orchestration complexity. */
final class EventDetailBuilder
{
    private const ORDER_BY_ID_ASC='ORDER BY id ASC';
    private const REGISTRATION='event_id=%d AND user_id=%d';
    private const REGISTRATION_STATUS='event_id=%d AND status=%s';

    public static function apply(array &$item,int $id,\WP_Post $post,array $meta): void
    {
        self::registration($item,$id,$meta);
        self::calendar($item,$post,$meta);
        self::audience($item,$id);
    }

    private static function registration(array &$item,int $id,array $meta): void
    {
        $registration=Store::rows('registrations',self::REGISTRATION,[$id,get_current_user_id()],'LIMIT 1')[0]??null;
        $item['registered']=$registration['status']??'none';
        $item['offer_expires_at']=$item['registered']==='offered'?EventParticipation::deadline($id,(int)$registration['id']):null;
        $item['attending']=Store::count('registrations',self::REGISTRATION_STATUS,[$id,'accepted']);
        $item['capacity']=max(0,(int)($meta['capacity']??0));
        $item['reserved']=$item['attending']+Store::count('registrations',self::REGISTRATION_STATUS,[$id,'offered']);
        $item['remaining']=$item['capacity']>0?max(0,$item['capacity']-$item['reserved']):null;
        $item['full']=$item['capacity']>0 && $item['reserved']>=$item['capacity'];
        $item['waitlist_count']=Store::count('registrations',self::REGISTRATION_STATUS,[$id,'waitlisted']);
        $item['waitlist_position']=$item['registered']==='waitlisted'?self::waitlistPosition($id,get_current_user_id()):0;
    }

    private static function waitlistPosition(int $id,int $user): int
    {
        foreach (Store::rows('registrations',"event_id=%d AND status='waitlisted'",[$id],self::ORDER_BY_ID_ASC) as $index=>$row) {
            if ((int)$row['user_id']===$user) { return $index+1; }
        }
        return 0;
    }

    private static function calendar(array &$item,\WP_Post $post,array $meta): void
    {
        $organizer=absint(get_post_meta($post->ID,'_ascla_google_organizer_user',true));
        $organizerEvent=(string)get_post_meta($post->ID,'_ascla_google_organizer_event',true);
        $item['google_managed']=$organizer===get_current_user_id() && $organizerEvent!=='';
        $item['google_synced']=$organizer>0 && $organizerEvent!=='';
        $item['google_url']='';
        if(!$item['is_past'] && !$item['cancelled']){
            $description=!empty($meta['chatham'])?'Sesión bajo la Regla de Chatham House.':$post->post_content;
            $item['google_url']=Calendar::google($post->post_title,$meta,$description);
        }
    }

    private static function audience(array &$item,int $id): void
    {
        if ($item['registered']==='accepted') {
            $attendees=self::visibleAttendees($id,get_current_user_id());
            $item['attendees']=$attendees['items'];
            $item['attendees_hidden']=$attendees['hidden'];
        }
        if (Access::canPublish()) {
            $item['participants']=array_map(static function ($row) {
                $user=get_userdata($row['user_id']);
                return ['id'=>(int)$row['user_id'],'name'=>$user?Profiles::publicName((int)$user->ID):'Miembro','status'=>$row['status']];
            },Store::rows('registrations','event_id=%d',[$id],self::ORDER_BY_ID_ASC));
        }
    }

    /** RF-031: expose only attendee information that the confirmed viewer is allowed to see. */
    private static function visibleAttendees(int $id,int $viewer): array
    {
        $items=[];$hidden=0;
        foreach (Store::rows('registrations',"event_id=%d AND status='accepted'",[$id],self::ORDER_BY_ID_ASC) as $row) {
            $entry=self::visibleAttendee($row,$viewer);
            if ($entry===false) { $hidden++;continue; }
            if ($entry!==null) { $items[]=$entry; }
        }
        return ['items'=>$items,'hidden'=>$hidden];
    }

    private static function visibleAttendee(array $row,int $viewer): array|false|null
    {
        $uid=absint($row['user_id']??0);
        if ($uid<=0 || !Access::member($uid)) { return null; }
        $profile=Profiles::raw($uid);
        $own=$uid===$viewer;
        // Leaving the directory is also a request not to be exposed in attendee discovery views.
        if (!$own && (empty($profile['directory']) || Messaging::blocked($viewer,$uid))) { return false; }
        $hiddenFields=(array)($profile['hidden']??[]);
        foreach ($hiddenFields as $field) { unset($profile[$field]); }
        $photo='';
        if (($own || !in_array('photo_id',$hiddenFields,true)) && !empty($profile['photo_id'])) {
            $photo=Media::profilePhotoUrl((int)$profile['photo_id'],$uid);
        }
        return [
            'id'=>$uid,
            'name'=>$own?(string)($profile['name']??Profiles::publicName($uid)):Profiles::publicName($uid),
            'photo_url'=>$photo,
            'position'=>(string)($profile['position']??''),
            'company'=>(string)($profile['company']??''),
            'is_me'=>$own,
        ];
    }
}
