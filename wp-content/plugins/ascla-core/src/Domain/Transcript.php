<?php
namespace ASCLA\Core\Domain;
final class Transcript
{
    public static function moments(string $text): array
    {
        preg_match_all('/(?:\[|^)(?:(\d{1,2}):)?(\d{2}):(\d{2})(?:[,.]\d{3})?\]?[^\n]*\n?/m',$text,$matches,PREG_SET_ORDER);
        $moments=[];
        foreach ($matches as $m) {
            $start=(int)($m[1]?:0)*3600+(int)$m[2]*60+(int)$m[3];
            $label=trim(preg_replace('/^\[?[\d:,.]+\]?\s*/','',$m[0]));
            $moments[]=['start'=>$start,'title'=>mb_substr($label,0,150)];
        }
        $out=[];
        for ($i=0;$i<count($moments)-1;$i++) {
            $duration=$moments[$i+1]['start']-$moments[$i]['start'];
            if ($duration>=60 && $duration<=180) { $out[]=$moments[$i]+['end'=>$moments[$i+1]['start']]; }
        }
        return array_slice($out,0,8);
    }
}
