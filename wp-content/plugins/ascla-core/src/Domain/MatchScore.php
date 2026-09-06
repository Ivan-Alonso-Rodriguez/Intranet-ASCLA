<?php
namespace ASCLA\Core\Domain;
final class MatchScore
{
    public const WEIGHTS=['interests'=>30,'areas'=>25,'industries'=>20,'goals'=>15,'languages'=>10];
    public static function calculate(array $a,array $b,array $weights=self::WEIGHTS): array
    {
        $weights=array_intersect_key($weights,self::WEIGHTS); $total=array_sum($weights); $score=0; $factors=[];
        foreach (self::WEIGHTS as $field=>$default) {
            $left=array_unique((array)($a[$field]??[])); $right=array_unique((array)($b[$field]??[]));
            $union=array_unique(array_merge($left,$right)); $common=array_values(array_intersect($left,$right));
            $ratio=$union?count($common)/count($union):0;
            $weight=max(0,(float)($weights[$field]??0));
            $score+=$ratio*$weight; $factors[$field]=['common'=>$common,'ratio'=>$ratio,'weight'=>$weight];
        }
        return ['score'=>$total>0?(int)round(max(0,min(100,$score/$total*100))):0,'factors'=>$factors];
    }
}
