<?php
namespace ASCLA\Core\Domain;

/** Redacted reader projection; the original editorial source is never overwritten. */
final class EditorialPrivacy
{
    public static function reader(string $title,string $body,array $meta): array
    {
        $identities=preg_split('/[\r\n,;]+/u',(string)($meta['identities']??''))?:[];
        if(!empty($meta['chatham'])){
            $title=EntityRedactor::redact($title,$identities);
            $body=EntityRedactor::redact($body,$identities);
            foreach(['source','description','summary','technical_note','video_source_title','infographic','moments','excerpts','frameworks','norms','conclusions','concepts','tags'] as $field){
                if(isset($meta[$field])){$meta[$field]=EntityRedactor::tree($meta[$field],$identities);}
            }
            foreach(['moments','excerpts'] as $field){
                if(isset($meta[$field]) && is_array($meta[$field])){$meta[$field]=Transcript::videoLinks($meta[$field],(string)($meta['video_id']??''));}
            }
        }
        unset($meta['transcript'],$meta['identities'],$meta['transcript_error'],$meta['transcript_mode'],$meta['transcript_checked_at']);
        return ['title'=>$title,'body'=>$body,'meta'=>$meta];
    }
}
