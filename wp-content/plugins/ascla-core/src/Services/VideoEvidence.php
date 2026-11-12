<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\EntityRedactor;
use ASCLA\Core\Repositories\WordPressWrites;

/** Searchable reader material from the original resource, never the private editorial payload. */
final class VideoEvidence
{
    public const INDEX='_ascla_video_evidence';

    public static function text(array $meta): string
    {
        if (!self::readable($meta)) { return ''; }

        $lines=[];
        $append=static function(string $label,mixed $values) use (&$lines): void {
            self::appendValues($lines,$label,$values);
        };
        foreach (['summary'=>'Resumen','technical_note'=>'Nota técnica','conclusions'=>'Conclusión','frameworks'=>'Marco','norms'=>'Norma','concepts'=>'Concepto','tags'=>'Etiqueta','ai_topics'=>'Tema','suggested_hub'=>'Texto sugerido para el Hub'] as $key=>$label) {
            $append($label,$meta[$key]??[]);
        }
        $info=(array)($meta['infographic']??[]);
        foreach (['title','sections','key_points','statistics'] as $key) { $append('Infografía',$info[$key]??[]); }
        self::appendTimeline($info,$append);
        foreach (array_merge((array)($meta['moments']??[]),(array)($meta['excerpts']??[])) as $clip) {
            if (is_array($clip)) { self::appendClip($clip,$append); }
        }
        $text=implode("\n",array_keys($lines));
        if ($text!=='' && !empty($meta['chatham'])) {
            $text=EntityRedactor::redact($text,preg_split('/[\r\n,;]+/u',(string)($meta['identities']??''))?:[]);
        }
        return $text;
    }

    private static function appendValues(array &$lines,string $label,mixed $values): void
    {
        foreach ((array)$values as $value) {
            if (!is_string($value)) { continue; }
            $value=trim(wp_strip_all_tags($value));
            if ($value!=='') { $lines[$label.' · '.$value]=true; }
        }
    }

    private static function appendTimeline(array $info,callable $append): void
    {
        foreach ((array)($info['timeline']??[]) as $event) {
            if (is_array($event) && is_string($event['text']??null)) { $append('Cronología',trim((is_scalar($event['date']??null)?$event['date']:'').' '.$event['text'])); }
        }
    }

    private static function readable(array $meta): bool
    {
        $video=!empty($meta['video_id']) || ($meta['resource_type']??'')==='Video' || !empty($meta['generated_sections']) || !empty($meta['ai_enriched']);
        $reviewed=(empty($meta['generated']) || !empty($meta['reviewed'])) && (!isset($meta['ai_reviewed']) || $meta['ai_reviewed']);
        return $video && $reviewed && (!isset($meta['ai_source_signature']) || $meta['ai_source_signature']===VideoPreparation::signature($meta));
    }

    private static function appendClip(array $clip,callable $append): void
    {
        $seconds=max(0,(int)($clip['start']??0));
        $stamp=sprintf('%d:%02d',intdiv($seconds,60),$seconds%60);
        $end=(int)($clip['end']??0);
        if ($end>$seconds) { $stamp.=sprintf('–%d:%02d',intdiv($end,60),$end%60); }
        $parts=array_filter(array_intersect_key($clip,array_flip(['title','description','selection','reason'])),'is_string');
        $append('Cápsula '.$stamp,implode(' · ',array_unique($parts)));
    }

    public static function index(int $id,array $meta): void
    {
        $text=self::text($meta);
        if ($text==='') { WordPressWrites::deleteMeta('post',$id,self::INDEX); }
        else { WordPressWrites::meta('post',$id,self::INDEX,$text,true); }
    }

    public static function deleted(mixed $ids,int $post,string $key): void
    {
        unset($ids); // WordPress supplies deleted meta IDs before the post and key.
        if ($key==='_ascla' && get_post_type($post)==='ascla_resource') { WordPressWrites::deleteMeta('post',$post,self::INDEX); }
    }
}
