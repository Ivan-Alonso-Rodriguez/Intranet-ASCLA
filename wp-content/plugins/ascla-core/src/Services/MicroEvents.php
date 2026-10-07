<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\GroupPlanner;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\Store;
final class MicroEvents
{
    public static function create(array $onlyIds=[]): array
    {
        sort($onlyIds);
        $month=($onlyIds?'demo-'.substr(hash('sha256',wp_json_encode($onlyIds)),0,12).'-':'').MicroPlanning::period();
        return Store::lock('micro:'.$month,static function () use($month,$onlyIds) {
            $existing=get_option('ascla_micro_'.$month);
            if (is_array($existing)) {
                $current=self::sanitizeResult($existing);
                if (!empty($current['events'])) {
                    if ($current!==$existing) { update_option('ascla_micro_'.$month,$current,false); }
                    return $current;
                }
                // A deleted proposal must not keep the month locked to stale post IDs.
                delete_option('ascla_micro_'.$month);
            }
            $profiles=self::eligible($onlyIds);
            $history=get_option('ascla_group_history',[]);
            $blocked=self::blockedPairs($profiles);
            $plan=MicroPlanning::plan($profiles,$history,$blocked);
            $ids=[];
            foreach ($plan['groups'] as $group) {
                $ids[]=self::proposal($group['members'],(bool)$onlyIds,$group['topic']);
                $history=self::recordPairs($group['members'],$history);
            }
            $result=['events'=>$ids,'waiting'=>$plan['waiting'],'month'=>$month];
            if ($ids) { update_option('ascla_micro_'.$month,$result,false); update_option('ascla_group_history',$history,false); }
            Audit::record('microevents_created',0,'groups_'.count($ids)); return $result;
        });
    }

    /** Remove deleted/trashed microevents from a stored or queued result. */
    public static function sanitizeResult(array $result): array
    {
        $events=[];
        foreach ((array)($result['events']??[]) as $id) {
            $id=absint($id);
            if ($id>0 && self::isLiveProposal($id)) { $events[]=$id; }
        }
        $result['events']=array_values(array_unique($events));
        $result['waiting']=array_values(array_unique(array_filter(array_map('absint',(array)($result['waiting']??[])))));
        return $result;
    }
    private static function isLiveProposal(int $id): bool
    {
        $post=get_post($id);
        if (!$post || $post->post_type!=='ascla_event' || in_array($post->post_status,['trash','auto-draft'],true)) { return false; }
        $meta=(array)get_post_meta($id,'_ascla',true);
        return !empty($meta['micro']);
    }
    /** Keep monthly proposal caches aligned when an administrator removes a microevent. */
    public static function forget(int $id): void
    {
        global $wpdb;
        $like=$wpdb->esc_like('ascla_micro_').'%';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",$like),ARRAY_A)?:[];
        foreach ($rows as $row) {
            $result=maybe_unserialize($row['option_value']??null);
            if (!is_array($result) || empty($result['events']) || !in_array($id,array_map('absint',(array)$result['events']),true)) { continue; }
            $result['events']=array_values(array_filter(array_map('absint',(array)$result['events']),static fn($eventId)=>$eventId!==$id));
            $result=self::sanitizeResult($result);
            if ($result['events']) { update_option($row['option_name'],$result,false); }
            else { delete_option($row['option_name']); }
        }
    }
    private static function eligible(array $onlyIds): array
    {
        $profiles=[];
        foreach (get_users(['capability'=>'ascla_access','fields'=>'ID']) as $id) {
            if (($onlyIds&&!in_array((int)$id,$onlyIds,true)) || !Access::member((int)$id)) { continue; }
            $p=Profiles::raw((int)$id);
            if (!empty($p['microevents']) && !ProfileRequirements::missing($p)) { $profiles[]=Profiles::matchingProfile((int)$id); }
        }
        return $profiles;
    }
    private static function blockedPairs(array $profiles): array
    {
        $blocked=[];
        foreach ($profiles as $a) {
            foreach ($profiles as $b) {
                if ($a['id']<$b['id']&&Messaging::blocked($a['id'],$b['id'])) { $blocked[$a['id'].':'.$b['id']]=true; }
            }
        }
        return $blocked;
    }
    private static function recordPairs(array $group,array $history): array
    {
        foreach ($group as $a) {
            foreach ($group as $b) {
                if ($a<$b) { $pair=$a.':'.$b;$history[$pair]=min(12,($history[$pair]??0)+1); }
            }
        }
        return $history;
    }
    private static function proposal(array $group,bool $demo,int $topic): int
    {
                $proposal=NetworkingAI::agenda($group,$demo);
                $start=(new \DateTimeImmutable('+14 days 16:00',wp_timezone()))->setTimezone(new \DateTimeZone('UTC'));
                $meta=['micro'=>true,'invitees'=>$group,'start'=>$start->format('c'),'end'=>$start->modify('+'.$proposal['duration_minutes'].' minutes')->format('c'),'capacity'=>Settings::get()['micro_capacity'],'chatham'=>true,'modality'=>'Virtual','location'=>'Por confirmar','agenda'=>implode("\n",array_map(static fn($item)=>$item['minutes'].' min · '.$item['topic'],$proposal['agenda'])),'agenda_ai'=>$proposal,'invited'=>false];
                $id=wp_insert_post(wp_slash(['post_type'=>'ascla_event','post_title'=>$proposal['title'],'post_content'=>$proposal['objective'],'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
                if (is_wp_error($id)) { throw new ServiceException('No se pudo crear el microevento.'); }
                update_post_meta($id,'_ascla',MicroLifecycle::defaults($meta+['topic_id'=>$topic]));
                wp_set_object_terms($id,[$topic],'ascla_interest');
                MicroLifecycle::history($id,'pending');
                wp_update_post(['ID'=>$id,'post_status'=>'pending']);

        return $id;
    }
    public static function invite(int $id): void
    {
        $meta=(array)get_post_meta($id,'_ascla',true);
        if (!empty($meta['invited'])) { return; }
        self::validateInvitees($meta);
        foreach ($meta['invitees']??[] as $uid) {
            if (!Access::member($uid)) { continue; }
            $p=Profiles::raw($uid); if (empty($p['microevents'])) { continue; }
            if (!Store::count('registrations','event_id=%d AND user_id=%d',[$id,$uid])) { Store::insert('registrations',['event_id'=>$id,'user_id'=>$uid,'status'=>'invited','created_at'=>current_time('mysql',true)]); }
            Notifications::send($uid,'microevent','Te invitaron a un círculo ASCLA.',Catalog::url('eventos',['item'=>$id]),['type'=>'post','id'=>$id]);
        }
        $meta['invited']=true; update_post_meta($id,'_ascla',$meta);
    }
    public static function validateInvitees(array $meta): void
    {
        $group=array_values(array_unique(array_map('intval',$meta['invitees']??[])));
        Access::require(count($group)>=(int)($meta['micro_min']??Settings::get()['micro_min']) && count($group)<=(int)($meta['capacity']??Settings::get()['micro_capacity']),'Revise el tamaño del grupo antes de aprobar.',400);
        foreach($group as $a){
            Access::require(Access::member($a),'Un participante ya no tiene membresía activa.',400);$p=Profiles::raw($a);Access::require(Access::member($a) && !empty($p['microevents']) && !ProfileRequirements::missing($p),'Un participante ya no acepta microeventos.',400);
            foreach($group as $b){if($a<$b){ Access::require(!Messaging::blocked($a,$b),'El grupo contiene un bloqueo entre participantes. Cree otra propuesta.',400); }}
        }
    }
    public static function monthly(): void { if (Settings::get()['micro_enabled']) { \ASCLA\Core\Jobs\Queue::enqueue('microevents',[],0); } }
}
