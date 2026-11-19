<?php
namespace ASCLA\Core\Repositories;

use ASCLA\Core\Services\{Content,EditorialPayload};

/** Exclude matches on text a reader cannot see, before SQL counts and pagination. */
final class EditorialSearch
{
    private const TYPES=['ascla_resource','ascla_gallery','ascla_event'];

    public static function readerConstraint(string $search,\WP_Query $query,string $text,array $authors): string
    {
        $types=array_values(array_intersect((array)$query->get('post_type'),self::TYPES));
        if (!$types) { return ''; }
        global $wpdb;
        $placeholders=implode(',',array_fill(0,count($types),'%s'));
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders)",...$types).$search));
        self::requireSuccessfulRead();
        if (!$ids) { return $wpdb->prepare(" AND {$wpdb->posts}.post_type NOT IN ($placeholders)",...$types); }
        $posts=get_posts(['post_type'=>$types,'post_status'=>$query->get('post_status')?:'publish','post__in'=>$ids,'numberposts'=>-1,'suppress_filters'=>true]);
        self::requireSuccessfulRead();
        $allowed=[];$authors=array_map('intval',$authors);
        foreach ($posts as $post) {
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            self::requireSuccessfulRead();
            if (empty($meta['chatham']) || Content::canEdit($post) || in_array((int)$post->post_author,$authors,true)
                || self::matchesVisibleText($post,$meta,$text)) { $allowed[]=(int)$post->ID; }
        }
        // Newly matching posts must also be checked, rather than bypassing an exclusion list.
        return $wpdb->prepare(" AND ({$wpdb->posts}.post_type NOT IN ($placeholders) OR {$wpdb->posts}.ID IN (".implode(',',$allowed?:[0]).'))',...$types);
    }

    private static function requireSuccessfulRead(): void
    {
        global $wpdb;
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo completar la búsqueda.'); }
    }

    private static function matchesVisibleText(\WP_Post $post,array $meta,string $text): bool
    {
        [$title,$body,$visible]=EditorialPayload::reader($post,$meta,$post->post_title,$post->post_content);
        $terms=wp_get_object_terms($post->ID,['ascla_interest','ascla_category','ascla_tag'],['fields'=>'names']);
        $labels=is_wp_error($terms)?'':implode(' ',$terms);
        $haystack=remove_accents($title.' '.$body.' '.($visible['source']??'').' '.$labels);
        return mb_stripos($haystack,remove_accents($text))!==false;
    }
}
