<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Services\Access;

final class StatisticsPeriod
{
    public readonly \DateTimeImmutable $from;
    public readonly \DateTimeImmutable $until;
    public readonly \DateTimeImmutable $previous;
    public readonly int $days;
    public function __construct(array $input)
    {
        $today=new \DateTimeImmutable('today',wp_timezone());
        $parse=static function($value) {
            $text=Access::text($value,10);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$text,wp_timezone());
            Access::require($date && $date->format('Y-m-d')===$text,'Usa fechas válidas con formato AAAA-MM-DD.',400);
            return $date;
        };
        $this->from=$parse($input['from']??$today->modify('-89 days')->format('Y-m-d'));
        $to=$parse($input['to']??$today->format('Y-m-d'));
        Access::require($this->from<=$to && $to<=$today,'El período debe terminar hoy o antes y comenzar antes de su fin.',400);
        $this->days=(int)$this->from->diff($to)->days+1;
        Access::require($this->days<=366,'Selecciona un período de hasta 366 días.',400);
        $this->until=$to->modify('+1 day');$this->previous=$this->from->modify('-'.$this->days.' days');
    }
    public function output(): array
    {
        return ['from'=>$this->from->format('Y-m-d'),'to'=>$this->until->modify('-1 day')->format('Y-m-d'),'previous_from'=>$this->previous->format('Y-m-d'),'previous_to'=>$this->from->modify('-1 day')->format('Y-m-d'),'days'=>$this->days,'timezone'=>wp_timezone_string(),'includes_today'=>$this->until->format('Y-m-d')>(new \DateTimeImmutable('today',wp_timezone()))->format('Y-m-d')];
    }
    public static function change(int $current,int $previous): ?float
    {
        return $previous===0?($current===0?0.0:null):round(($current-$previous)*100/$previous,1);
    }
}
