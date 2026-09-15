<?php
namespace ASCLA\Core\Domain;

/**
 * Deterministic affinity score.
 *
 * Explicit profile taxonomies remain the strongest signals. Professional
 * experience and public community participation act as bounded secondary
 * signals so they improve recommendations without replacing the member's
 * explicit interests and networking preferences.
 */
final class MatchScore
{
    public const WEIGHTS=['interests'=>30,'areas'=>25,'industries'=>20,'goals'=>15,'languages'=>10];
    private const EXPERIENCE_BONUS=15;
    private const PARTICIPATION_BONUS=5;

    public static function calculate(array $a,array $b,array $weights=self::WEIGHTS): array
    {
        $weights=array_intersect_key($weights,self::WEIGHTS); $total=array_sum($weights); $base=0; $factors=[];
        foreach (self::WEIGHTS as $field=>$default) {
            $left=array_values(array_unique((array)($a[$field]??[]))); $right=array_values(array_unique((array)($b[$field]??[])));
            $union=array_values(array_unique(array_merge($left,$right))); $common=array_values(array_intersect($left,$right));
            $ratio=$union?count($common)/count($union):0;
            $weight=max(0,(float)($weights[$field]??0));
            $base+=$ratio*$weight; $factors[$field]=['common'=>$common,'ratio'=>$ratio,'weight'=>$weight];
        }

        $baseScore=$total>0?max(0,min(100,$base/$total*100)):0;

        // RF-040: experience is only evaluated when it is available to the
        // matching process. Hidden profile fields are removed by Matching
        // before this method is called, so privacy choices remain effective.
        $experience=self::professionalSimilarity($a,$b);
        $experienceBonus=$experience['ratio']*self::EXPERIENCE_BONUS;
        $factors['experience']=[
            'common'=>$experience['common'],
            'ratio'=>$experience['ratio'],
            'weight'=>self::EXPERIENCE_BONUS,
        ];

        // Participation contains only privacy-safe topic signatures prepared
        // by the service layer from community content both users may access.
        $leftParticipation=array_values(array_unique((array)($a['participation']??[])));
        $rightParticipation=array_values(array_unique((array)($b['participation']??[])));
        $participationUnion=array_values(array_unique(array_merge($leftParticipation,$rightParticipation)));
        $participationCommon=array_values(array_intersect($leftParticipation,$rightParticipation));
        $participationRatio=$participationUnion?count($participationCommon)/count($participationUnion):0;
        $participationBonus=$participationRatio*self::PARTICIPATION_BONUS;
        $factors['participation']=[
            'common'=>$participationCommon,
            'ratio'=>$participationRatio,
            'weight'=>self::PARTICIPATION_BONUS,
        ];

        return [
            'score'=>(int)round(max(0,min(100,$baseScore+$experienceBonus+$participationBonus))),
            'base_score'=>(int)round($baseScore),
            'factors'=>$factors,
        ];
    }

    private static function professionalSimilarity(array $a,array $b): array
    {
        $left=self::professionalTokens($a); $right=self::professionalTokens($b);
        if (count($left)<2 || count($right)<2) { return ['ratio'=>0.0,'common'=>[]]; }
        $common=array_values(array_intersect($left,$right));
        if (!$common) { return ['ratio'=>0.0,'common'=>[]]; }
        $union=array_values(array_unique(array_merge($left,$right)));
        $jaccard=count($common)/max(1,count($union));
        $overlap=count($common)/max(1,min(count($left),count($right)));
        // The overlap component gives useful weight to a focused shared area
        // even when one member has a much longer experience description.
        $ratio=max(0,min(1,($jaccard*0.35)+($overlap*0.65)));
        return ['ratio'=>$ratio,'common'=>array_slice($common,0,6)];
    }

    private static function professionalTokens(array $profile): array
    {
        $text=trim(implode(' ',array_filter([
            (string)($profile['position']??''),
            (string)($profile['experience']??''),
        ])));
        if ($text==='') { return []; }
        $text=mb_strtolower(remove_accents(wp_strip_all_tags($text)));
        $text=preg_replace('/[^a-z0-9]+/u',' ',$text)??'';
        $stop=[
            'de','del','la','las','el','los','y','e','en','para','por','con','sin','un','una','unos','unas','al','a','o','u',
            'que','como','desde','hasta','sobre','entre','durante','su','sus','mi','mis','the','and','for','with','from','into',
            'experiencia','profesional','profesionales','trabajo','trabajos','empresa','empresas','anos','year','years',
        ];
        $tokens=[];
        foreach (preg_split('/\s+/',trim($text))?:[] as $token) {
            if ($token==='' || mb_strlen($token)<3 || in_array($token,$stop,true) || ctype_digit($token)) { continue; }
            $tokens[]=$token;
        }
        return array_values(array_unique($tokens));
    }
}
