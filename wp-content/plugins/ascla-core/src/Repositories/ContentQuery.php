<?php
namespace ASCLA\Core\Repositories;

use ASCLA\Core\Services\Access;

/** Search and sorting run before pagination, inside the private content query. */
final class ContentQuery
{
    public static function search(string $sql, \WP_Query $query): string
    {
        if (!$query->get('ascla_search')) { return $sql; }
        $text = Access::text($query->get('s'), 150);
        if ($text === '') { return $sql; }
        global $wpdb;
        $like = '%' . $wpdb->esc_like($text) . '%';
        return $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s
            OR EXISTS (SELECT 1 FROM {$wpdb->users} au WHERE au.ID={$wpdb->posts}.post_author AND au.display_name LIKE %s)
            OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} am WHERE am.post_id={$wpdb->posts}.ID AND am.meta_key='_ascla_source' AND am.meta_value LIKE %s)
            OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} ar INNER JOIN {$wpdb->term_taxonomy} atx ON ar.term_taxonomy_id=atx.term_taxonomy_id INNER JOIN {$wpdb->terms} atr ON atr.term_id=atx.term_id WHERE ar.object_id={$wpdb->posts}.ID AND atx.taxonomy IN ('ascla_interest','ascla_category','ascla_tag') AND atr.name LIKE %s))", $like, $like, $like, $like, $like);
    }

    public static function authors(): array
    {
        global $wpdb;
        $visibility = current_user_can('ascla_moderate') ? "p.post_status IN ('publish','draft','pending','ascla_rejected','ascla_hidden')" : "p.post_status='publish'";
        return $wpdb->get_results("SELECT DISTINCT u.ID AS id,u.display_name AS name FROM {$wpdb->users} u INNER JOIN {$wpdb->posts} p ON p.post_author=u.ID WHERE p.post_type='ascla_resource' AND $visibility ORDER BY u.display_name,u.ID", ARRAY_A);
    }

    public static function filters(array $args, string $type, array $filter): array
    {
        $args['ascla_search'] = true;
        $args['posts_per_page'] = max(1, min(100, (int)($filter['per_page'] ?? 18)));
        $args['orderby'] = ['date' => 'DESC', 'ID' => 'DESC'];
        if ($type === 'topic') { $args['orderby'] = ['modified' => 'DESC', 'ID' => 'DESC']; }
        foreach (['tag' => 'interest', 'category' => 'category', 'keyword' => 'tag'] as $key => $taxonomy) {
            if (!empty($filter[$key])) { $args['tax_query'][] = ['taxonomy' => 'ascla_' . $taxonomy, 'field' => 'term_id', 'terms' => absint($filter[$key])]; }
        }
        if ($type !== 'event') { return $args; }
        $args['meta_key'] = '_ascla_start';
        $direction = rest_sanitize_boolean($filter['past'] ?? false) ? 'DESC' : 'ASC';
        $args['orderby'] = ['meta_value' => $direction, 'ID' => $direction];
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
