<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** Review, priority registration and a retained state history for microevents. */
final class MicroLifecycle
{
    public static function defaults(array $meta): array
    {
        $settings=Settings::get();
        return $meta+['micro_min'=>$settings['micro_min'],'priority_hours'=>$settings['micro_priority_hours'],
            'registration_deadline'=>gmdate('c',strtotime($meta['start']??'+14 days')-DAY_IN_SECONDS),'reminder_hours'=>[24,1]];
    }
    public static function sanitize(array &$meta,array $input,int $id): void
    {
        if (empty($meta['micro'])) { return; }
        $meta=self::defaults($meta);
        if ($id && get_post_status($id)==='publish' && current_user_can('ascla_manage')) { $meta['micro_approved']=true; }
        foreach (['micro_min'=>[2,50],'priority_hours'=>[0,168]] as $key=>$range) {
            $value=$input[$key]??$meta[$key];
            Access::require(filter_var($value,FILTER_VALIDATE_INT)!==false && $value>=$range[0] && $value<=$range[1],'Parámetro de microeventos no válido.',400);
            $meta[$key]=(int)$value;
        }
        Access::require($meta['capacity']>=$meta['micro_min'],'El mínimo no puede superar los cupos.',400);
        if (isset($input['registration_deadline'])) {
            $meta['registration_deadline']=ContentMeta::eventDate($input['registration_deadline']);
        }
        Access::require(strtotime($meta['registration_deadline'])<=strtotime($meta['start']),'El plazo de inscripción debe terminar antes del inicio.',400);
    }
    public static function publicAccess(array $meta): bool
    {
        return !empty($meta['public_at']) && (int)$meta['public_at']<=time();
    }
    public static function canRegister(array $meta,int $user): bool
    {
        return self::publicAccess($meta) || in_array($user,array_map('intval',$meta['invitees']??[]),true);
    }
    public static function registration(int $id,array $meta,int $user,string $status): void
    {
        unset($id); // Kept for the event lifecycle callback contract.
        if (empty($meta['micro']) || in_array($status,['declined','cancelled'],true)) { return; }
        Access::require(self::canRegister($meta,$user),'La inscripción está reservada temporalmente a los miembros recomendados.',403);
        $deadline=strtotime($meta['registration_deadline']??$meta['start']);
        Access::require($deadline>time(),'El plazo de inscripción ha finalizado.',409);
    }
    public static function history(int $id,string $state,string $reason=''): void
    {
        $rows=(array)(get_post_meta($id,'_ascla_micro_history',true)?:[]);
        $last=$rows?end($rows):null;
        if ($last && $last['state']===$state && $reason==='') { return; }
        $rows[]=['state'=>$state,'reason'=>$reason,'actor'=>get_current_user_id(),'at'=>gmdate('c')];
        update_post_meta($id,'_ascla_micro_history',$rows);
        update_post_meta($id,'_ascla_micro_state',$state);
        Audit::record('microevent_state',$id,$state);
    }
    public static function review(int $id,string $decision,string $reason,array &$meta): void
    {
        Access::require(current_user_can('ascla_manage'),'La revisión del microevento requiere administración.',403);
        if ($decision==='approve') {
            $meta=self::defaults($meta);
            MicroEvents::validateInvitees($meta);
            Access::require(strtotime($meta['registration_deadline'])>time(),'Amplía el plazo de inscripción antes de aprobar.',400);
            if (empty($meta['invited'])) { $meta['public_at']=min(strtotime($meta['registration_deadline']),time()+(int)$meta['priority_hours']*HOUR_IN_SECONDS); }
            $meta['micro_approved']=true;self::history($id,'approved',$reason);
        } else { $meta['micro_approved']=false;self::history($id,$decision==='reject'?'denied':'cancelled',$reason); }
    }
    public static function publish(int $id): void
    {
        $meta=self::defaults((array)get_post_meta($id,'_ascla',true));
        if (empty($meta['public_at'])) { $meta['public_at']=min(strtotime($meta['registration_deadline']),time()+(int)$meta['priority_hours']*HOUR_IN_SECONDS); }
        update_post_meta($id,'_ascla',$meta);
        self::history($id,'published');self::schedule($id,$meta);
    }
    public static function schedule(int $id,array $meta): void
    {
        wp_clear_scheduled_hook('ascla_micro_tick',[$id]);
        foreach (['registration_deadline','end'] as $key) {
            $at=strtotime($meta[$key]??'');
            if ($at>time()) { wp_schedule_single_event($at,'ascla_micro_tick',[$id]); }
        }
        EventReminders::schedule($id,$meta);
    }
    public static function tick(int $id): void
    {
        Store::lock('event:'.$id,static function()use($id){
            $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
            if (!$post || $post->post_status!=='publish' || empty($meta['micro']) || !empty($meta['cancelled'])) { return; }
            $state='published';
            if (strtotime($meta['end']??'')<=time()) { $state='finished'; }
            elseif (strtotime($meta['registration_deadline']??$meta['start'])<=time() && Store::count('registrations',"event_id=%d AND status='accepted'",[$id])<(int)($meta['micro_min']??4)) { $state='insufficient'; }
            self::history($id,$state);
        });
    }
}
