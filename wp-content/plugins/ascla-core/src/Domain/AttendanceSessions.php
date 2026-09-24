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
        $timestamp=null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})$/D',$value)) {
            try { $timestamp=(new \DateTimeImmutable($value))->getTimestamp(); } catch (\Exception) { $timestamp=null; }
        } else {
            $timestamp=self::formattedTimestamp($value,$options);
        }
        return $timestamp;
    }

    private static function formattedTimestamp(string $value,array $options): ?int
    {
        $dates=match($options['date_format']){'dmy'=>['d/m/Y','d-m-Y'],'mdy'=>['m/d/Y','m-d-Y'],default=>['Y-m-d','Y/m/d']};
        $timestamp=null;
        foreach ($dates as $date) {
            foreach (['H:i:s','H:i','h:i:s A','h:i A'] as $time) {
                $parsed=\DateTimeImmutable::createFromFormat('!'.$date.' '.$time,$value,new \DateTimeZone($options['timezone']));
                $errors=\DateTimeImmutable::getLastErrors();
                if ($parsed && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))) {
                    $timestamp=$parsed->getTimestamp();
                    break 2;
                }
            }
        }
        return $timestamp;
    }
    public static function calculate(array $rows,int $start,int $end,int $threshold,array $options): array
    {
        [$sessions,$intervals,$warnings,$fallback]=self::attendanceEvidence($rows,$start,$end,$options);
        $merged=self::mergeIntervals($intervals);
        [$seconds,$warnings]=self::attendanceSeconds($rows,$intervals,$fallback,$merged,$start,$end,$warnings);
        $reason=implode(' ',array_unique($warnings));
        $known=$reason==='';
        $percent=$known?round(100*$seconds/($end-$start),2):null;
        $status=self::attendanceStatus($known,$seconds,$start,$end,$threshold);
        return ['status'=>$status,'seconds'=>$known?$seconds:null,'minutes'=>$known?(int)ceil($seconds/60):null,'percent'=>$percent,'sessions'=>$sessions,'intervals'=>$merged,'review_reason'=>$reason,'connections'=>count($rows)];
    }

    private static function attendanceEvidence(array $rows,int $start,int $end,array $options): array
    {
        $sessions=[];$intervals=[];$warnings=[];$fallback=[];
        foreach ($rows as $row) {
            $a=self::timestamp($row['join'],$options);$z=self::timestamp($row['leave'],$options);
            $sessions[]=['line'=>$row['line'],'join'=>$row['join'],'leave'=>$row['leave'],'reported_seconds'=>$row['seconds'],'start'=>$a,'end'=>$z];
            if ($a!==null && $z!==null && $z>$a) {
                $a=max($start,$a);$z=min($end,$z);
                if ($z>$a) { $intervals[]=[$a,$z]; }
            } elseif ($row['join']!=='' || $row['leave']!=='') {
                $warnings[]='Horario no válido o ambiguo; revisa el formato y la zona horaria.';
            } elseif ($row['seconds']!==null) {
                $fallback[]=$row['seconds'];
            } else {
                $warnings[]='Falta evidencia de duración.';
            }
        }
        return [$sessions,$intervals,$warnings,$fallback];
    }

    private static function mergeIntervals(array $intervals): array
    {
        usort($intervals,static fn($a,$b)=>$a[0]<=>$b[0]);
        $merged=[];
        foreach ($intervals as $interval) {
            $last=count($merged)-1;
            if ($last>=0 && $interval[0]<=$merged[$last][1]) { $merged[$last][1]=max($merged[$last][1],$interval[1]); }
            else { $merged[]=$interval; }
        }
        return $merged;
    }

    private static function attendanceSeconds(array $rows,array $intervals,array $fallback,array $merged,int $start,int $end,array $warnings): array
    {
        $seconds=array_sum(array_map(static fn($interval)=>$interval[1]-$interval[0],$merged));
        if ($fallback) {
            if (count($rows)===1 && !$intervals) { $seconds=min($end-$start,max(0,$fallback[0])); }
            else { $warnings[]='Reconexiones sin horarios: no se pueden descartar intervalos solapados.'; }
        }
        return [$seconds,$warnings];
    }

    private static function attendanceStatus(bool $known,int|float $seconds,int $start,int $end,int $threshold): string
    {
        if (!$known) { return 'review'; }
        if ($seconds===0) { return 'absent'; }
        return $seconds*100>=($end-$start)*$threshold?'present':'partial';
    }
}
