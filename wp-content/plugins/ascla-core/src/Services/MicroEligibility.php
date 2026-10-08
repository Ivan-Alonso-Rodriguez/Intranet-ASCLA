<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Repositories\WordPressWrites;

/** Pending proposals must still have a consenting, eligible group before publication. */
final class MicroEligibility
{
    public static function boot(): void
    {
        add_action('init',[self::class,'schedule'],22);
        add_action('ascla_micro_candidates',[self::class,'refresh']);
        add_action('ascla_micro_candidate_changed',[self::class,'refresh']);
        add_action('added_user_meta',[self::class,'changed'],10,4);
        add_action('updated_user_meta',[self::class,'changed'],10,4);
        add_action('deleted_user',[self::class,'refresh']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled('ascla_micro_candidates')) { wp_schedule_event(time()+60,'hourly','ascla_micro_candidates'); }
    }

    public static function changed(int $metaId,int $user,string $key,mixed $value): void
    {
        unset($metaId,$value);
        if (!in_array($key,['_ascla_profile','_ascla_suspended','_ascla_membership_status','_ascla_membership_until'],true)) { return; }
        if (!wp_next_scheduled('ascla_micro_candidate_changed',[$user])) { wp_schedule_single_event(time()+1,'ascla_micro_candidate_changed',[$user]); }
    }

    public static function refresh(int $user=0): void
    {
        $args=['post_type'=>'ascla_event','post_status'=>['draft','pending','ascla_hidden'],'numberposts'=>-1,
            'meta_query'=>[['key'=>'_ascla_micro','value'=>'1']]];
        if ($user) { $args['meta_query'][]=['key'=>'_ascla_invitee','value'=>$user]; }
        foreach (get_posts($args) as $post) {
            Store::atomic('event:'.$post->ID,static fn()=>self::proposal($post->ID));
        }
    }

    private static function candidates(array $ids): array
    {
        $eligible=[];
        foreach (array_unique(array_map('absint',$ids)) as $id) {
            if (!Access::member($id)) { continue; }
            $profile=Profiles::raw($id);
            if (empty($profile['microevents']) || ProfileRequirements::missing($profile)) { continue; }
            $blocked=false;
            foreach ($eligible as $other) { if (Messaging::blocked($id,$other)) { $blocked=true;break; } }
            if (!$blocked) { $eligible[]=$id; }
        }
        return $eligible;
    }

    private static function proposal(int $id): void
    {
        $meta=(array)get_post_meta($id,'_ascla',true);
        if (empty($meta['micro']) || !empty($meta['invited']) || !empty($meta['cancelled']) || get_post_status($id)==='publish') { return; }
        $before=array_values(array_unique(array_map('absint',$meta['invitees']??[])));
        $eligible=self::candidates($before);
        if ($before===$eligible && count($eligible)>=(int)($meta['micro_min']??Settings::get()['micro_min'])) { return; }
        $insufficient=count($eligible)<(int)($meta['micro_min']??Settings::get()['micro_min']);
        $reason=$insufficient?'La propuesta ya no alcanza el mínimo de candidatos elegibles.':'Cambió la lista de candidatos elegibles. Requiere nueva revisión.';
        $meta['invitees']=$eligible;$meta['micro_approved']=false;
        unset($meta['public_at'],$meta['publish_at'],$meta['publish_actor_id'],$meta['schedule_error']);
        if ($insufficient) { $meta['cancelled']=true;$meta['cancellation_reason']=$reason; }
        WordPressWrites::meta('post',$id,'_ascla',$meta);
        ContentSchedule::cancel($id);
        WordPressWrites::post(['ID'=>$id,'post_status'=>$insufficient?'ascla_hidden':'pending']);
        MicroLifecycle::history($id,$insufficient?'cancelled':'pending',$reason);
        Audit::changes('microevent_candidates_updated',$id,['invitees'=>$before],['invitees'=>$eligible]);
    }
}
