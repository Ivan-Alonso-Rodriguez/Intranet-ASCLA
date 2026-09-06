<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\GroupPlanner;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\Store;
final class MicroEvents
{
    public static function create(): array
    {
        $month=wp_date('Y-m');
        return Store::lock('micro:'.$month,static function () use($month) {
            $existing=get_option('ascla_micro_'.$month);
            if ($existing) { return $existing; }
            $profiles=[];
            foreach (get_users(['capability'=>'ascla_access','fields'=>'ID']) as $id) {
                if (!Access::member((int)$id)) { continue; }
                $p=Profiles::raw((int)$id);
                if (!empty($p['microevents'])&&!empty($p['networking'])&&!empty($p['directory'])) { $profiles[]=$p; }
            }
            $history=get_option('ascla_group_history',[]);
            $plan=GroupPlanner::plan($profiles,$history,(int)wp_date('n'));
            $ids=[];
            foreach ($plan['groups'] as $index=>$group) {
                $interests=[];
                foreach ($group as $uid) { foreach (Profiles::raw($uid)['interests']??[] as $tid) { $interests[$tid]=($interests[$tid]??0)+1; } }
                arsort($interests); $tid=$interests?array_key_first($interests):0; $term=$tid?get_term($tid,'ascla_interest'):null;
                $topic=$term&&!is_wp_error($term)?$term->name:'Gobierno corporativo';
                $start=(new \DateTimeImmutable('+14 days 16:00',wp_timezone()))->setTimezone(new \DateTimeZone('UTC'));
                $meta=['micro'=>true,'invitees'=>$group,'start'=>$start->format('c'),'end'=>$start->modify('+45 minutes')->format('c'),'capacity'=>count($group),'chatham'=>true,'modality'=>'Virtual','location'=>'Por confirmar','agenda'=>'5 min · Presentaciones\n15 min · Experiencias sobre '.$topic.'\n20 min · Conversación y aprendizaje\n5 min · Próximos pasos','invited'=>false];
                $id=wp_insert_post(wp_slash(['post_type'=>'ascla_event','post_title'=>'Círculo ASCLA · '.$topic,'post_content'=>'Una conversación en un grupo pequeño para compartir experiencias profesionales. La hora propuesta puede ajustarse antes de la aprobación.','post_status'=>'draft','post_author'=>get_current_user_id()]),true);
                if (is_wp_error($id)) { throw new \RuntimeException('No se pudo crear el microevento.'); }
                update_post_meta($id,'_ascla',$meta);
                wp_update_post(['ID'=>$id,'post_status'=>Settings::get()['micro_approval']?'pending':'publish']);
                $ids[]=$id;
                foreach ($group as $a) { foreach ($group as $b) { if ($a<$b) { $pair=$a.':'.$b; $history[$pair]=min(12,($history[$pair]??0)+1); } } }
            }
            $result=['events'=>$ids,'waiting'=>$plan['waiting'],'month'=>$month];
            if ($ids) { update_option('ascla_micro_'.$month,$result,false); update_option('ascla_group_history',$history,false); }
            Audit::record('microevents_created',0,'groups_'.count($ids)); return $result;
        });
    }
    public static function invite(int $id): void
    {
        $meta=(array)get_post_meta($id,'_ascla',true);
        if (!empty($meta['invited'])) { return; }
        foreach ($meta['invitees']??[] as $uid) {
            if (!Access::member($uid)) { continue; }
            $p=Profiles::raw($uid); if (empty($p['microevents'])) { continue; }
            if (!Store::count('registrations','event_id=%d AND user_id=%d',[$id,$uid])) { Store::insert('registrations',['event_id'=>$id,'user_id'=>$uid,'status'=>'invited','created_at'=>current_time('mysql',true)]); }
            Notifications::send($uid,'microevent','Te invitaron a un círculo ASCLA.',Catalog::url('eventos',['item'=>$id]),['type'=>'post','id'=>$id]);
        }
        $meta['invited']=true; update_post_meta($id,'_ascla',$meta);
    }
    public static function monthly(): void { if (Settings::get()['micro_enabled']) { \ASCLA\Core\Jobs\Queue::enqueue('microevents',[],0); } }
}
