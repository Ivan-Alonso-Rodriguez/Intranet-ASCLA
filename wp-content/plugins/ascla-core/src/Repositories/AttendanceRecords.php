<?php
namespace ASCLA\Core\Repositories;
use ASCLA\Core\Services\Profiles;
final class AttendanceRecords
{
    public static function roster(int $event,int $page,int $duration,bool $complete): array
    {
        global $wpdb;$event=absint($event);$att=Store::table('attendance');$reg=Store::table('registrations');
        $q=new \WP_User_Query();$q->prepare_query(['capability'=>'ascla_access','fields'=>'ID','number'=>-1,'count_total'=>false]);$members="SELECT DISTINCT {$wpdb->users}.ID ".$q->query_from.' '.$q->query_where;
        $base="FROM (SELECT user_id FROM $reg WHERE event_id=$event AND status='accepted' UNION SELECT user_id FROM $att WHERE event_id=$event) ids JOIN ($members) m ON m.ID=ids.user_id JOIN {$wpdb->users} u ON u.ID=ids.user_id LEFT JOIN $att a ON a.event_id=$event AND a.user_id=ids.user_id LEFT JOIN $reg r ON r.event_id=$event AND r.user_id=ids.user_id AND r.status='accepted'";
        $s=$wpdb->get_row("SELECT COUNT(*) total,COALESCE(SUM(a.status IN ('present','partial','review')),0) entered,COALESCE(SUM(a.status='present'),0) present,COALESCE(SUM(a.status='partial'),0) partial,COALESCE(SUM(a.status='review'),0) review,COALESCE(SUM(a.status='absent' OR (a.id IS NULL AND r.id IS NOT NULL AND ".(int)$complete."=1)),0) absent,COUNT(r.id) registered,COALESCE(SUM(a.status='present' AND r.id IS NOT NULL),0) registered_present $base",ARRAY_A);$s=array_map('intval',$s);$s['rate']=$complete && $s['registered']?round(100*$s['registered_present']/$s['registered'],1):null;
        $page=max(1,$page);$rows=$wpdb->get_results("SELECT u.ID,u.user_email,IF(r.id IS NULL,0,1) registered,a.* $base ORDER BY u.ID LIMIT 20 OFFSET ".(($page-1)*20),ARRAY_A);$uids=array_map('intval',array_column($rows,'ID'));if($uids)cache_users($uids);$people=[];
        foreach($rows as $r)$people[]=['id'=>(int)$r['ID'],'name'=>Profiles::publicName((int)$r['ID']),'email'=>$r['user_email'],'registered'=>(bool)$r['registered'],'status'=>$r['status']??'unknown','minutes'=>$r['minutes']===null?null:(int)$r['minutes'],'seconds'=>$r['seconds']===null?null:(int)$r['seconds'],'source'=>$r['source']??'','sessions'=>json_decode($r['sessions']??'',true)?:[],'review_reason'=>$r['review_reason']??'','percent'=>$r['seconds']===null?null:round(100*(int)$r['seconds']/$duration,2)];
        return ['people'=>$people,'summary'=>$s,'page'=>$page,'pages'=>max(1,(int)ceil($s['total']/20)),'total'=>$s['total']];
    }
}
