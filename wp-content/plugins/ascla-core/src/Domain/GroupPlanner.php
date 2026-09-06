<?php
namespace ASCLA\Core\Domain;
final class GroupPlanner
{
    /** Deterministic grouping, minimum 4 and maximum 6. Remaining <4 wait next month. */
    public static function plan(array $profiles,array $history=[],int $rotation=0): array
    {
        usort($profiles,static fn($a,$b)=>$a['id']<=>$b['id']);
        if ($profiles) { $shift=$rotation%count($profiles); $profiles=array_merge(array_slice($profiles,$shift),array_slice($profiles,0,$shift)); }
        $groups=[];
        while (count($profiles)>=4) {
            $count=count($profiles); $size=($count>=8 && $count<=11)?intdiv($count,2):min(6,$count);
            if ($count-$size>0&&$count-$size<4) { $size=$count>=8?$count-4:$size; }
            $group=[array_shift($profiles)];
            while (count($group)<$size) {
                $bestKey=0; $best=-INF;
                foreach ($profiles as $key=>$candidate) {
                    $score=0;
                    foreach ($group as $member) {
                        $ids=[$member['id'],$candidate['id']]; sort($ids);
                        $score+=MatchScore::calculate($member,$candidate)['score']-50*($history[implode(':',$ids)]??0);
                    }
                    if ($score>$best) { $best=$score; $bestKey=$key; }
                }
                $group[]=$profiles[$bestKey]; array_splice($profiles,$bestKey,1);
            }
            $groups[]=array_column($group,'id');
        }
        return ['groups'=>$groups,'waiting'=>array_column($profiles,'id')];
    }
}
