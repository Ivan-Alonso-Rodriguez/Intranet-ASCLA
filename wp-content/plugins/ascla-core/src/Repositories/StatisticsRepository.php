<?php
namespace ASCLA\Core\Repositories;
use ASCLA\Core\Domain\StatisticsPeriod;
use ASCLA\Core\Services\Profiles;

/** Event analytics use SQL scopes; UI pages materialize only the requested rows. */
final class StatisticsRepository
{
    public static function members(): array
    {
        $members=[];$page=1;
        do {
            $batch=get_users(['capability'=>'ascla_access','number'=>200,'paged'=>$page++,'orderby'=>'ID','order'=>'ASC']);
            foreach($batch as $user)$members[(int)$user->ID]=['name'=>Profiles::publicName((int)$user->ID),'registered'=>$user->user_registered,'interests'=>(array)(((array)get_user_meta($user->ID,'_ascla_profile',true))['interests']??[])];
        } while(count($batch)===200);
        return $members;
    }

    public static function terms(string $taxonomy): array
    {
        $terms=get_terms(['taxonomy'=>'ascla_'.$taxonomy,'hide_empty'=>false]);
        return is_wp_error($terms)?[]:array_map(static fn($t)=>['id'=>(int)$t->term_id,'name'=>$t->name],$terms);
    }

    /**
     * Prepared SQL selecting readable, published, finished and non-cancelled events.
     * The returned query is safe to embed as a subquery in statistics aggregates.
     */
    public static function eventScopeSql(StatisticsPeriod $period,array $filter=[],string $segment='all',string $search=''): string
    {
        global $wpdb;
        if(!in_array($segment,['all','current','previous'],true))throw new \InvalidArgumentException('Segmento estadístico no válido.');
        $lower=$segment==='current'?$period->from:($segment==='previous'?$period->previous:$period->previous);
        $upper=$segment==='previous'?$period->from:$period->until;
        $utc=new \DateTimeZone('UTC');
        $from=$lower->setTimezone($utc)->format('c');
        $until=$upper->setTimezone($utc)->format('c');
        $now=gmdate('c');
        $where=[
            "p.post_type='ascla_event'",
            "p.post_status='publish'",
            $wpdb->prepare('ending.meta_value >= %s AND ending.meta_value < %s AND ending.meta_value <= %s',$from,$until,$now),
            'start_meta.meta_value < ending.meta_value',
            "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} cancelled WHERE cancelled.post_id=p.ID AND cancelled.meta_key='_ascla_cancelled' AND cancelled.meta_value='1')",
            // Compatibility with pre-index events that have not been edited since _ascla_cancelled was introduced.
            $wpdb->prepare("NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} legacy_cancelled WHERE legacy_cancelled.post_id=p.ID AND legacy_cancelled.meta_key='_ascla' AND legacy_cancelled.meta_value LIKE %s)",'%'.$wpdb->esc_like('s:9:"cancelled";b:1;').'%'),
        ];
        if(!current_user_can('ascla_manage')){
            $uid=get_current_user_id();
            $where[]=$wpdb->prepare("(p.post_author=%d OR NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} micro WHERE micro.post_id=p.ID AND micro.meta_key='_ascla_micro' AND micro.meta_value='1') OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} invitee WHERE invitee.post_id=p.ID AND invitee.meta_key='_ascla_invitee' AND invitee.meta_value=%s))",$uid,(string)$uid);
        }
        $topic=absint($filter['topic']??0);$category=absint($filter['category']??0);$event=absint($filter['event']??0);
        if($topic)$where[]=$wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='ascla_interest' AND tt.term_id=%d)",$topic);
        if($category)$where[]=$wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=p.ID AND tt.taxonomy='ascla_category' AND tt.term_id=%d)",$category);
        if($event)$where[]=$wpdb->prepare('p.ID=%d',$event);
        $search=trim($search);
        if($search!=='')$where[]=$wpdb->prepare('p.post_title LIKE %s','%'.$wpdb->esc_like($search).'%');
        return "SELECT p.ID id,p.post_title title,ending.meta_value ending,
            EXISTS(SELECT 1 FROM {$wpdb->postmeta} complete WHERE complete.post_id=p.ID AND complete.meta_key='_ascla_attendance_complete' AND complete.meta_value NOT IN ('','0')) complete
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} ending ON ending.post_id=p.ID AND ending.meta_key='_ascla_end'
            JOIN {$wpdb->postmeta} start_meta ON start_meta.post_id=p.ID AND start_meta.meta_key='_ascla_start'
            WHERE ".implode(' AND ',$where);
    }

    public static function eventExists(StatisticsPeriod $period,int $event): bool
    {
        global $wpdb;$scope=self::eventScopeSql($period,['event'=>$event],'all');
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM ($scope) scoped")>0;
    }

    /** Exact server-side pagination for the attendance table. */
    public static function eventPage(StatisticsPeriod $period,array $filter,int $page=1,int $perPage=20,string $segment='current'): array
    {
        global $wpdb;$page=max(1,$page);$perPage=max(1,min(100,$perPage));$scope=self::eventScopeSql($period,$filter,$segment);
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM ($scope) scoped");
        $offset=($page-1)*$perPage;
        $items=$wpdb->get_results($wpdb->prepare("SELECT id,title,ending AS `end`,complete FROM ($scope) scoped ORDER BY ending DESC,id DESC LIMIT %d OFFSET %d",$perPage,$offset),ARRAY_A)?:[];
        foreach($items as &$item){$item['id']=(int)$item['id'];$item['complete']=(bool)$item['complete'];}unset($item);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'per_page'=>$perPage];
    }

    /** Search options are bounded independently from analytics aggregates. */
    public static function eventOptions(StatisticsPeriod $period,int $selected=0,string $search='',int $limit=100): array
    {
        global $wpdb;$limit=max(1,min(100,$limit));$search=trim($search);$scope=self::eventScopeSql($period,[],'all',$search);
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM ($scope) scoped");
        $items=$wpdb->get_results($wpdb->prepare("SELECT id,title,ending AS `end` FROM ($scope) scoped ORDER BY ending DESC,id DESC LIMIT %d",$limit),ARRAY_A)?:[];
        foreach($items as &$item)$item['id']=(int)$item['id'];unset($item);
        if($selected && !in_array($selected,array_column($items,'id'),true)){
            $selectedScope=self::eventScopeSql($period,['event'=>$selected],'all');
            $row=$wpdb->get_row("SELECT id,title,ending AS `end` FROM ($selectedScope) scoped LIMIT 1",ARRAY_A);
            if($row){$row['id']=(int)$row['id'];$items[]=$row;if($search!=='' && stripos($row['title'],$search)===false)++$total;}
        }
        return ['items'=>$items,'total'=>$total];
    }

    public static function eventsByIds(string $scope,array $ids): array
    {
        global $wpdb;$ids=array_values(array_unique(array_filter(array_map('absint',$ids))));if(!$ids)return [];$list=implode(',',$ids);
        $rows=$wpdb->get_results("SELECT id,title,ending AS `end`,complete FROM ($scope) scoped WHERE id IN ($list)",ARRAY_A)?:[];$result=[];
        if($rows)update_meta_cache('post',array_map('intval',array_column($rows,'id')));
        foreach($rows as $row){$row['id']=(int)$row['id'];$row['complete']=(bool)$row['complete'];$result[$row['id']]=$row;}
        return $result;
    }

    /** Iterate a potentially large event scope in bounded pages for AI/topic maintenance. */
    public static function eventBatches(string $scope,int $size=200): \Generator
    {
        global $wpdb;$size=max(1,min(500,$size));$after=0;
        do {
            $batch=$wpdb->get_results($wpdb->prepare("SELECT id,title,ending AS `end`,complete FROM ($scope) scoped WHERE id>%d ORDER BY id ASC LIMIT %d",$after,$size),ARRAY_A)?:[];
            if(!$batch)break;
            $ids=array_map('intval',array_column($batch,'id'));if($ids)update_meta_cache('post',$ids);
            foreach($batch as &$row){$row['id']=(int)$row['id'];$row['complete']=(bool)$row['complete'];}unset($row);
            $last=end($batch);$after=(int)$last['id'];yield $batch;
        } while(count($batch)===$size);
    }

    public static function rows(string $table,array $events): array
    {
        $rows=[];
        foreach(array_chunk(array_keys($events),200) as $ids) {
            $where='event_id IN ('.implode(',',array_fill(0,count($ids),'%d')).')';
            if($table==='registrations')$where.=" AND status='accepted'";
            foreach(Store::rows($table,$where,$ids,'ORDER BY id ASC') as $row)$rows[]=$row;
        }
        return $rows;
    }

    public static function activeUsers(StatisticsPeriod $period,array $members): int
    {
        global $wpdb;$utc=new \DateTimeZone('UTC');
        $ids=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT actor_id FROM '.Store::table('audit').' WHERE created_at>=%s AND created_at<%s AND actor_id>0 AND action<>%s',$period->from->setTimezone($utc)->format('Y-m-d H:i:s'),$period->until->setTimezone($utc)->format('Y-m-d H:i:s'),'request_failed'));
        return count(array_intersect(array_keys($members),array_map('intval',$ids)));
    }
}
