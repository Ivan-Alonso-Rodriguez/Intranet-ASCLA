<?php
namespace ASCLA\Core\Repositories;

use ASCLA\Core\Services\Access;
use ASCLA\Core\Frontend\Language;

/** RF-077: query visible names and agreement types before counting or paginating. */
final class AllySearch
{
    private const LABELS=['Socio estratégico','Convenio','Otro','Alianza institucional'];

    public static function value(array $meta): string
    {
        $value=is_string($meta['alliance_type']??null)?trim($meta['alliance_type']):'';
        return self::normalize($value!==''?$value:'Alianza institucional');
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(remove_accents($value));
    }

    /** Standalone clause is also safe to include in the global content search. */
    public static function typeClause(string $text): string
    {
        global $wpdb;
        $needle=self::normalize($text);
        $match=$wpdb->prepare('ally_type.meta_value LIKE %s','%'.$wpdb->esc_like($needle).'%');
        foreach(self::LABELS as $label) {
            if (mb_stripos(self::normalize(Language::label($label)),$needle)!==false) {
                $match.=$wpdb->prepare(' OR ally_type.meta_value=%s',self::normalize($label));
            }
        }
        $fallback='';
        if (mb_stripos(self::normalize(Language::label('Alianza institucional')),$needle)!==false || mb_stripos('alianza institucional',$needle)!==false) {
            $fallback=" OR NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} ally_original WHERE ally_original.post_id={$wpdb->posts}.ID AND ally_original.meta_key='_ascla')";
        }
        return "({$wpdb->posts}.post_type='ascla_ally' AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} ally_type WHERE ally_type.post_id={$wpdb->posts}.ID AND ally_type.meta_key='_ascla_alliance_type' AND ($match))$fallback))";
    }

    public static function search(\WP_Query $query): string
    {
        $text=Access::text($query->get('s'),150);
        if ($text==='') { return ''; }
        global $wpdb;$like='%'.$wpdb->esc_like($text).'%';
        return $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR ",$like,$like).self::typeClause($text).')';
    }

    public static function deleted(mixed $metaIds,int $post,string $key,mixed $old): void
    {
        unset($metaIds,$old);
        if ($key==='_ascla' && get_post_type($post)==='ascla_ally') { WordPressWrites::deleteMeta('post',$post,'_ascla_alliance_type'); }
    }
}
