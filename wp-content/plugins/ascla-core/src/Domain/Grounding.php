<?php
namespace ASCLA\Core\Domain;

/** Fail-closed factual output: publish source sentences, never inferred claims. */
final class Grounding
{
    public const POLICY='extractive-source-sentences-v1';
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
    private static function supported(string $claim,array $sentences): ?string
    {
        $key=self::normalize($claim);
        foreach($sentences as $sentence){if($key!==''&&$key===self::normalize($sentence)){ return $sentence; }}
        return null;
    }
    public static function facts(array $claims,string $source): array
    {
        $sentences=self::sentences($source);$safe=[];
        foreach(array_slice($claims,0,40) as $claim){
            $text=is_array($claim)?($claim['quote']??$claim['text']??''):$claim;
            if(!is_string($text)){ continue; }
            $supported=self::supported($text,$sentences);if($supported!==null){ $safe[]=$supported; }
        }
        return array_values(array_unique($safe));
    }
    public static function text(string $candidate,string $source): string
    {
        return implode("\n\n",self::facts(self::sentences($candidate),$source));
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
        $ids=array_map('intval',(array)($result['source_ids']??[]));$claims=self::sentences((string)($result['answer']??''));
        foreach((array)($result['claims']??[]) as $claim){if(is_array($claim)&&is_string($claim['quote']??null)){ $claims[]=$claim['quote']; }}
        $safe=[];$used=[];
        foreach($sources as $source){
            if(!in_array((int)$source['id'],$ids,true)){ continue; }
            $facts=self::facts($claims,$source['body']);
            if($facts){$safe=array_merge($safe,$facts);$used[]=$source;}
        }
        return ['answer'=>implode("\n\n",array_unique($safe)),'sources'=>$used,'grounding'=>['policy'=>self::POLICY,'verified_sentences'=>count($safe),'unsupported_removed'=>count($claims)>count($safe)]];
    }
    private static function timeline(array $items,string $source): array
    {
        $safe=[];
        foreach(array_slice($items,0,20) as $item){
            if(!is_array($item)){ continue; }
            $facts=self::facts([$item],$source);$date=(string)($item['date']??'');
            if($facts&&$date!==''&&self::terms([$date],$facts[0])){ $safe[]=['date'=>$date,'text'=>$facts[0]]; }
        }
        return $safe;
    }
    public static function multimedia(array $result,string $source): array
    {
        $sentences=self::sentences($source);$summary=self::text((string)($result['summary']??''),$source);
        $note=self::text((string)($result['technical_note']??''),$source);
        $result['summary']=$summary?:implode(' ',array_slice($sentences,0,3));
        $result['technical_note']=$note?:implode("\n\n",array_slice($sentences,0,8));
        $result['suggested_hub']=self::text((string)($result['suggested_hub']??''),$source)?:$result['summary'];
        foreach(['conclusions','concepts'] as $field){ $result[$field]=self::facts((array)($result[$field]??[]),$source); }
        foreach(['frameworks','norms','tags'] as $field){ $result[$field]=self::terms((array)($result[$field]??[]),$source); }
        $info=(array)($result['infographic']??[]);
        $result['infographic']=['title'=>'Claves de la sesión','key_points'=>self::facts((array)($info['key_points']??[]),$source),'sections'=>self::facts((array)($info['sections']??[]),$source),'statistics'=>self::facts((array)($info['statistics']??[]),$source),'timeline'=>self::timeline((array)($info['timeline']??[]),$source)];
        if(!$result['infographic']['key_points']){ $result['infographic']['key_points']=array_slice($sentences,0,5); }
        $result['grounding']=['policy'=>self::POLICY,'review_required'=>true,'summary_fallback'=>$summary==='','note_fallback'=>$note===''];
        return $result;
    }
}
