<?php
namespace ASCLA\Core\Repositories;

use ASCLA\Core\Services\Access;

/** Publication dates use the site's calendar, including both complete boundary days. */
final class ContentDateRange
{
    public static function query(array $filter): array
    {
        $range=[];$dates=[];
        foreach (['after','before'] as $key) {
            $value=$filter[$key]??'';
            if ($value==='') { continue; }
            Access::require(is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$parts)===1,'Fecha de filtro no válida.',400);
            [$year,$month,$day]=array_map('intval',array_slice($parts,1));
            Access::require($year>=1000 && checkdate($month,$day,$year),'Fecha de filtro no válida.',400);
            $dates[$key]=$value;
            $range[$key]=['year'=>$year,'month'=>$month,'day'=>$day];
        }
        Access::require(!isset($dates['after'],$dates['before']) || $dates['after']<=$dates['before'],'La fecha inicial no puede ser posterior a la fecha final.',400);
        return $range ? [$range+['inclusive'=>true,'column'=>'post_date']] : [];
    }

    /** Events overlapping either complete boundary day in the site's calendar. */
    public static function events(array $filter): array
    {
        $range=self::query($filter)[0]??[];$clauses=[];
        foreach (['after','before'] as $key) {
            if (!isset($range[$key])) { continue; }
            $date=$range[$key];$text=sprintf('%04d-%02d-%02d',$date['year'],$date['month'],$date['day']);
            $boundary=new \DateTimeImmutable($text.($key==='after'?' 00:00:00':' 23:59:59'),wp_timezone());
            $clauses[]=['key'=>$key==='after'?'_ascla_end':'_ascla_start','value'=>$boundary->setTimezone(new \DateTimeZone('UTC'))->format('c'),'compare'=>$key==='after'?'>=':'<='];
        }
        return $clauses;
    }
}
