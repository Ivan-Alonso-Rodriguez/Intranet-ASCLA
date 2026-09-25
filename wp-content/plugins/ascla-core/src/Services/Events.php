<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Calendar;
final class Events
{
    private const ORDER_BY_ID_ASC='ORDER BY id ASC';
    private const EVENT_FINISHED='El evento ya finalizó.';
    private const REGISTRATION='event_id=%d AND user_id=%d';

    private static function capacity(array $meta): int
    {
        return max(0,(int)($meta['capacity']??0));
    }

    private static function countStatus(int $id,string $status): int
    {
        return Store::count('registrations','event_id=%d AND status=%s',[$id,$status]);
    }

    private static function reserved(int $id): int
    {
        return Store::count('registrations',"event_id=%d AND status IN ('accepted','offered')",[$id]);
    }

    private static function waitlistPosition(int $id,int $user): int
    {
        $rows=Store::rows('registrations',"event_id=%d AND status='waitlisted'",[$id],self::ORDER_BY_ID_ASC);
        foreach ($rows as $index=>$row) {
            if ((int)$row['user_id']===$user) { return $index+1; }
        }
        return 0;
    }

    private static function notifyAvailable(int $id,int $user): void
    {
        Notifications::send(
            $user,
            'event_waitlist_available',
            'Se liberó un cupo para un evento de ASCLA. Confirma tu asistencia para reservarlo.',
            \ASCLA\Core\Domain\Catalog::url('eventos',['item'=>$id]),
            ['type'=>'post','id'=>$id]
        );
    }

    /** RF-031: expose only attendee information that the confirmed viewer is allowed to see. */
    private static function visibleAttendees(int $id,int $viewer): array
    {
        $items=[]; $hidden=0;
        foreach (Store::rows('registrations',"event_id=%d AND status='accepted'",[$id],self::ORDER_BY_ID_ASC) as $row) {
            $uid=absint($row['user_id']??0);
            if ($uid<=0 || !Access::member($uid)) { continue; }
            $profile=Profiles::raw($uid);
            $own=$uid===$viewer;
            // Leaving the directory is also a request not to be exposed in attendee discovery views.
            if (!$own && empty($profile['directory'])) { $hidden++; continue; }
            $hiddenFields=(array)($profile['hidden']??[]);
            foreach ($hiddenFields as $field) { unset($profile[$field]); }
            $name=$own?(string)($profile['name']??Profiles::publicName($uid)):Profiles::publicName($uid);
            $photo='';
            if (($own || !in_array('photo_id',$hiddenFields,true)) && !empty($profile['photo_id'])) {
                $photo=Media::profilePhotoUrl((int)$profile['photo_id'],$uid);
            }
            $items[]=[
                'id'=>$uid,
                'name'=>$name,
                'photo_url'=>$photo,
                'position'=>(string)($profile['position']??''),
                'company'=>(string)($profile['company']??''),
                'is_me'=>$own,
            ];
        }
        return ['items'=>$items,'hidden'=>$hidden];
    }

    /** Reserve every currently free seat for the oldest people waiting. */
    private static function fillAvailableSlots(int $id,array $meta): int
    {
        $capacity=self::capacity($meta);
        if ($capacity<=0) { return 0; }
        $offered=0;
        while (self::reserved($id)<$capacity) {
            $next=Store::rows('registrations',"event_id=%d AND status='waitlisted'",[$id],'ORDER BY id ASC LIMIT 1')[0]??null;
            if (!$next) { break; }
            Store::update('registrations',['status'=>'offered'],['id'=>(int)$next['id']]);
            self::notifyAvailable($id,(int)$next['user_id']);
            $offered++;
        }
        return $offered;
    }

    public static function detail(int $id): array
    {
        $post=Content::get($id); Access::require($post->post_type==='ascla_event','Evento no encontrado.',404);
        $meta=(array)get_post_meta($id,'_ascla',true); $item=Content::serialize($post);
        $item['cancelled']=!empty($meta['cancelled']);
        $end=strtotime((string)($meta['end']??'')); $item['is_past']=$end!==false && $end<=time();
        $registration=Store::rows('registrations',self::REGISTRATION,[$id,get_current_user_id()],'LIMIT 1')[0]??null;
        $item['registered']=$registration['status']??'none';
        $item['attending']=self::countStatus($id,'accepted');
        $item['capacity']=self::capacity($meta);
        $item['reserved']=$item['attending']+self::countStatus($id,'offered');
        $item['remaining']=$item['capacity']>0?max(0,$item['capacity']-$item['reserved']):null;
        $item['full']=$item['capacity']>0 && $item['reserved']>=$item['capacity'];
        $item['waitlist_count']=self::countStatus($id,'waitlisted');
        $item['waitlist_position']=$item['registered']==='waitlisted'?self::waitlistPosition($id,get_current_user_id()):0;
        $googleOrganizer=absint(get_post_meta($id,'_ascla_google_organizer_user',true));
        $googleOrganizerEvent=(string)get_post_meta($id,'_ascla_google_organizer_event',true);
        $item['google_managed']=$googleOrganizer===get_current_user_id() && $googleOrganizerEvent!=='';
        $item['google_synced']=$googleOrganizer>0 && $googleOrganizerEvent!=='';
        $item['google_url']='';
        if(!$item['is_past'] && !$item['cancelled']){
            $calendarDescription=!empty($meta['chatham'])?'Sesión bajo la Regla de Chatham House.':$post->post_content;
            $item['google_url']=Calendar::google($post->post_title,$meta,$calendarDescription);
        }
        if ($item['registered']==='accepted') {
            $attendees=self::visibleAttendees($id,get_current_user_id());
            $item['attendees']=$attendees['items'];
            $item['attendees_hidden']=$attendees['hidden'];
        }
        if (Access::canPublish()) {
            $item['participants']=array_map(static function ($r) { $u=get_userdata($r['user_id']); return ['id'=>(int)$r['user_id'],'name'=>$u?Profiles::publicName((int)$u->ID):'Miembro','status'=>$r['status']]; },Store::rows('registrations','event_id=%d',[$id],self::ORDER_BY_ID_ASC));
        }
        return $item;
    }

    public static function invite(int $id,array $users): array
    {
        Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador puede invitar asociados a eventos.',403);
        Access::limit('event_invite',10,300);
        $post=Content::get($id); $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_event' && $post->post_status==='publish' && empty($meta['micro']) && empty($meta['cancelled']),'Seleccione un evento publicado y activo de la comunidad.',400);
        Access::require(strtotime($meta['end']??'')>time(),self::EVENT_FINISHED,400);
        $users=array_values(array_unique(array_map('absint',$users)));
        Access::require(!empty($users) && count($users)<=50,'Seleccione entre 1 y 50 asociados.',400);
        foreach ($users as $uid) { Access::require(Access::member($uid),'Asociado no disponible.',400); }
        $invited=Store::lock('event:'.$id,static function () use($id,$users) {
            $invited=[];
            foreach ($users as $uid) {
                if (Store::count('registrations',self::REGISTRATION,[$id,$uid])) { continue; }
                Store::insert('registrations',['event_id'=>$id,'user_id'=>$uid,'status'=>'invited','created_at'=>current_time('mysql',true)]);
                Notifications::once($uid,'event:'.$id,'event','Te invitaron a un evento de ASCLA.',\ASCLA\Core\Domain\Catalog::url('eventos',['item'=>$id]),['type'=>'post','id'=>$id]);
                $invited[]=$uid;
            }
            return $invited;
        });
        $calendar=['available'=>false,'sent'=>0];
        if ($invited) {
            try { $calendar=\ASCLA\Core\Integrations\GoogleOAuth::inviteEvent($id,$invited); }
            catch (\Throwable $error) { $calendar=['available'=>false,'sent'=>0,'error'=>$error->getMessage()]; }
        }
        $sent=count($invited);
        Audit::record('event_invited',$id,'sent_'.$sent.'_google_'.(int)($calendar['sent']??0));
        $message='Invitaciones internas enviadas. Los cupos se confirman al aceptar.';
        if (($calendar['sent']??0)>0) { $message.=' Google Calendar envió las invitaciones por correo a '.(int)$calendar['sent'].' asociado(s).'; }
        elseif (!empty($calendar['error'])) { $message.=' Google Calendar no pudo enviar la invitación: '.$calendar['error']; }
        elseif ($sent>0 && empty($calendar['available'])) { $message.=' Para enviar invitaciones de Google Calendar, conecta el calendario de la cuenta organizadora.'; }
        return ['sent'=>$sent,'skipped'=>count($users)-$sent,'google_calendar'=>$calendar,'message'=>$message];
    }

    /** Notify registered/invited associates only when operational event data changed materially. */
    public static function notifyImportantChanges(int $id,array $before,array $after): int
    {
        $fields=[
            '__title'=>'title',
            'start'=>'start',
            'end'=>'end',
            'modality'=>'modality',
            'location'=>'location',
            'url'=>'url',
            'capacity'=>'capacity',
        ];
        $changed=[];
        foreach ($fields as $key=>$contextKey) {
            $left=is_scalar($before[$key]??null)?trim((string)$before[$key]):'';
            $right=is_scalar($after[$key]??null)?trim((string)$after[$key]):'';
            if ($left!==$right) { $changed[]=$contextKey; }
        }
        if (!$changed) { return 0; }
        $post=get_post($id);
        if (!$post || $post->post_type!=='ascla_event' || $post->post_status!=='publish' || !empty($after['cancelled'])) { return 0; }
        $users=[];
        foreach (Store::rows('registrations',"event_id=%d AND status IN ('accepted','offered','waitlisted','invited')",[$id],self::ORDER_BY_ID_ASC) as $row) {
            $uid=absint($row['user_id']??0);
            if ($uid>0 && $uid!==get_current_user_id() && Access::member($uid)) { $users[$uid]=true; }
        }
        foreach (array_keys($users) as $uid) {
            Notifications::send(
                $uid,
                'event_updated',
                'Se actualizaron datos importantes del evento “'.$post->post_title.'”.',
                \ASCLA\Core\Domain\Catalog::url('eventos',['item'=>$id]),
                ['type'=>'post','id'=>$id,'actor'=>get_current_user_id(),'changes'=>$changed]
            );
        }
        try { \ASCLA\Core\Integrations\GoogleOAuth::syncOrganizerEvent($id,0,true); }
        catch (\Throwable) { /* Google is complementary; the ASCLA update must remain saved. */ }
        return count($users);
    }

    /** RF-024: cancel an event without deleting its history, and notify affected associates. */
    public static function cancel(int $id): array
    {
        Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador puede cancelar eventos.',403);
        $post=Content::get($id);
        Access::require($post->post_type==='ascla_event' && $post->post_status==='publish','Evento no disponible.',400);
        $users=Store::lock('event:'.$id,static function () use($id) {
            $meta=(array)get_post_meta($id,'_ascla',true);
            Access::require(empty($meta['cancelled']),'El evento ya fue cancelado.',409);
            Access::require(strtotime((string)($meta['end']??''))>time(),self::EVENT_FINISHED,400);
            $meta['cancelled']=true;
            $meta['cancelled_at']=current_time('mysql',true);
            $meta['cancelled_by']=get_current_user_id();
            update_post_meta($id,'_ascla',$meta);
            $users=[];
            foreach (Store::rows('registrations',"event_id=%d AND status IN ('accepted','offered','waitlisted','invited')",[$id],self::ORDER_BY_ID_ASC) as $row) {
                $uid=absint($row['user_id']??0);
                if ($uid>0) { $users[$uid]=true; }
            }
            return array_keys($users);
        });
        foreach ($users as $uid) {
            Notifications::once(
                $uid,
                'event_cancelled:'.$id,
                'event_cancelled',
                'El evento “'.$post->post_title.'” fue cancelado.',
                \ASCLA\Core\Domain\Catalog::url('eventos',['item'=>$id]),
                ['type'=>'post','id'=>$id,'actor'=>get_current_user_id()]
            );
        }
        try { \ASCLA\Core\Integrations\GoogleOAuth::cancelOrganizerEvent($id); }
        catch (\Throwable) { /* Cancellation in ASCLA is authoritative even if Google is temporarily unavailable. */ }
        Audit::record('event_cancelled',$id,'notified_'.count($users));
        return self::detail($id);
    }

    /** Remove a deleted member from event participation and release any reserved seat fairly. */
    public static function removeMemberRegistrations(int $user): void
    {
        if($user<=0) { return; }
        $rows=Store::rows('registrations','user_id=%d',[$user],self::ORDER_BY_ID_ASC);
        $events=[];
        foreach($rows as $row) {
            $eventId=(int)$row['event_id'];
            $events[$eventId]=($events[$eventId]??false)||in_array((string)$row['status'],['accepted','offered'],true);
        }
        foreach($events as $eventId=>$releasedSeat) {
            Store::lock('event:'.$eventId,static function()use($eventId,$user,$releasedSeat){
                Store::delete('registrations',['event_id'=>$eventId,'user_id'=>$user]);
                if(!$releasedSeat) { return; }
                $post=get_post($eventId);
                $meta=(array)get_post_meta($eventId,'_ascla',true);
                if($post && $post->post_type==='ascla_event' && $post->post_status==='publish' && empty($meta['cancelled']) && strtotime((string)($meta['end']??''))>time()) {
                    self::fillAvailableSlots($eventId,$meta);
                }
            });
        }
    }

    private static function registerWaitlisted(int $id,int $user,array $meta,?array $existing,string $before,int $capacity): void
    {
        Access::require($capacity>0,'Este evento no utiliza lista de espera porque no tiene límite de cupos.',400);
        Access::require(!in_array($before,['accepted','offered'],true),'Ya tienes un cupo disponible o confirmado.',409);
        if ($before==='waitlisted') { return; }
        self::fillAvailableSlots($id,$meta);
        Access::require(self::reserved($id)>=$capacity,'Todavía hay cupos disponibles. Confirma tu asistencia directamente.',409);
        if ($existing) { Store::delete('registrations',['id'=>(int)$existing['id']]); }
        Store::insert('registrations',['event_id'=>$id,'user_id'=>$user,'status'=>'waitlisted','created_at'=>current_time('mysql',true)]);
    }

    private static function registerAccepted(int $id,int $user,array $meta,?array $existing,string $before,int $capacity): void
    {
        if ($before==='accepted') { return; }
        if ($before==='offered') { Store::update('registrations',['status'=>'accepted'],['id'=>(int)$existing['id']]);return; }
        self::fillAvailableSlots($id,$meta);
        if ($capacity>0) { Access::require(self::reserved($id)<$capacity,'No quedan cupos disponibles. Puedes unirte a la lista de espera.',409); }
        if ($existing) { Store::update('registrations',['status'=>'accepted'],['id'=>(int)$existing['id']]); }
        else { Store::insert('registrations',['event_id'=>$id,'user_id'=>$user,'status'=>'accepted','created_at'=>current_time('mysql',true)]); }
    }

    private static function registerOther(int $id,int $user,array $meta,?array $existing,string $before,string $status): void
    {
        if ($existing) { Store::update('registrations',['status'=>$status],['id'=>(int)$existing['id']]); }
        else { Store::insert('registrations',['event_id'=>$id,'user_id'=>$user,'status'=>$status,'created_at'=>current_time('mysql',true)]); }
        if (in_array($before,['accepted','offered'],true) && in_array($status,['declined','cancelled'],true)) { self::fillAvailableSlots($id,$meta); }
    }

    private static function registerLocked(int $id,string $status,int $user): void
    {
        $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require(empty($meta['cancelled']),'El evento fue cancelado y ya no admite inscripciones.',409);
        Access::require(strtotime((string)($meta['end']??''))>time(),self::EVENT_FINISHED,400);
        $existing=Store::rows('registrations',self::REGISTRATION,[$id,$user],'LIMIT 1')[0]??null;$before=(string)($existing['status']??'none');$capacity=self::capacity($meta);
        if ($status==='waitlisted') { self::registerWaitlisted($id,$user,$meta,$existing,$before,$capacity);return; }
        if ($status==='accepted') { self::registerAccepted($id,$user,$meta,$existing,$before,$capacity);return; }
        self::registerOther($id,$user,$meta,$existing,$before,$status);
    }

    public static function register(int $id,string $status): array
    {
        Access::require(in_array($status,['accepted','declined','cancelled','waitlisted'],true),'Estado no válido.',400);
        $post=Content::get($id);Access::require($post->post_type==='ascla_event'&&$post->post_status==='publish','Evento no disponible.',400);$user=get_current_user_id();
        Store::lock('event:'.$id,static fn()=>self::registerLocked($id,$status,$user));
        Audit::record('event_registration',$id,$status);
        return self::detail($id);
    }

}
