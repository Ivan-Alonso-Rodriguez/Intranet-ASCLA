<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/**
 * RF-041: rank knowledge resources using three complementary layers:
 * explicit profile preferences, professional context and recent relevant
 * community activity. Recency only breaks ties after topical relevance exists.
 */
final class KnowledgeRecommendations
{
    /** @var array<int,array<int,string>> */
    private static array $activityCache=[];

    public static function invalidateActivity(int $userId): void
    {
        unset(self::$activityCache[$userId]);
    }

    public static function score(\WP_Post $post, int $userId=0): array
    {
        $userId=$userId?:get_current_user_id();
        $invalid=!$userId || $post->post_type!=='ascla_resource' || $post->post_status!=='publish' || (int)$post->post_author===$userId;
        if ($invalid) { return ['score'=>0,'relevance'=>0,'reasons'=>[]]; }

        $profile=Profiles::raw($userId);
        $labels=Profiles::labels($profile);
        $resourceInterests=wp_get_object_terms($post->ID,'ascla_interest',['fields'=>'ids']);
        $resourceInterests=is_wp_error($resourceInterests)?[]:array_map('intval',$resourceInterests);
        $profileInterests=array_map('intval',(array)($profile['interests']??[]));
        $direct=count(array_intersect($resourceInterests,$profileInterests));
        $relevance=$direct?min(70,50+(max(0,$direct-1)*10)):0;
        $reasons=$direct?['interest_match']:[];

        $tags=self::termNames($post->ID,['ascla_tag']);
        [$keywordScore,$keywordReasons]=self::keywordScore($tags,$labels,$profile);
        $relevance+=min(35,$keywordScore);
        $reasons=array_merge($reasons,$keywordReasons);

        [$activityScore,$activityReason]=self::activityScore($post,$userId,$tags);
        $relevance+=min(18,$activityScore);
        if ($activityReason!=='') { $reasons[]=$activityReason; }

        $relevance=min(90,$relevance);
        if ($relevance<=0) { return ['score'=>0,'relevance'=>0,'reasons'=>[]]; }
        $recency=self::recencyScore($post);
        if ($recency>0) { $reasons[]='recent'; }
        return [
            'score'=>(int)min(100,$relevance+$recency),
            'relevance'=>(int)$relevance,
            'reasons'=>array_values(array_unique($reasons)),
        ];
    }

    private static function keywordScore(array $tags,array $labels,array $profile): array
    {
        if (!$tags) { return [0,[]]; }
        $professional=array_values(array_filter([
            trim((string)($profile['position']??'')),
            trim((string)($profile['experience']??'')),
        ]));
        $dimensions=[
            ['labels'=>(array)($labels['interests']??[]),'exact'=>18,'fuzzy'=>10,'reason'=>'keyword_interest'],
            ['labels'=>(array)($labels['areas']??[]),'exact'=>14,'fuzzy'=>8,'reason'=>'keyword_area'],
            ['labels'=>(array)($labels['industries']??[]),'exact'=>8,'fuzzy'=>4,'reason'=>'keyword_industry'],
            ['labels'=>(array)($labels['goals']??[]),'exact'=>6,'fuzzy'=>3,'reason'=>'keyword_goal'],
            ['labels'=>$professional,'exact'=>12,'fuzzy'=>7,'reason'=>'professional_match'],
        ];
        $score=0;$reasons=[];
        foreach ($tags as $tag) {
            [$best,$reason]=self::bestKeywordDimension((string)$tag,$dimensions);
            $score+=$best;
            if ($reason!=='' && !in_array($reason,$reasons,true)) { $reasons[]=$reason; }
        }
        return [$score,$reasons];
    }

    private static function bestKeywordDimension(string $tag,array $dimensions): array
    {
        $best=0;$reason='';
        foreach ($dimensions as $dimension) {
            $match=self::match($tag,$dimension['labels']);
            $value=match($match) {'exact'=>$dimension['exact'],'fuzzy'=>$dimension['fuzzy'],default=>0};
            if ($value>$best) { $best=$value;$reason=$dimension['reason']; }
        }
        return [$best,$reason];
    }

    private static function activityScore(\WP_Post $post,int $userId,array $tags): array
    {
        $activity=self::activityLabels($userId);
        if (!$activity) { return [0,'']; }
        $resourceLabels=array_values(array_unique(array_merge(
            self::termNames($post->ID,['ascla_interest','ascla_category','ascla_tag']),
            $tags
        )));
        $score=0;
        foreach ($resourceLabels as $label) {
            $score+=match(self::match((string)$label,$activity)) {'exact'=>8,'fuzzy'=>4,default=>0};
        }
        return [$score,$score>0?'activity_match':''];
    }

    private static function recencyScore(\WP_Post $post): int
    {
        $timestamp=strtotime($post->post_date_gmt.' UTC')?:strtotime($post->post_date)?:0;
        $days=$timestamp?max(0,(int)floor((time()-$timestamp)/DAY_IN_SECONDS)):9999;
        return match(true) {$days<=14=>10,$days<=30=>6,$days<=90=>3,default=>0};
    }

    /** @return string[] */
    private static function activityLabels(int $userId): array
    {
        if (isset(self::$activityCache[$userId])) { return self::$activityCache[$userId]; }
        $ids=[];
        foreach (Store::rows('relations','user_id=%d AND kind IN (%s,%s)',[$userId,'like','follow'],'ORDER BY id DESC LIMIT 80') as $row) {
            $ids[]=(int)$row['target_id'];
        }
        foreach (get_comments(['user_id'=>$userId,'status'=>'approve','number'=>60,'fields'=>'ids']) as $commentId) {
            $comment=get_comment((int)$commentId); if ($comment) { $ids[]=(int)$comment->comment_post_ID; }
        }
        $authored=get_posts([
            'author'=>$userId,
            'post_type'=>['ascla_hub','ascla_topic','ascla_resource','ascla_gallery'],
            'post_status'=>'publish','posts_per_page'=>30,'fields'=>'ids','orderby'=>'date','order'=>'DESC',
        ]);
        $ids=array_values(array_unique(array_merge($ids,array_map('intval',$authored))));

        $labels=[];
        foreach (array_slice($ids,0,100) as $postId) {
            $item=get_post($postId);
            if (!$item || $item->post_status!=='publish' || !str_starts_with($item->post_type,'ascla_') || !Content::canRead($item)) { continue; }
            $labels=array_merge($labels,self::termNames($postId,['ascla_interest','ascla_category','ascla_tag']));
        }
        $labels=array_values(array_unique(array_filter(array_map('strval',$labels))));
        return self::$activityCache[$userId]=array_slice($labels,0,80);
    }

    /** @return string[] */
    private static function termNames(int $postId,array $taxonomies): array
    {
        $terms=wp_get_object_terms($postId,$taxonomies,['fields'=>'names']);
        return is_wp_error($terms)?[]:array_values(array_filter(array_map('strval',$terms)));
    }

    private static function match(string $keyword,array $labels): string
    {
        $needle=self::normalize($keyword);
        if ($needle==='') { return ''; }
        $needleTokens=self::tokens($needle);
        $result='';
        foreach ($labels as $label) {
            $candidate=self::normalize((string)$label);
            if ($candidate==='') { continue; }
            $exact=$needle===$candidate || $needle===self::acronym($candidate) || self::acronym($needle)===$candidate;
            if ($exact) { $result='exact';break; }
            $candidateTokens=self::tokens($candidate);
            if (!$needleTokens || !$candidateTokens) { continue; }
            $shared=count(array_intersect($needleTokens,$candidateTokens));
            $denominator=max(1,min(count($needleTokens),count($candidateTokens)));
            if ($shared>=1 && ($shared/$denominator)>=0.6) { $result='fuzzy';break; }
        }
        return $result;
    }

    private static function normalize(string $value): string
    {
        $value=mb_strtolower(remove_accents(wp_strip_all_tags($value)));
        $value=preg_replace('/[^a-z0-9]+/u',' ',trim($value))??'';
        return trim(preg_replace('/\s+/',' ',$value)??'');
    }

    private static function tokens(string $value): array
    {
        $stop=['de','del','la','las','el','los','y','en','para','con','por','a','un','una','que','the','and','for','with','from'];
        return array_values(array_filter(explode(' ',$value),static fn($token)=>$token!=='' && mb_strlen($token)>=2 && !in_array($token,$stop,true)));
    }

    private static function acronym(string $value): string
    {
        $tokens=self::tokens($value);
        if (count($tokens)<2) { return ''; }
        return implode('',array_map(static fn($token)=>$token[0]??'', $tokens));
    }
}
