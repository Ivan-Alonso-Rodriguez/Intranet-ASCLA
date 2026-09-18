<?php
namespace ASCLA\Core\Repositories;
use ASCLA\Core\Domain\StatisticsPeriod;
use ASCLA\Core\Services\Profiles;

/** Aggregate stored evidence in SQL; transfer only bounded pages to PHP. */
final class StatisticsQueries
{
    private static function members(): string
    {
        global $wpdb;$q=new \WP_User_Query();$q->prepare_query(['capability'=>'ascla_access','fields'=>'ID','number'=>-1,'count_total'=>false]);
        return "SELECT DISTINCT {$wpdb->users}.ID ".$q->query_from.' '.$q->query_where;
    }

    private static function facts(string $scope): string
    {
        $members=self::members();$reg=Store::table('registrations');$att=Store::table('attendance');
        return "SELECT r.event_id,r.user_id,MAX(r.registered) registered,MAX(r.present) present,MAX(r.recorded) recorded,MAX(r.minutes) minutes,MAX(r.complete) complete,MAX(r.ending) ending
            FROM (SELECT reg.event_id,reg.user_id,1 registered,0 present,0 recorded,NULL minutes,e.complete,e.ending ending FROM $reg reg JOIN ($scope) e ON e.id=reg.event_id WHERE reg.status='accepted'
            UNION ALL SELECT att.event_id,att.user_id,0,att.status='present',1,IF(att.status='present',att.minutes,NULL),e.complete,e.ending FROM $att att JOIN ($scope) e ON e.id=att.event_id) r
            JOIN ($members) m ON m.ID=r.user_id GROUP BY r.event_id,r.user_id";
    }

    private static function rankingSql(string $facts): string
    {
        return "SELECT user_id id,SUM(registered) registered,SUM(present) attended,SUM(registered*complete) eligible,SUM(registered*complete*present) attended_registered,SUM(minutes) minutes,COUNT(minutes) duration_known,MAX(IF(present,ending,NULL)) last_attendance FROM ($facts) f WHERE registered=1 OR present=1 GROUP BY user_id";
    }

    public static function cohort(string $scope,int $page=1,bool $ranking=true): array
    {
        global $wpdb;$facts=self::facts($scope);$rank=self::rankingSql($facts);$page=max(1,$page);
        $s=$wpdb->get_row("SELECT COALESCE(SUM(registered),0) registrations,COUNT(DISTINCT IF(present,user_id,NULL)) attendees,COALESCE(SUM(present),0) attendances,COUNT(DISTINCT IF(recorded,event_id,NULL)) events_with_records,COALESCE(SUM(registered*complete),0) rate_registered,COALESCE(SUM(registered*complete*present),0) rate_attended,COALESCE(SUM(present*complete),0) complete_present,SUM(minutes) minutes,COUNT(minutes) duration_known FROM ($facts) f",ARRAY_A)?:[];
        foreach($s as $key=>$value)if($value!==null)$s[$key]=(int)$value;
        $eventSummary=$wpdb->get_row("SELECT COUNT(*) events,COALESCE(SUM(complete),0) complete_events FROM ($scope) e",ARRAY_A)?:['events'=>0,'complete_events'=>0];
        $s['events']=(int)$eventSummary['events'];$s['complete_events']=(int)$eventSummary['complete_events'];
        foreach(['registrations','attendees','attendances','events_with_records','rate_registered','rate_attended','minutes','duration_known'] as $key)if(!isset($s[$key]))$s[$key]=0;
        $s['rate']=$s['rate_registered']?round(100*$s['rate_attended']/$s['rate_registered'],1):null;$s['average']=$s['complete_events']?round(($s['complete_present']??0)/$s['complete_events'],1):null;unset($s['complete_present']);
        $counts=$wpdb->get_row("SELECT COUNT(*) total,COALESCE(SUM(attended>=2),0) recurring FROM ($rank) r",ARRAY_A)?:['total'=>0,'recurring'=>0];$s['recurring']=(int)$counts['recurring'];$items=[];
        if($ranking){$items=$wpdb->get_results($rank.' ORDER BY attended DESC,registered DESC,id ASC LIMIT 20 OFFSET '.(($page-1)*20),ARRAY_A)?:[];$users=array_map('intval',array_column($items,'id'));if($users)cache_users($users);
            foreach($items as &$r){foreach(['id','registered','attended','eligible','attended_registered','duration_known'] as $k)$r[$k]=(int)$r[$k];$r['minutes']=$r['minutes']===null?null:(int)$r['minutes'];$r['name']=Profiles::publicName($r['id']);$r['rate']=$r['eligible']?round(100*$r['attended_registered']/$r['eligible'],1):null;$r['recurring']=$r['attended']>=2;}unset($r);
        }
        $topics=[];foreach($wpdb->get_results("SELECT t.term_id,SUM(f.registered) registrations,SUM(f.present) attendances,COUNT(DISTINCT IF(f.present,f.user_id,NULL)) attendees FROM ($facts) f JOIN {$wpdb->term_relationships} tr ON tr.object_id=f.event_id JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=tr.term_taxonomy_id AND t.taxonomy='ascla_interest' GROUP BY t.term_id",ARRAY_A)?:[] as $r)$topics[(int)$r['term_id']]=array_map('intval',$r);
        return ['summary'=>$s,'ranking'=>$items,'ranking_total'=>(int)$counts['total'],'ranking_page'=>$page,'topics'=>$topics];
    }

    public static function eventTopicCounts(string $scope): array
    {
        global $wpdb;$result=[];
        foreach($wpdb->get_results("SELECT tt.term_id,COUNT(DISTINCT e.id) events FROM ($scope) e JOIN {$wpdb->term_relationships} tr ON tr.object_id=e.id JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='ascla_interest' GROUP BY tt.term_id",ARRAY_A)?:[] as $row)$result[(int)$row['term_id']]=(int)$row['events'];
        return $result;
    }

    public static function eventCounts(string $scope,array $ids): array
    {
        global $wpdb;$ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids)return [];$facts=self::facts($scope);$list=implode(',',$ids);$result=[];
        foreach($wpdb->get_results("SELECT event_id,SUM(registered) registered,SUM(present) attended FROM ($facts) f WHERE event_id IN ($list) GROUP BY event_id",ARRAY_A)?:[] as $row)$result[(int)$row['event_id']]=['registered'=>(int)$row['registered'],'attended'=>(int)$row['attended']];
        return $result;
    }

    public static function membersSummary(StatisticsPeriod $period): array
    {
        global $wpdb;$members=self::members();$utc=new \DateTimeZone('UTC');$from=$period->from->setTimezone($utc)->format('Y-m-d H:i:s');$until=$period->until->setTimezone($utc)->format('Y-m-d H:i:s');
        $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) users,COALESCE(SUM(u.user_registered>=%s AND u.user_registered<%s),0) new_users FROM {$wpdb->users} u JOIN ($members) m ON m.ID=u.ID",$from,$until),ARRAY_A);
        $row['active_users']=$wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT a.actor_id) FROM '.Store::table('audit')." a JOIN ($members) m ON m.ID=a.actor_id WHERE a.created_at>=%s AND a.created_at<%s AND a.action<>%s",$from,$until,'request_failed'));return array_map('intval',$row);
    }

    public static function declared(): array
    {
        global $wpdb;$table=Store::table('user_interests');$members=self::members();$result=[];
        foreach($wpdb->get_results("SELECT term_id,COUNT(DISTINCT user_id) declared,COUNT(DISTINCT IF(source='profile',user_id,NULL)) profile,COUNT(DISTINCT IF(source='forms',user_id,NULL)) forms FROM $table i JOIN ($members) m ON m.ID=i.user_id GROUP BY term_id",ARRAY_A) as $r)$result[(int)$r['term_id']]=array_map('intval',$r);return $result;
    }

    public static function content(StatisticsPeriod $period): array
    {
        global $wpdb;$where="p.post_status='publish' AND p.post_type IN ('ascla_hub','ascla_forum','ascla_topic','ascla_resource','ascla_gallery')";
        if(!current_user_can('ascla_manage'))$where.=$wpdb->prepare(" AND (p.post_type NOT IN ('ascla_resource','ascla_gallery') OR p.post_author=%d OR NOT EXISTS(SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_ascla_micro' AND pm.meta_value='1') OR EXISTS(SELECT 1 FROM {$wpdb->postmeta} pi WHERE pi.post_id=p.ID AND pi.meta_key='_ascla_invitee' AND pi.meta_value=%s))",get_current_user_id(),(string)get_current_user_id());
        $utc=new \DateTimeZone('UTC');$where.=$wpdb->prepare(' AND p.post_date_gmt>=%s AND p.post_date_gmt<%s',$period->from->setTimezone($utc)->format('Y-m-d H:i:s'),$period->until->setTimezone($utc)->format('Y-m-d H:i:s'));$result=[];
        foreach($wpdb->get_results("SELECT t.term_id,COUNT(DISTINCT p.ID) total,COUNT(DISTINCT IF(p.post_type='ascla_resource',p.ID,NULL)) resources,COUNT(DISTINCT IF(p.post_type IN ('ascla_hub','ascla_forum','ascla_topic'),p.ID,NULL)) discussions FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=tr.term_taxonomy_id AND t.taxonomy='ascla_interest' WHERE $where GROUP BY t.term_id",ARRAY_A) as $r)$result[(int)$r['term_id']]=array_map('intval',$r);return $result;
    }
}
