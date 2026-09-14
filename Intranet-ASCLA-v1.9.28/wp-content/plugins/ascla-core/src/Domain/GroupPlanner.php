<?php
namespace ASCLA\Core\Domain;
final class GroupPlanner
{
    /** Deterministic grouping, minimum 4 and maximum 6. Remaining <4 wait next month. */
    public static function plan(array $profiles,array $history=[],int $rotation=0,array $blocked=[]): array
    {
        usort($profiles,static fn($a,$b)=>$a['id']<=>$b['id']);
        if ($profiles) { $shift=$rotation%count($profiles); $profiles=array_merge(array_slice($profiles,$shift),array_slice($profiles,0,$shift)); }
        $groups=[]; $waiting=[];
        while (count($profiles)>=4) {
            $count=count($profiles); $size=($count>=8 && $count<=11)?intdiv($count,2):min(6,$count);
            $group=[array_shift($profiles)];
            while (count($group)<$size) {
                $bestKey=self::bestCandidate($profiles,$group,$history,$blocked);
                if($bestKey===null){ break; }
                $group[]=$profiles[$bestKey]; array_splice($profiles,$bestKey,1);
            }
            if(count($group)>=4){ $groups[]=array_column($group,'id'); }
            else { $waiting=array_merge($waiting,array_column($group,'id')); }
        }
        return ['groups'=>$groups,'waiting'=>array_merge($waiting,array_column($profiles,'id'))];
    }

    private static function bestCandidate(array $profiles,array $group,array $history,array $blocked): ?int
    {
        $bestKey=null;$best=-INF;
        foreach ($profiles as $key=>$candidate) {
            $score=self::candidateScore($candidate,$group,$history,$blocked);
            if ($score>$best) { $best=$score;$bestKey=$key; }
        }
        return $bestKey;
    }
    private static function candidateScore(array $candidate,array $group,array $history,array $blocked): float
    {
        $score=0;
        foreach ($group as $member) {
            $pair=[(int)$member['id'],(int)$candidate['id']];sort($pair);$key=implode(':',$pair);
            if (isset($blocked[$key])) { return -INF; }
            $score+=MatchScore::calculate($member,$candidate)['score']-50*($history[$key]??0);
        }
        return $score;
    }
}
