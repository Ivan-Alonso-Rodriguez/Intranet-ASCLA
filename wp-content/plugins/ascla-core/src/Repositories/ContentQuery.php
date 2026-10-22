<?php
namespace ASCLA\Core\Repositories;

use ASCLA\Core\Services\Access;
use ASCLA\Core\Services\Profiles;

/** Search and sorting run before pagination, inside the private content query. */
final class ContentQuery
{
    public static function draftVisibility(string $sql, \WP_Query $query): string
    {
        if (!$query->get('ascla_search')) { return $sql; }
        global $wpdb;
        $sql.=$wpdb->prepare(" AND ({$wpdb->posts}.post_status <> 'draft' OR {$wpdb->posts}.post_author = %d)", get_current_user_id());
        return ContentVisibility::where($sql,$query);
    }

    public static function search(string $sql, \WP_Query $query): string
    {
        if ((array)$query->get('post_type')===['ascla_ally'] && ($query->get('ascla_ally_search') || $query->get('ascla_search'))) { return AllySearch::search($query); }
        $text = $query->get('ascla_search')?Access::text($query->get('s'), 150):'';
        if ($text === '') { return $sql; }
        global $wpdb;
        $like = '%' . $wpdb->esc_like($text) . '%';
        $authorIds=Profiles::searchableAuthors($text);
        $authorSql=$authorIds?' OR '.$wpdb->posts.'.post_author IN ('.implode(',',array_map('intval',$authorIds)).')':'';
        $legacySql='';
        if (preg_match('/C[ií]rculo\h+ASCLA/iu',$text)) {
            $aliases=[];
            foreach (['participante','identidad reservada'] as $label) {
                $alias=preg_replace('/C[ií]rculo\h+ASCLA/iu',$label,$text);
                $aliases[]=$wpdb->prepare("{$wpdb->posts}.post_title LIKE %s",'%'.$wpdb->esc_like($alias).'%');
            }
            $legacySql=" OR ({$wpdb->posts}.post_type='ascla_event' AND (".implode(' OR ',$aliases).") AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} lm WHERE lm.post_id={$wpdb->posts}.ID AND lm.meta_key='_ascla_micro' AND lm.meta_value='1'))";
        }
        $allySql=in_array('ascla_ally',(array)$query->get('post_type'),true)?' OR '.AllySearch::typeClause($text):'';
        $search=$wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s
            $authorSql
            OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} am WHERE am.post_id={$wpdb->posts}.ID AND am.meta_key='_ascla_source' AND am.meta_value LIKE %s)
            OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} ar INNER JOIN {$wpdb->term_taxonomy} atx ON ar.term_taxonomy_id=atx.term_taxonomy_id INNER JOIN {$wpdb->terms} atr ON atr.term_id=atx.term_id WHERE ar.object_id={$wpdb->posts}.ID AND atx.taxonomy IN ('ascla_interest','ascla_category','ascla_tag') AND atr.name LIKE %s)", $like, $like, $like, $like).$legacySql.$allySql.')';
        return $search.EditorialSearch::readerConstraint($search,$query,$text,$authorIds);
    }

    public static function authors(): array
    {
        global $wpdb;
        $visibility = current_user_can('ascla_moderate') ? $wpdb->prepare("(p.post_status IN ('publish','pending','ascla_rejected','ascla_hidden') OR (p.post_status='draft' AND p.post_author=%d))",get_current_user_id()) : "p.post_status='publish'";
        $authors=$wpdb->get_results("SELECT DISTINCT u.ID AS id,u.display_name AS name FROM {$wpdb->users} u INNER JOIN {$wpdb->posts} p ON p.post_author=u.ID WHERE p.post_type='ascla_resource' AND $visibility ORDER BY u.display_name,u.ID", ARRAY_A);
        return array_map(static fn($author)=>['id'=>$author['id'],'name'=>Profiles::publicName((int)$author['id'])],$authors);
    }

    public static function filters(array $args, string $type, array $filter): array
    {
        $args['ascla_search'] = true;
        $args['date_query'] = $type==='event' ? [] : ContentDateRange::query($filter);
        $args['posts_per_page'] = max(1, min(100, (int)($filter['per_page'] ?? 18)));
        $args['orderby'] = ['date' => 'DESC', 'ID' => 'DESC'];
        if ($type === 'topic') { $args['orderby'] = ['modified' => 'DESC', 'ID' => 'DESC']; }
        foreach (['tag' => 'interest', 'category' => 'category', 'keyword' => 'tag'] as $key => $taxonomy) {
            if (!empty($filter[$key])) { $args['tax_query'][] = ['taxonomy' => 'ascla_' . $taxonomy, 'field' => 'term_id', 'terms' => absint($filter[$key])]; }
        }
        if ($type !== 'event') { return $args; }
        foreach (ContentDateRange::events($filter) as $clause) { $args['meta_query'][]=$clause; }
        $modality=$filter['modality']??'';
        if ($modality!=='') {
            Access::require(in_array($modality,['Virtual','Presencial','Híbrido'],true),'Modalidad no válida.',400);
            $args['meta_query'][]=['key'=>'_ascla_modality','value'=>$modality];
        }
        if (rest_sanitize_boolean($filter['micro']??false)) { $args['meta_query'][]=['key'=>'_ascla_micro','value'=>'1']; }
        // Own publications and undated drafts must not require a start-date meta row.
        if (!rest_sanitize_boolean($filter['mine']??false) || array_key_exists('past',$filter)) {
            $args['meta_key'] = '_ascla_start';
            $direction = rest_sanitize_boolean($filter['past'] ?? false) ? 'DESC' : 'ASC';
            $args['orderby'] = ['meta_value' => $direction, 'ID' => $direction];
        }
        return self::monthFilters($args,$filter);
    }

    private static function monthFilters(array $args,array $filter): array
    {
        if (!empty($filter['month'])) {
            $month = Access::text($filter['month'], 7);
            Access::require((bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 'Mes no válido.', 400);
            $offset=max(-840,min(840,(int)($filter['tz_offset']??0)));
            $localStart = new \DateTimeImmutable($month . '-01', new \DateTimeZone('UTC'));
            $shift = ($offset>=0?'+':'').$offset.' minutes';
            $start = $localStart->modify($shift);
            $end = $localStart->modify('+1 month')->modify($shift)->modify('-1 second');
            $args['meta_query'][] = ['key' => '_ascla_start', 'value' => $end->setTimezone(new \DateTimeZone('UTC'))->format('c'), 'compare' => '<='];
            $args['meta_query'][] = ['key' => '_ascla_end', 'value' => $start->setTimezone(new \DateTimeZone('UTC'))->format('c'), 'compare' => '>='];
        }
        return $args;
    }
}
