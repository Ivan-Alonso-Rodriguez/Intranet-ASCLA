<?php
namespace ASCLA\Core\Domain;

/** Conservative local entity detection; human review is still required. */
final class EntityRedactor
{
    public static function detectEntities(string $text,array $known=[]): array
    {
        $entities=[];
        foreach($known as $identity){if(is_string($identity)&&mb_strlen(trim($identity))>=3){ $entities[]=trim($identity); }}
        $word='[\p{Lu}][\p{L}’\x27-]*';
        $name=$word.'(?:\h+(?:(?:de|del|la)\h+)?'.$word.'){0,3}';
        $patterns=[
            '/[\p{L}\p{N}.+_-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u',
            '~https?://[^\s<>]+~iu',
            '/(?i:\b(?:ponente|director(?:a)?|gerente|president(?:e|a)|secretari[oa]|señor(?:a)?|doctor(?:a)?)\h+)('.$name.')/u',
            '/(?i:\b(?:gerente|director(?:a)?|president(?:e|a)|secretari[oa])\h+(?:de|en)\h+)'.$name.'/u',
            '/(?i:\b(?:empresa|compañía|compania|organización|organizacion|corporación|corporacion|universidad|fundación|fundacion|banco|grupo))\h+'.$name.'/u',
            '/(?<!\p{L})'.$word.'(?:\h+(?:(?:de|del|la)\h+)?'.$word.'){1,3}(?!\p{L})/u',
            '/^\h*('.$name.'):\h*/mu',
        ];
        $public=['COSO ERM','API REAL','Identidad reservada','ASCLA','Chatham House','Centro de Conocimiento','Gobierno Corporativo','Inteligencia Artificial','Gestión de Riesgos','Gobierno de IA','Secretaría Corporativa'];
        foreach($patterns as $pattern){
            preg_match_all($pattern,$text,$matches,PREG_SET_ORDER);
            foreach($matches as $match){$value=trim($match[1]??$match[0]);if(!in_array($value,$public,true)){ $entities[]=$value; }}
        }
        return array_values(array_unique($entities));
    }
    public static function redactEntities(string $text,array $entities): string
    {
        $entities=array_map('trim',array_filter($entities,'is_string'));
        usort($entities,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        foreach($entities as $entity){
            if(mb_strlen($entity)<2){ continue; }
            $replacement=(str_contains($entity,'@')||preg_match('~^https?://~iu',$entity))?'dato reservado':'identidad reservada';
            $text=preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($entity,'/').'(?![\p{L}\p{N}])/iu',$replacement,$text);
        }
        return $text;
    }
    public static function validateRedaction(string $text,array $known=[],array $references=[]): array
    {
        [$text]=self::maskReferences($text,$references,$known);
        $remaining=self::detectEntities($text,$known);
        $remaining=array_filter($remaining,static fn($entity)=>mb_stripos($text,$entity)!==false);
        return ['valid'=>!$remaining,'remaining_count'=>count($remaining)];
    }
    public static function redact(string $text,array $known=[],array $references=[]): string
    {
        // Explicitly reserved identities take precedence over public reference titles.
        $text=self::redactEntities($text,$known);
        [$text,$titles]=self::maskReferences($text,$references,$known);
        // A second pass catches an affiliation exposed when its person's name was removed.
        for($pass=0;$pass<2;$pass++){ $text=self::redactEntities($text,self::detectEntities($text,$known)); }
        $text=str_ireplace('[identidad reservada]','información reservada',$text);
        $text=preg_replace('/^\h*identidad reservada:\h*/mui','Identidad reservada: ',$text);
        return strtr($text,$titles);
    }
    public static function tree(mixed $value,array $known=[],array $references=[]): mixed
    {
        if(is_array($value)){ return array_map(static fn($item)=>self::tree($item,$known,$references),$value); }
        return is_string($value)?self::redact($value,$known,$references):$value;
    }
    private static function maskReferences(string $text,array $references,array $known): array
    {
        $titles=array_unique(array_filter(array_map(static fn($title)=>trim(self::redactEntities((string)$title,$known)),$references),static fn($title)=>mb_strlen($title)>=3));
        if (!$titles) { return [$text,[]]; }
        usort($titles,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        $pattern='/(?<![\p{L}\p{N}])(?:'.implode('|',array_map(static fn($title)=>preg_quote($title,'/'),$titles)).')(?![\p{L}\p{N}])/iu';
        $masked=[];
        $text=preg_replace_callback($pattern,static function($match)use(&$masked){
            $key='asclareference'.hash('sha256',$match[0]);$masked[$key]=$match[0];return $key;
        },$text);
        return [$text,$masked];
    }
}
