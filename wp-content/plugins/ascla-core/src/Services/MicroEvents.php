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
        $month=($onlyIds?'demo-'.substr(hash('sha256',wp_json_encode($onlyIds)),0,12).'-':'').wp_date('Y-m');
        return Store::lock('micro:'.$month,static function () use($month,$onlyIds) {
            $existing=get_option('ascla_micro_'.$month);
            if ($existing) { return $existing; }
            $profiles=self::eligible($onlyIds);
            $history=get_option('ascla_group_history',[]);
            $blocked=self::blockedPairs($profiles);
            $plan=GroupPlanner::plan($profiles,$history,(int)wp_date('n'),$blocked);
            $ids=[];
            foreach ($plan['groups'] as $group) {
                $ids[]=self::proposal($group,(bool)$onlyIds);
                $history=self::recordPairs($group,$history);
            }
            $result=['events'=>$ids,'waiting'=>$plan['waiting'],'month'=>$month];
            if ($ids) { update_option('ascla_micro_'.$month,$result,false); update_option('ascla_group_history',$history,false); }
            Audit::record('microevents_created',0,'groups_'.count($ids)); return $result;
        });
    }
    private static function eligible(array $onlyIds): array
    {
        $profiles=[];
        foreach (get_users(['capability'=>'ascla_access','fields'=>'ID']) as $id) {
            if (($onlyIds&&!in_array((int)$id,$onlyIds,true)) || !Access::member((int)$id)) { continue; }
            $p=Profiles::raw((int)$id);
            if (!empty($p['microevents'])&&!empty($p['networking'])&&!empty($p['directory'])) { $profiles[]=Profiles::matchingProfile((int)$id); }
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
    private static function proposal(array $group,bool $demo): int
    {
                $proposal=NetworkingAI::agenda($group,$demo);
                $start=(new \DateTimeImmutable('+14 days 16:00',wp_timezone()))->setTimezone(new \DateTimeZone('UTC'));
                $meta=['micro'=>true,'invitees'=>$group,'start'=>$start->format('c'),'end'=>$start->modify('+'.$proposal['duration_minutes'].' minutes')->format('c'),'capacity'=>count($group),'chatham'=>true,'modality'=>'Virtual','location'=>'Por confirmar','agenda'=>implode("\n",array_map(static fn($item)=>$item['minutes'].' min · '.$item['topic'],$proposal['agenda'])),'agenda_ai'=>$proposal,'invited'=>false];
                $id=wp_insert_post(wp_slash(['post_type'=>'ascla_event','post_title'=>$proposal['title'],'post_content'=>$proposal['objective'],'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
                if (is_wp_error($id)) { throw new \RuntimeException('No se pudo crear el microevento.'); }
                update_post_meta($id,'_ascla',$meta);
                wp_update_post(['ID'=>$id,'post_status'=>Settings::get()['micro_approval']?'pending':'publish']);

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
        $group=array_map('intval',$meta['invitees']??[]);
        Access::require(count($group)>=4&&count($group)<=6,'Revise el tamaño del grupo antes de aprobar.',400);
        foreach($group as $a){
            $p=Profiles::raw($a);Access::require(!empty($p['microevents'])&&!empty($p['networking'])&&!empty($p['directory']),'Un participante ya no acepta microeventos.',400);
            foreach($group as $b){if($a<$b){ Access::require(!Messaging::blocked($a,$b),'El grupo contiene un bloqueo entre participantes. Cree otra propuesta.',400); }}
        }
    }
    public static function monthly(): void { if (Settings::get()['micro_enabled']) { \ASCLA\Core\Jobs\Queue::enqueue('microevents',[],0); } }
}
