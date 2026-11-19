<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\{Store,WordPressWrites};
use ASCLA\Core\Jobs\DeliveryHandlers;

/** Reopen a cancelled event under the same lock used by RSVP and cancellation. */
final class EventReactivation
{
    public static function run(int $id): array
    {
        Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador puede reactivar eventos.',403);
        Store::atomic('event:'.$id,static function()use($id){
            clean_post_cache($id);
            $post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
            $micro=!empty($meta['micro']);$published=$post->post_status==='publish';
            Access::require($post->post_type==='ascla_event' && ($published || ($micro && $post->post_status==='ascla_hidden')),'Evento no disponible.',400);
            Access::require(!$micro || current_user_can('ascla_manage'),'Solo administración puede reactivar microeventos.',403);
            Access::require(!empty($meta['cancelled']),'El evento ya está activo.',409);
            Access::require(strtotime($meta['end']??'')>time(),'Actualiza la fecha del evento antes de reactivarlo: ya finalizó.',400);
            Access::require(!$micro || strtotime($meta['registration_deadline']??$meta['start']??'')>time(),'Amplía el plazo de inscripción antes de reactivar el microevento.',400);
            self::retainHistory($id,$meta);
            $meta['cancelled']=false;
            unset($meta['cancellation_reason'],$meta['cancelled_at'],$meta['cancelled_by']);
            if ($micro) { $meta['micro_approved']=$published; }
            WordPressWrites::meta('post',$id,'_ascla',$meta);
            if (!$published) {
                // A cancelled proposal returns to review, never directly to publication.
                WordPressWrites::post(['ID'=>$id,'post_status'=>'draft']);
                MicroLifecycle::history($id,'pending','Propuesta reactivada para revisión.');
            } else {
                self::resume($id,$post,$meta);
            }
            Audit::record('event_reactivated',$id,$published?'registrations_preserved':'draft_for_review');
        });
        return Events::detail($id);
    }

    private static function retainHistory(int $id,array $meta): void
    {
        $history=(array)(get_post_meta($id,'_ascla_event_reactivations',true)?:[]);
        $history[]=[
            'cancelled_at'=>$meta['cancelled_at']??null,'cancelled_by'=>$meta['cancelled_by']??null,
            'cancellation_reason'=>$meta['cancellation_reason']??'',
            'reactivated_at'=>gmdate('c'),'reactivated_by'=>get_current_user_id(),
        ];
        WordPressWrites::meta('post',$id,'_ascla_event_reactivations',$history);
        WordPressWrites::meta('post',$id,'_ascla_event_reactivation_count',1+(int)get_post_meta($id,'_ascla_event_reactivation_count',true));
    }

    private static function resume(int $id,\WP_Post $post,array $meta): void
    {
        $before=(array)get_post_meta($id,'_ascla_cancelled_schedule',true);
        if ($before) { EventParticipation::reconfirm($id,$before,$meta); }
        // Cancellation pauses offers; give these members a fresh confirmation window.
        foreach (Store::rows('registrations',"event_id=%d AND status='offered'",[$id],'ORDER BY id ASC') as $row) {
            EventParticipation::offer($id,(int)$row['id'],$meta);
        }
        Events::refreshWaitlist($id);
        EventReminders::schedule($id,$meta);
        if (!empty($meta['micro'])) {
            MicroLifecycle::history($id,'published','Microevento reactivado.');
            MicroLifecycle::schedule($id,$meta);
        }
        foreach (Store::rows('registrations',"event_id=%d AND status IN ('accepted','offered','waitlisted','invited','reconfirm')",[$id],'ORDER BY id ASC') as $row) {
            Notifications::send((int)$row['user_id'],'event_reactivated',
                'El evento “'.$post->post_title.'” vuelve a estar activo. Revisa la fecha y el estado de tu inscripción.',
                \ASCLA\Core\Domain\Catalog::url('eventos',['item'=>$id]),
                ['type'=>'post','id'=>$id,'actor'=>get_current_user_id()]);
        }
        DeliveryHandlers::queueCalendar($id,true);
    }
}
