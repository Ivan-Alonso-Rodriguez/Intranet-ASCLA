<?php
namespace ASCLA\Core\Repositories;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Services\Access;

/** Apply reader permissions before SQL counts and pagination. */
final class ContentVisibility
{
    public static function where(string $sql, \WP_Query $query): string
    {
        $types=(array)$query->get('post_type');
        $known=array_map(static fn($type)=>'ascla_'.$type,array_keys(Catalog::TYPES));
        if (!$types || array_diff($types,$known)) { return $sql; }
        if (!Access::member()) { return $sql.' AND 1=0'; }
        return current_user_can('ascla_manage')?$sql:self::memberWhere($sql,$types);
    }
    private static function memberWhere(string $sql,array $types): string
    {
        global $wpdb;
        $clauses=[];
        foreach ($types as $type) {
            $clauses[]=$wpdb->prepare("({$wpdb->posts}.post_type=%s AND ", $type).self::permission(substr($type,6)).')';
        }
        return $sql.' AND ('.implode(' OR ',$clauses).')';
    }

    private static function permission(string $type): string
    {
        global $wpdb;
        if ($type==='contact' || (in_array($type,['hub','forum','topic'],true) && current_user_can('ascla_moderate'))) { return '1=1'; }
        if ($type==='ally') { return "{$wpdb->posts}.post_status='publish'"; }
        return $wpdb->prepare("({$wpdb->posts}.post_author=%d OR ({$wpdb->posts}.post_status='publish' AND ",get_current_user_id()).self::microevent().'))';
    }

    private static function microevent(): string
    {
        global $wpdb;
        return $wpdb->prepare("(NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} vm WHERE vm.post_id={$wpdb->posts}.ID AND vm.meta_key='_ascla_micro' AND vm.meta_value='1')
            OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} vp WHERE vp.post_id={$wpdb->posts}.ID AND vp.meta_key='_ascla_micro_public_at' AND CAST(vp.meta_value AS SIGNED)>0 AND CAST(vp.meta_value AS SIGNED)<=%d)
            OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} vi WHERE vi.post_id={$wpdb->posts}.ID AND vi.meta_key='_ascla_invitee' AND vi.meta_value=%s))",time(),(string)get_current_user_id());
    }
}
