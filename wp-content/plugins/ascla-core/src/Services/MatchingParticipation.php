<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** Privacy-safe community participation signals used by matching. */
final class MatchingParticipation
{
    /** @var array<string,array<int,string>> */
    private static array $cache=[];

    public static function topics(int $userId): array
    {
        $cacheKey=get_current_user_id().':'.$userId;
        if (isset(self::$cache[$cacheKey])) { return self::$cache[$cacheKey]; }
        $ids=self::postIds($userId);
        if (!$ids) { return []; }
        $topics=self::termKeys($ids);
        sort($topics,SORT_STRING);
        return self::$cache[$cacheKey]=array_slice($topics,0,60);
    }

    private static function postIds(int $userId): array
    {
        $ids=[];
        foreach (Store::rows('relations','user_id=%d AND kind IN (%s,%s)',[$userId,'like','follow'],'ORDER BY id DESC LIMIT 60') as $row) { $ids[]=(int)$row['target_id']; }
        foreach (get_comments(['user_id'=>$userId,'status'=>'approve','number'=>50,'fields'=>'ids']) as $commentId) {
            $comment=get_comment((int)$commentId);
            if ($comment) { $ids[]=(int)$comment->comment_post_ID; }
        }
        $authored=get_posts([
            'author'=>$userId,
            'post_type'=>['ascla_hub','ascla_topic','ascla_resource','ascla_gallery'],
            'post_status'=>'publish','posts_per_page'=>30,'fields'=>'ids','orderby'=>'date','order'=>'DESC',
        ]);
        return array_values(array_unique(array_merge($ids,array_map('intval',$authored))));
    }

    private static function termKeys(array $ids): array
    {
        $topics=[];
        foreach (array_slice($ids,0,80) as $postId) {
            $post=get_post($postId);
            if (!$post || $post->post_status!=='publish' || !str_starts_with($post->post_type,'ascla_') || !Content::canRead($post)) { continue; }
            foreach (['ascla_interest','ascla_category','ascla_tag'] as $taxonomy) {
                $terms=wp_get_object_terms($postId,$taxonomy,['fields'=>'ids']);
                if (is_wp_error($terms)) { continue; }
                foreach ($terms as $termId) { $topics[]=$taxonomy.':'.(int)$termId; }
            }
        }
        return array_values(array_unique($topics));
    }
}
