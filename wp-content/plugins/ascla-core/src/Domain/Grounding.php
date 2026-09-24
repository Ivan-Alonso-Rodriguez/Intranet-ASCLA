<?php
namespace ASCLA\Core\Domain;

/** Preserve natural generated language; validate citation membership, not sentence equality. */
final class Grounding
{
    public const POLICY='source-references-v2';
    public static function normalize(string $text): string
    {
        $text=html_entity_decode(strip_tags($text),ENT_QUOTES,'UTF-8');
        return mb_strtolower(trim(preg_replace('/\s+/u',' ',$text)," \t\n\r\0\x0B.?!•-\"“”"));
    }
    public static function sentences(string $text): array
    {
        $segments=Transcript::segments($text);
        if($segments){ $text=implode("\n",array_column($segments,'text')); }
        $parts=preg_split('/(?<=[.!?])\s+|\n+/u',strip_tags($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
        return array_values(array_filter(array_map('trim',$parts),static fn($p)=>mb_strlen($p)>=12));
    }
    public static function terms(array $terms,string $source): array
    {
        $safe=[];
        foreach(array_slice($terms,0,30) as $term){
            if(!is_string($term)||mb_strlen($term)>180){ continue; }
            $term=trim($term);
            if($term!==''&&preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term,'/').'(?![\p{L}\p{N}])/iu',$source)){ $safe[]=$term; }
        }
        return array_values(array_unique($safe));
    }
    public static function answer(array $result,array $sources): array
    {
        $ids=[];
        foreach((array)($result['source_ids']??[]) as $id) { if((is_int($id)||is_string($id)) && ctype_digit((string)$id) && (int)$id>0) { $ids[]=(int)$id; } }
        $ids=array_values(array_unique($ids));$used=[];
        foreach($sources as $source) { if(in_array((int)$source['id'],$ids,true)) { $used[]=$source; } }
        $text=is_string($result['answer']??null)?$result['answer']:'';
        return ['answer'=>$text,'sources'=>$used,'grounding'=>['policy'=>self::POLICY,'context_source_ids'=>array_map('intval',array_column($sources,'id')),'valid_references'=>count($used),'ignored_references'=>count(array_diff($ids,array_column($used,'id')))]];
    }
    private static function strings(array $values,int $limit=40): array
    {
        return array_values(array_filter(array_slice($values,0,$limit),static fn($value)=>is_string($value)&&trim($value)!==''));
    }
    public static function multimedia(array $result,string $source): array
    {
        unset($source);
        foreach(['summary','technical_note','suggested_hub'] as $field) { $result[$field]=is_string($result[$field]??null)?$result[$field]:''; }
        foreach(['frameworks','norms','conclusions','concepts','tags','topics'] as $field) { $result[$field]=self::strings((array)($result[$field]??[])); }
        $info=is_array($result['infographic']??null)?$result['infographic']:[];
        $result['infographic']=['title'=>is_string($info['title']??null)?$info['title']:'Claves de la sesión'];
        foreach(['key_points','sections','statistics'] as $field) { $result['infographic'][$field]=self::strings((array)($info[$field]??[])); }
        $result['infographic']['timeline']=array_values(array_filter(array_slice((array)($info['timeline']??[]),0,20),static fn($item)=>is_array($item)&&is_string($item['date']??null)&&is_string($item['text']??null)));
        $result['grounding']=['policy'=>self::POLICY,'review_required'=>true];
        return $result;
    }
}
