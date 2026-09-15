<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Calendar;
final class Events
{
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
        $rows=Store::rows('registrations',"event_id=%d AND status='waitlisted'",[$id],'ORDER BY id ASC');
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
        $item['google_url']=($item['is_past']||$item['cancelled'])?'':Calendar::google($post->post_title,$meta,!empty($meta['chatham'])?'Sesión bajo la Regla de Chatham House.':$post->post_content);
        if (current_user_can('ascla_moderate')) {
            $item['participants']=array_map(static function ($r) { $u=get_userdata($r['user_id']); return ['id'=>(int)$r['user_id'],'name'=>$u?Profiles::publicName((int)$u->ID):'Miembro','status'=>$r['status']]; },Store::rows('registrations','event_id=%d',[$id],'ORDER BY id ASC'));
        }
        return $item;
    }

    public static function invite(int $id,array $users): array
    {
        Access::require(current_user_can('ascla_moderate'));
        Access::limit('event_invite',10,300);
        $post=Content::get($id); $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_event' && $post->post_status==='publish' && empty($meta['micro']) && empty($meta['cancelled']),'Seleccione un evento publicado y activo de la comunidad.',400);
        Access::require(strtotime($meta['end']??'')>time(),'El evento ya finalizó.',400);
        $users=array_values(array_unique(array_map('absint',$users)));
        Access::require(!empty($users) && count($users)<=50,'Seleccione entre 1 y 50 asociados.',400);
        foreach ($users as $uid) { Access::require(Access::member($uid),'Asociado no disponible.',400); }
        $sent=Store::lock('event:'.$id,static function () use($id,$users) {
            $sent=0;
            foreach ($users as $uid) {
                if (Store::count('registrations',self::REGISTRATION,[$id,$uid])) { continue; }
                Store::insert('registrations',['event_id'=>$id,'user_id'=>$uid,'status'=>'invited','created_at'=>current_time('mysql',true)]);
                if (Notifications::once($uid,'event:'.$id,'event','Te invitaron a un evento de ASCLA.',\ASCLA\Core\Domain\Catalog::url('eventos',['item'=>$id]),['type'=>'post','id'=>$id])) { $sent++; }
            }
            return $sent;
        });
        Audit::record('event_invited',$id,'sent_'.$sent);
        return ['sent'=>$sent,'skipped'=>count($users)-$sent,'message'=>'Invitaciones internas enviadas. Los cupos se confirman al aceptar.'];
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
            Access::require(strtotime((string)($meta['end']??''))>time(),'El evento ya finalizó.',400);
            $meta['cancelled']=true;
            $meta['cancelled_at']=current_time('mysql',true);
            $meta['cancelled_by']=get_current_user_id();
            update_post_meta($id,'_ascla',$meta);
            $users=[];
            foreach (Store::rows('registrations',"event_id=%d AND status IN ('accepted','offered','waitlisted','invited')",[$id],'ORDER BY id ASC') as $row) {
                $uid=absint($row['user_id']??0);
                if ($uid>0) $users[$uid]=true;
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
        Audit::record('event_cancelled',$id,'notified_'.count($users));
        return self::detail($id);
    }

    public static function register(int $id,string $status): array
    {
        Access::require(in_array($status,['accepted','declined','cancelled','waitlisted'],true),'Estado no válido.',400);
        $post=Content::get($id); Access::require($post->post_type==='ascla_event'&&$post->post_status==='publish','Evento no disponible.',400);
        $user=get_current_user_id();
        Store::lock('event:'.$id,static function () use($id,$status,$user) {
            $meta=(array)get_post_meta($id,'_ascla',true);
            Access::require(empty($meta['cancelled']),'El evento fue cancelado y ya no admite inscripciones.',409);
            Access::require(strtotime((string)($meta['end']??''))>time(),'El evento ya finalizó.',400);
            $existing=Store::rows('registrations',self::REGISTRATION,[$id,$user],'LIMIT 1')[0]??null;
            $before=(string)($existing['status']??'none');
            $capacity=self::capacity($meta);

            if ($status==='waitlisted') {
                Access::require($capacity>0,'Este evento no utiliza lista de espera porque no tiene límite de cupos.',400);
                Access::require(!in_array($before,['accepted','offered'],true),'Ya tienes un cupo disponible o confirmado.',409);
                if ($before==='waitlisted') { return; }
                // Reconcile older waiting entries first so nobody can skip the queue if a seat is already free.
                self::fillAvailableSlots($id,$meta);
                Access::require(self::reserved($id)>=$capacity,'Todavía hay cupos disponibles. Confirma tu asistencia directamente.',409);
                // A person who left the queue and joins again must return at the end, never recover an old position.
                if ($existing) { Store::delete('registrations',['id'=>(int)$existing['id']]); }
                Store::insert('registrations',['event_id'=>$id,'user_id'=>$user,'status'=>'waitlisted','created_at'=>current_time('mysql',true)]);
                return;
            }

            if ($status==='accepted') {
                if ($before==='accepted') { return; }
                if ($before==='offered') {
                    // The queue already reserved this seat for the current user.
                    Store::update('registrations',['status'=>'accepted'],['id'=>(int)$existing['id']]);
                    return;
                }
                self::fillAvailableSlots($id,$meta);
                if ($capacity>0) {
                    Access::require(self::reserved($id)<$capacity,'No quedan cupos disponibles. Puedes unirte a la lista de espera.',409);
                }
                if ($existing) { Store::update('registrations',['status'=>'accepted'],['id'=>(int)$existing['id']]); }
                else { Store::insert('registrations',['event_id'=>$id,'user_id'=>$user,'status'=>'accepted','created_at'=>current_time('mysql',true)]); }
                return;
            }

            if ($existing) { Store::update('registrations',['status'=>$status],['id'=>(int)$existing['id']]); }
            else { Store::insert('registrations',['event_id'=>$id,'user_id'=>$user,'status'=>$status,'created_at'=>current_time('mysql',true)]); }

            // Cancelling a confirmed seat or rejecting a reserved offer releases it to the oldest waiter.
            if (in_array($before,['accepted','offered'],true) && in_array($status,['declined','cancelled'],true)) {
                self::fillAvailableSlots($id,$meta);
            }
        });
        Audit::record('event_registration',$id,$status);
        return self::detail($id);
    }
}
