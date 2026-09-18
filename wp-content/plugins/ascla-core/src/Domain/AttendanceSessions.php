<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Services\Access;

/** Pure interval arithmetic: overlapping reconnects must never count twice. */
final class AttendanceSessions
{
    public static function options(array $input): array
    {
        $zone=(string)($input['timezone']??wp_timezone_string());$format=(string)($input['date_format']??'ymd');
        try{new \DateTimeZone($zone);}catch(\Exception $e){Access::require(false,'Zona horaria no válida.',400);}
        Access::require(in_array($format,['ymd','dmy','mdy'],true),'Formato de fecha no válido.',400);
        return ['timezone'=>$zone,'date_format'=>$format];
    }
    private static function timestamp(string $value,array $options): ?int
    {
        if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})$/D',$value)){
            try{return (new \DateTimeImmutable($value))->getTimestamp();}catch(\Exception $e){return null;}
        }
        $dates=match($options['date_format']){'dmy'=>['d/m/Y','d-m-Y'],'mdy'=>['m/d/Y','m-d-Y'],default=>['Y-m-d','Y/m/d']};
        foreach($dates as $date)foreach(['H:i:s','H:i','h:i:s A','h:i A'] as $time){
            $parsed=\DateTimeImmutable::createFromFormat('!'.$date.' '.$time,$value,new \DateTimeZone($options['timezone']));$errors=\DateTimeImmutable::getLastErrors();
            if($parsed && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])))return $parsed->getTimestamp();
        }
        return null;
    }
    public static function calculate(array $rows,int $start,int $end,int $threshold,array $options): array
    {
        $sessions=[];$intervals=[];$warnings=[];$fallback=[];
        foreach($rows as $r){
            $a=self::timestamp($r['join'],$options);$z=self::timestamp($r['leave'],$options);
            $session=['line'=>$r['line'],'join'=>$r['join'],'leave'=>$r['leave'],'reported_seconds'=>$r['seconds'],'start'=>$a,'end'=>$z];
            if($a!==null && $z!==null && $z>$a){$a=max($start,$a);$z=min($end,$z);if($z>$a)$intervals[]=[$a,$z];}
            elseif($r['join']!=='' || $r['leave']!=='')$warnings[]='Horario no válido o ambiguo; revisa el formato y la zona horaria.';
            elseif($r['seconds']!==null)$fallback[]=$r['seconds'];
            else $warnings[]='Falta evidencia de duración.';
            $sessions[]=$session;
        }
        usort($intervals,static fn($a,$b)=>$a[0]<=>$b[0]);$merged=[];
        foreach($intervals as $interval){$last=count($merged)-1;if($last>=0 && $interval[0]<=$merged[$last][1])$merged[$last][1]=max($merged[$last][1],$interval[1]);else $merged[]=$interval;}
        $seconds=array_sum(array_map(static fn($i)=>$i[1]-$i[0],$merged));
        if($fallback){if(count($rows)===1 && !$intervals)$seconds=min($end-$start,max(0,$fallback[0]));else $warnings[]='Reconexiones sin horarios: no se pueden descartar intervalos solapados.';}
        $reason=implode(' ',array_unique($warnings));$known=$reason==='';$percent=$known?round(100*$seconds/($end-$start),2):null;
        $status=!$known?'review':($seconds===0?'absent':($seconds*100>=($end-$start)*$threshold?'present':'partial'));
        return ['status'=>$status,'seconds'=>$known?$seconds:null,'minutes'=>$known?(int)ceil($seconds/60):null,'percent'=>$percent,'sessions'=>$sessions,'intervals'=>$merged,'review_reason'=>$reason,'connections'=>count($rows)];
    }
}
