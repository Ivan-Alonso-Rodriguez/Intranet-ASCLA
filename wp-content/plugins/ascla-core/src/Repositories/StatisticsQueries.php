<?php
namespace ASCLA\Core\Repositories;
use ASCLA\Core\Domain\StatisticsPeriod;
use ASCLA\Core\Services\Profiles;

/** Aggregate stored evidence in SQL; transfer only bounded pages to PHP. */
final class StatisticsQueries
{
    private const DATABASE_DATE_FORMAT='Y-m-d H:i:s';
    private static function members(): string
    {
        global $wpdb;$q=new \WP_User_Query();$q->prepare_query(['capability'=>'ascla_access','fields'=>'ID','number'=>-1,'count_total'=>false]);
        return "SELECT DISTINCT {$wpdb->users}.ID ".$q->query_from.' '.$q->query_where;
    }

    private static function facts(string $scope): string
    {
        $members=self::members();$reg=Store::table('registrations');$att=Store::table('attendance');
        // Each ASCLA table has a unique (event_id,user_id) key. Join that pair once,
        // then include attendance without an accepted reservation. Collapse only
        // legacy duplicate event metadata, rather than grouping every RSVP fact.
        $events="SELECT id,MAX(complete) complete,MAX(ending) ending FROM ($scope) scoped GROUP BY id";
        return "SELECT r.* FROM (
            SELECT reg.event_id,reg.user_id,1 registered,COALESCE(att.status='present',0) present,(att.id IS NOT NULL) recorded,IF(att.status='present',att.minutes,NULL) minutes,e.complete,e.ending
            FROM $reg reg JOIN ($events) e ON e.id=reg.event_id LEFT JOIN $att att ON att.event_id=reg.event_id AND att.user_id=reg.user_id WHERE reg.status='accepted'
            UNION ALL SELECT att.event_id,att.user_id,0,att.status='present',1,IF(att.status='present',att.minutes,NULL),e.complete,e.ending
            FROM $att att JOIN ($events) e ON e.id=att.event_id WHERE NOT EXISTS(SELECT 1 FROM $reg reg WHERE reg.event_id=att.event_id AND reg.user_id=att.user_id AND reg.status='accepted')
            ) r JOIN ($members) m ON m.ID=r.user_id";
    }

    private static function rankingSql(string $facts): string
    {
        return "SELECT user_id id,SUM(registered) registered,SUM(present) attended,SUM(registered*complete) eligible,SUM(registered*complete*present) attended_registered,SUM(minutes) minutes,COUNT(minutes) duration_known,MAX(IF(present,ending,NULL)) last_attendance FROM ($facts) f WHERE registered=1 OR present=1 GROUP BY user_id";
    }

    private static function cohortSummary(string $scope,string $facts,string $rank): array
    {
        global $wpdb;
        $summary=$wpdb->get_row("SELECT COALESCE(SUM(registered),0) registrations,COUNT(DISTINCT IF(registered,user_id,NULL)) registrants,COUNT(DISTINCT IF(present,user_id,NULL)) attendees,COALESCE(SUM(present),0) attendances,COUNT(DISTINCT IF(recorded,event_id,NULL)) events_with_records,COALESCE(SUM(registered*complete),0) rate_registered,COALESCE(SUM(registered*complete*present),0) rate_attended,COALESCE(SUM(present*complete),0) complete_present,SUM(minutes) minutes,COUNT(minutes) duration_known FROM ($facts) f",ARRAY_A)?:[];
        foreach ($summary as $key=>$value) { if ($value!==null) { $summary[$key]=(int)$value; } }
        $events=$wpdb->get_row("SELECT COUNT(*) events,COALESCE(SUM(complete),0) complete_events FROM ($scope) e",ARRAY_A)?:['events'=>0,'complete_events'=>0];
        $summary['events']=(int)$events['events'];$summary['complete_events']=(int)$events['complete_events'];
        foreach (['registrations','registrants','attendees','attendances','events_with_records','rate_registered','rate_attended','minutes','duration_known'] as $key) { $summary[$key]=(int)($summary[$key]??0); }
        $summary['rate']=$summary['rate_registered']?round(100*$summary['rate_attended']/$summary['rate_registered'],1):null;
        $summary['average']=$summary['complete_events']?round(($summary['complete_present']??0)/$summary['complete_events'],1):null;
        unset($summary['complete_present']);
        $counts=$wpdb->get_row("SELECT COUNT(*) total,COALESCE(SUM(attended>=2),0) recurring FROM ($rank) r",ARRAY_A)?:['total'=>0,'recurring'=>0];
        $summary['recurring']=(int)$counts['recurring'];
        return [$summary,$counts];
    }
    private static function cohortRanking(string $rank,int $page,bool $names): array
    {
        global $wpdb;
        $items=$wpdb->get_results($rank.' ORDER BY attended DESC,registered DESC,id ASC LIMIT 20 OFFSET '.(($page-1)*20),ARRAY_A)?:[];
        $users=array_map('intval',array_column($items,'id'));if($users) { cache_users($users); }
        foreach ($items as &$row) {
            foreach (['id','registered','attended','eligible','attended_registered','duration_known'] as $key) { $row[$key]=(int)$row[$key]; }
            $row['minutes']=$row['minutes']===null?null:(int)$row['minutes'];
            if ($names) { $row['name']=Profiles::publicName($row['id']); }
            $row['rate']=$row['eligible']?round(100*$row['attended_registered']/$row['eligible'],1):null;$row['recurring']=$row['attended']>=2;
        }
        unset($row);return $items;
    }
    private static function cohortTopics(string $facts): array
    {
        global $wpdb;$topics=[];
        foreach ($wpdb->get_results("SELECT t.term_id,SUM(f.registered) registrations,SUM(f.present) attendances,COUNT(DISTINCT IF(f.present,f.user_id,NULL)) attendees FROM ($facts) f JOIN {$wpdb->term_relationships} tr ON tr.object_id=f.event_id JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=tr.term_taxonomy_id AND t.taxonomy='ascla_interest' GROUP BY t.term_id",ARRAY_A)?:[] as $row) {
            $topics[(int)$row['term_id']]=array_map('intval',$row);
        }
        return $topics;
    }
    public static function cohort(string $scope,int $page=1,bool $ranking=true,bool $names=true): array
    {
        $facts=self::facts($scope);$rank=self::rankingSql($facts);$page=max(1,$page);
        [$summary,$counts]=self::cohortSummary($scope,$facts,$rank);
        return ['summary'=>$summary,'ranking'=>$ranking?self::cohortRanking($rank,$page,$names):[],'ranking_total'=>(int)$counts['total'],'ranking_page'=>$page,'topics'=>self::cohortTopics($facts)];
    }

    public static function eventTopicCounts(string $scope): array
    {
        global $wpdb;$result=[];
        foreach($wpdb->get_results("SELECT tt.term_id,COUNT(DISTINCT e.id) events FROM ($scope) e JOIN {$wpdb->term_relationships} tr ON tr.object_id=e.id JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='ascla_interest' GROUP BY tt.term_id",ARRAY_A)?:[] as $row) {$result[(int)$row['term_id']]=(int)$row['events']; }
        return $result;
    }

    public static function eventCounts(string $scope,array $ids): array
    {
        global $wpdb;$ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids) {return []; }$facts=self::facts($scope);$list=implode(',',$ids);$result=[];
        foreach($wpdb->get_results("SELECT event_id,SUM(registered) registered,SUM(present) attended FROM ($facts) f WHERE event_id IN ($list) GROUP BY event_id",ARRAY_A)?:[] as $row) {$result[(int)$row['event_id']]=['registered'=>(int)$row['registered'],'attended'=>(int)$row['attended']]; }
        return $result;
    }

    public static function membersSummary(StatisticsPeriod $period): array
    {
        global $wpdb;$members=self::members();$utc=new \DateTimeZone('UTC');$from=$period->from->setTimezone($utc)->format(self::DATABASE_DATE_FORMAT);$until=$period->until->setTimezone($utc)->format(self::DATABASE_DATE_FORMAT);
        $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) users,COALESCE(SUM(u.user_registered>=%s AND u.user_registered<%s),0) new_users FROM {$wpdb->users} u JOIN ($members) m ON m.ID=u.ID",$from,$until),ARRAY_A);
        $row['active_users']=$wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT a.actor_id) FROM '.Store::table('audit')." a JOIN ($members) m ON m.ID=a.actor_id WHERE a.created_at>=%s AND a.created_at<%s AND a.action<>%s",$from,$until,'request_failed'));return array_map('intval',$row);
    }

    /** Community totals contain counts only; private messages and profile values are never selected. */
    public static function community(StatisticsPeriod $period): array
    {
        global $wpdb;
        $utc=new \DateTimeZone('UTC');$from=$period->from->setTimezone($utc)->format(self::DATABASE_DATE_FORMAT);$until=$period->until->setTimezone($utc)->format(self::DATABASE_DATE_FORMAT);
        $relations=Store::table('relations');$audit=Store::table('audit');
        $active=(int)$wpdb->get_var("SELECT COUNT(DISTINCT LEAST(r.user_id,r.target_id),GREATEST(r.user_id,r.target_id)) FROM $relations r JOIN {$wpdb->users} a ON a.ID=r.user_id JOIN {$wpdb->users} b ON b.ID=r.target_id WHERE r.kind='connected' AND r.user_id<>r.target_id");
        $accepted=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $audit WHERE action='connection_accepted' AND created_at>=%s AND created_at<%s",$from,$until));
        $types="'ascla_hub','ascla_forum','ascla_topic','ascla_resource','ascla_gallery'";
        $posts=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) publications,COALESCE(SUM(post_type='ascla_topic'),0) forum_topics FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($types) AND post_date_gmt>=%s AND post_date_gmt<%s",$from,$until),ARRAY_A)?:[];
        $comments=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) comments,COALESCE(SUM(p.post_type IN ('ascla_forum','ascla_topic')),0) forum_comments FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE c.comment_approved='1' AND c.comment_type IN ('','comment') AND p.post_status='publish' AND p.post_type IN ($types) AND c.comment_date_gmt>=%s AND c.comment_date_gmt<%s",$from,$until),ARRAY_A)?:[];
        return ['active_connections'=>$active,'accepted_connections'=>$accepted]+array_map('intval',$posts+$comments);
    }

    public static function declared(): array
    {
        global $wpdb;$table=Store::table('user_interests');$members=self::members();$result=[];
        foreach($wpdb->get_results("SELECT term_id,COUNT(DISTINCT user_id) declared,COUNT(DISTINCT IF(source='profile',user_id,NULL)) profile,COUNT(DISTINCT IF(source='forms',user_id,NULL)) forms FROM $table i JOIN ($members) m ON m.ID=i.user_id GROUP BY term_id",ARRAY_A) as $r) {$result[(int)$r['term_id']]=array_map('intval',$r); }return $result;
    }

    public static function content(StatisticsPeriod $period): array
    {
        global $wpdb;$where="p.post_status='publish' AND p.post_type IN ('ascla_hub','ascla_forum','ascla_topic','ascla_resource','ascla_gallery')";
        if(!current_user_can('ascla_manage')) {$where.=$wpdb->prepare(" AND (p.post_type NOT IN ('ascla_resource','ascla_gallery') OR p.post_author=%d OR NOT EXISTS(SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_ascla_micro' AND pm.meta_value='1') OR EXISTS(SELECT 1 FROM {$wpdb->postmeta} pi WHERE pi.post_id=p.ID AND pi.meta_key='_ascla_invitee' AND pi.meta_value=%s))",get_current_user_id(),(string)get_current_user_id()); }
        $utc=new \DateTimeZone('UTC');$where.=$wpdb->prepare(' AND p.post_date_gmt>=%s AND p.post_date_gmt<%s',$period->from->setTimezone($utc)->format(self::DATABASE_DATE_FORMAT),$period->until->setTimezone($utc)->format(self::DATABASE_DATE_FORMAT));$result=[];
        foreach($wpdb->get_results("SELECT t.term_id,COUNT(DISTINCT p.ID) total,COUNT(DISTINCT IF(p.post_type='ascla_resource',p.ID,NULL)) resources,COUNT(DISTINCT IF(p.post_type IN ('ascla_hub','ascla_forum','ascla_topic'),p.ID,NULL)) discussions FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=tr.term_taxonomy_id AND t.taxonomy='ascla_interest' WHERE $where GROUP BY t.term_id",ARRAY_A) as $r) {$result[(int)$r['term_id']]=array_map('intval',$r); }return $result;
    }
}
