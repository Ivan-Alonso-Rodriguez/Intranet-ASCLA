<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\StatisticsPeriod;
use ASCLA\Core\Repositories\StatisticsQueries as Queries;
use ASCLA\Core\Repositories\StatisticsRepository as Data;

/** All numbers are derived here from stored records; AI never supplies counts or percentages. */
final class Statistics
{
    public static function context(array $filter): array
    {
        Attendance::authorize();$period=new StatisticsPeriod($filter);$terms=Data::terms('interest');$categories=Data::terms('category');
        $topic=absint($filter['topic']??0);$category=absint($filter['category']??0);$event=absint($filter['event']??0);
        Access::require(!$topic || in_array($topic,array_column($terms,'id'),true),'Tema no válido.',400);
        Access::require(!$category || in_array($category,array_column($categories,'id'),true),'Categoría no válida.',400);
        Access::require(!$event || Data::eventExists($period,$event),'El evento no está disponible en este período.',400);
        $eventFilter=compact('topic','category','event');
        $currentScope=Data::eventScopeSql($period,$eventFilter,'current');$previousScope=Data::eventScopeSql($period,$eventFilter,'previous');$allScope=Data::eventScopeSql($period,$eventFilter,'all');
        return compact('period','terms','categories','topic','category','event','eventFilter','currentScope','previousScope','allScope');
    }

    public static function dashboard(array $filter): array
    {
        $c=self::context($filter);$page=max(1,(int)($filter['page']??1));$eventPage=max(1,(int)($filter['event_page']??1));
        $now=Queries::cohort($c['currentScope'],$page);$before=Queries::cohort($c['previousScope'],1,false);$declared=Queries::declared();$content=Queries::content($c['period']);$eventTopics=Queries::eventTopicCounts($c['currentScope']);$topics=[];
        foreach($c['terms'] as $term){$tid=$term['id'];if($c['topic'] && $tid!==$c['topic'])continue;
            $n=$now['topics'][$tid]??[];$p=$before['topics'][$tid]??[];$d=$declared[$tid]??[];$published=$content[$tid]??[];
            $item=$term+['declared'=>$d['declared']??0,'profile'=>$d['profile']??0,'forms'=>$d['forms']??0,'events'=>$eventTopics[$tid]??0,'registrations'=>$n['registrations']??0,'attendees'=>$n['attendees']??0,'attendances'=>$n['attendances']??0,'previous_attendances'=>$p['attendances']??0,'content'=>$published['total']??0,'resources'=>$published['resources']??0,'discussions'=>$published['discussions']??0];
            $item['change']=StatisticsPeriod::change($item['attendances'],$item['previous_attendances']);$topics[]=$item;
        }
        usort($topics,static fn($a,$b)=>($b['attendances']<=>$a['attendances']) ?: (($b['declared']<=>$a['declared']) ?: strcmp($a['name'],$b['name'])));
        $summary=$now['summary']+Queries::membersSummary($c['period']);$trends=[];
        foreach(['events','registrations','attendees','attendances','recurring'] as $key)$trends[]=['metric'=>$key,'current'=>$now['summary'][$key],'previous'=>$before['summary'][$key],'change'=>StatisticsPeriod::change($now['summary'][$key],$before['summary'][$key])];
        $eventResult=Data::eventPage($c['period'],$c['eventFilter'],$eventPage,20,'current');$counts=Queries::eventCounts($c['currentScope'],array_column($eventResult['items'],'id'));$eventRows=[];
        foreach($eventResult['items'] as $e)$eventRows[]=$e+($counts[$e['id']]??['registered'=>0,'attended'=>0]);
        $query=Access::text($filter['event_search']??'',100);$optionResult=Data::eventOptions($c['period'],$c['event'],$query,100);
        return ['period'=>$c['period']->output(),'filters'=>['topic'=>$c['topic'],'category'=>$c['category'],'event'=>$c['event'],'event_search'=>$query],'summary'=>$summary,'previous'=>$before['summary'],'ranking'=>$now['ranking'],'ranking_total'=>$now['ranking_total'],'ranking_page'=>$page,'topics'=>$topics,'trends'=>$trends,'events'=>$eventRows,'events_total'=>$eventResult['total'],'event_page'=>$eventPage,'index_complete'=>get_option('ascla_interest_index_cursor',false)===false,'options'=>['topics'=>$c['terms'],'categories'=>$c['categories'],'events'=>$optionResult['items'],'events_total'=>$optionResult['total']]];
    }
}
