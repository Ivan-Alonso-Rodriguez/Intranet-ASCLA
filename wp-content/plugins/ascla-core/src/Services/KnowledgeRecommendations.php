<?php
namespace ASCLA\Core\Services;

/**
 * Ranks knowledge resources using explicit profile interests first and
 * AI-generated keywords only as a secondary relevance signal.
 */
final class KnowledgeRecommendations
{
    public static function score(\WP_Post $post, int $userId=0): array
    {
        $userId=$userId?:get_current_user_id();
        if (!$userId || $post->post_type!=='ascla_resource' || $post->post_status!=='publish') {
            return ['score'=>0,'relevance'=>0,'reasons'=>[]];
        }
        $profile=Profiles::raw($userId);
        $labels=Profiles::labels($profile);
        $resourceInterests=wp_get_object_terms($post->ID,'ascla_interest',['fields'=>'ids']);
        $resourceInterests=is_wp_error($resourceInterests)?[]:array_map('intval',$resourceInterests);
        $profileInterests=array_map('intval',(array)($profile['interests']??[]));
        $direct=count(array_intersect($resourceInterests,$profileInterests));

        $relevance=$direct?min(70,50+(max(0,$direct-1)*10)):0;
        $reasons=$direct?['interest_match']:[];

        $tags=wp_get_object_terms($post->ID,'ascla_tag',['fields'=>'names']);
        $tags=is_wp_error($tags)?[]:array_values(array_filter(array_map('strval',$tags)));
        if ($tags) {
            $keywordScore=0;
            $dimensions=[
                ['labels'=>(array)($labels['interests']??[]),'exact'=>18,'fuzzy'=>10,'reason'=>'keyword_interest'],
                ['labels'=>(array)($labels['areas']??[]),'exact'=>14,'fuzzy'=>8,'reason'=>'keyword_area'],
                ['labels'=>(array)($labels['industries']??[]),'exact'=>8,'fuzzy'=>4,'reason'=>'keyword_industry'],
                ['labels'=>(array)($labels['goals']??[]),'exact'=>6,'fuzzy'=>3,'reason'=>'keyword_goal'],
            ];
            foreach ($tags as $tag) {
                $best=0;$bestReason='';
                foreach ($dimensions as $dimension) {
                    $match=self::match((string)$tag,$dimension['labels']);
                    $value=$match==='exact'?$dimension['exact']:($match==='fuzzy'?$dimension['fuzzy']:0);
                    if ($value>$best) { $best=$value;$bestReason=$dimension['reason']; }
                }
                if ($best>0) {
                    $keywordScore+=$best;
                    if ($bestReason!=='' && !in_array($bestReason,$reasons,true)) { $reasons[]=$bestReason; }
                }
            }
            // Keywords help ranking, but can never outweigh explicit interests by themselves.
            $relevance+=min(35,$keywordScore);
        }
        if ($relevance<=0) { return ['score'=>0,'relevance'=>0,'reasons'=>[]]; }

        $timestamp=strtotime($post->post_date_gmt.' UTC')?:strtotime($post->post_date)?:0;
        $days=$timestamp?max(0,(int)floor((time()-$timestamp)/DAY_IN_SECONDS)):9999;
        $recency=$days<=14?10:($days<=30?6:($days<=90?3:0));
        if ($recency) { $reasons[]='recent'; }
        return ['score'=>$relevance+$recency,'relevance'=>$relevance,'reasons'=>$reasons];
    }

    private static function match(string $keyword,array $labels): string
    {
        $needle=self::normalize($keyword);
        if ($needle==='') { return ''; }
        $needleTokens=self::tokens($needle);
        foreach ($labels as $label) {
            $candidate=self::normalize((string)$label);
            if ($candidate==='') { continue; }
            if ($needle===$candidate || $needle===self::acronym($candidate) || self::acronym($needle)===$candidate) { return 'exact'; }
            $candidateTokens=self::tokens($candidate);
            if (!$needleTokens || !$candidateTokens) { continue; }
            $shared=count(array_intersect($needleTokens,$candidateTokens));
            $denominator=max(1,min(count($needleTokens),count($candidateTokens)));
            if ($shared>=1 && ($shared/$denominator)>=0.6) { return 'fuzzy'; }
        }
        return '';
    }

    private static function normalize(string $value): string
    {
        $value=mb_strtolower(remove_accents(wp_strip_all_tags($value)));
        $value=preg_replace('/[^a-z0-9]+/u',' ',trim($value))??'';
        return trim(preg_replace('/\s+/',' ',$value)??'');
    }

    private static function tokens(string $value): array
    {
        $stop=['de','del','la','las','el','los','y','en','para','con','por','a'];
        return array_values(array_filter(explode(' ',$value),static fn($token)=>$token!=='' && !in_array($token,$stop,true)));
    }

    private static function acronym(string $value): string
    {
        $tokens=self::tokens($value);
        if (count($tokens)<2) { return ''; }
        return implode('',array_map(static fn($token)=>$token[0]??'', $tokens));
    }
}
