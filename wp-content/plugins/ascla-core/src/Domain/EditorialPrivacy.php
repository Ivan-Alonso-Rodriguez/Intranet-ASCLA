<?php
namespace ASCLA\Core\Domain;

/** Redacted reader projection; the original editorial source is never overwritten. */
final class EditorialPrivacy
{
    /** Published reference titles are editorial labels, not inferred speaker identities. */
    public static function title(string $title,array $meta): string
    {
        if (empty($meta['chatham'])) { return $title; }
        $identities=preg_split('/[\r\n,;]+/u',(string)($meta['identities']??''))?:[];
        return EntityRedactor::redactEntities($title,$identities);
    }
    public static function reader(string $title,string $body,array $meta): array
    {
        $identities=preg_split('/[\r\n,;]+/u',(string)($meta['identities']??''))?:[];
        if(!empty($meta['chatham'])){
            $title=self::title($title,$meta);
            $body=EntityRedactor::redact($body,$identities,[$title]);
            foreach(['source','description','summary','technical_note','video_source_title','infographic','moments','excerpts','frameworks','norms','conclusions','concepts','tags'] as $field){
                if(isset($meta[$field])){$meta[$field]=EntityRedactor::tree($meta[$field],$identities,[$title]);}
            }
            foreach(['moments','excerpts'] as $field){
                if(isset($meta[$field]) && is_array($meta[$field])){$meta[$field]=Transcript::videoLinks($meta[$field],(string)($meta['video_id']??''));}
            }
        }
        unset($meta['transcript'],$meta['identities'],$meta['transcript_error'],$meta['transcript_mode'],$meta['transcript_checked_at']);
        return ['title'=>$title,'body'=>$body,'meta'=>$meta];
    }
}
