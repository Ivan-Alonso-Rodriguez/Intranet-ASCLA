<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Repositories\WordPressWrites;

/** Pending proposals must still have a consenting, eligible group before publication. */
final class MicroEligibility
{
    private const REVIEW_PERMISSION='La revisión del microevento requiere administración.';
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

    /** Refresh the group synchronously when an administrator opens a proposal review. */
    public static function review(int $id): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'),self::REVIEW_PERMISSION,403);
        return Store::atomic('event:'.$id,static function()use($id){
            clean_post_cache($id);$post=Content::get($id);$before=(array)get_post_meta($id,'_ascla',true);
            Access::require($post->post_type==='ascla_event' && !empty($before['micro']),'Seleccione una propuesta de microevento.',400);
            self::proposal($id);
            $meta=(array)get_post_meta($id,'_ascla',true);
            $item=Content::serialize(get_post($id));
            MicroInvitations::apply($item,$id,$meta);
            return ['item'=>$item,'candidates'=>count((array)($meta['invitees']??[])),
                'removed'=>count(array_diff((array)($before['invitees']??[]),(array)($meta['invitees']??[])))];
        });
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

    /** Only unpublished, unsent proposals can have their selection changed. */
    private static function editable(int $id): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'),self::REVIEW_PERMISSION,403);
        $post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_event' && !empty($meta['micro']),'Seleccione una propuesta de microevento.',400);
        Access::require(in_array($post->post_status,['draft','pending','ascla_hidden'],true) && empty($meta['invited']) && empty($meta['cancelled']),
            'Solo puedes cambiar candidatos antes de publicar las invitaciones.',409);
        return $meta;
    }

    /** Search a bounded page of eligible replacements; names stay within administration. */
    public static function options(int $id,string $query='',int $page=1): array
    {
        self::editable($id);$query=mb_strtolower(trim(Access::text($query,100)));
        $items=[];$offset=0;
        do {
            $users=get_users(['capability'=>'ascla_access','number'=>200,'offset'=>$offset,'orderby'=>'ID','order'=>'ASC']);
            cache_users(array_map(static fn($user)=>(int)$user->ID,$users));
            foreach ($users as $user) {
                $uid=(int)$user->ID;
                if ($query!=='' && !str_contains(mb_strtolower($user->display_name),$query)) { continue; }
                if (self::candidates([$uid])!==[$uid]) { continue; }
                $items[]=['id'=>$uid,'name'=>$user->display_name];
            }
            $offset+=200;
        } while(count($users)===200);
        $pages=max(1,(int)ceil(count($items)/12));$page=max(1,min($pages,$page));
        return ['items'=>array_slice($items,($page-1)*12,12),'total'=>count($items),'page'=>$page,'pages'=>$pages];
    }

    public static function replace(int $id,mixed $ids,string $reason): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'),self::REVIEW_PERMISSION,403);
        Access::require(is_array($ids) && count($ids)<=50,'Selecciona una lista de candidatos válida.',400);
        foreach($ids as $uid) { Access::require((is_int($uid) || (is_string($uid) && ctype_digit($uid))) && (int)$uid>0,'Selecciona una lista de candidatos válida.',400); }
        $ids=array_values(array_unique(array_map('intval',$ids)));
        $reason=trim(Access::text($reason,1000));Access::require($reason!=='','Indica el motivo del cambio de candidatos.',400);
        return Store::atomic('event:'.$id,static function()use($id,$ids,$reason){
            clean_post_cache($id);$meta=self::editable($id);$before=array_values(array_map('intval',$meta['invitees']??[]));
            $updated=$meta;$updated['invitees']=$ids;MicroEvents::validateInvitees($updated);
            if ($before!==$ids) {
                $updated['micro_approved']=false;
                unset($updated['public_at'],$updated['publish_at'],$updated['publish_actor_id'],$updated['schedule_error']);
                WordPressWrites::meta('post',$id,'_ascla',$updated);ContentSchedule::cancel($id);
                WordPressWrites::post(['ID'=>$id,'post_status'=>'pending']);MicroLifecycle::history($id,'pending',$reason);
                Audit::changes('microevent_candidates_updated',$id,['invitees'=>$before],['invitees'=>$ids,'reason'=>$reason]);
            }
            $item=Content::serialize(get_post($id));
            MicroInvitations::apply($item,$id,(array)get_post_meta($id,'_ascla',true));
            return ['item'=>$item,'candidates'=>count($ids),'removed'=>count(array_diff($before,$ids))];
        });
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
