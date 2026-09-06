<?php
namespace ASCLA\Core\Repositories;

/** Rank the published corpus before limiting candidates; age never excludes a source. */
final class KnowledgeSearch
{
    public static function candidates(array $tokens): array
    {
        if (!$tokens) { return []; }
        global $wpdb;
        $expressions=[]; $parameters=[];
        foreach ($tokens as $token) {
            $expressions[]='(CASE WHEN post_title LIKE %s OR post_content LIKE %s THEN 1 ELSE 0 END)';
            $like='%'.$wpdb->esc_like($token).'%';
            $parameters[]=$like; $parameters[]=$like;
        }
        $score=implode(' + ',$expressions);
        $parameters[]='ascla_resource'; $parameters[]='publish';
        $parameters[]=max(1,(int)ceil(count($tokens)*0.5));
        $sql="SELECT ID, ($score) AS relevance FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s HAVING relevance >= %d ORDER BY relevance DESC, post_date DESC, ID DESC LIMIT 100";
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare($sql,...$parameters)));
        if ($ids) { _prime_post_caches($ids,true,true); }
        return array_values(array_filter(array_map('get_post',$ids)));
    }
}
