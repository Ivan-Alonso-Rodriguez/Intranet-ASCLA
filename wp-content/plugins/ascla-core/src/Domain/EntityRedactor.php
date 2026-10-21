<?php
namespace ASCLA\Core\Domain;

/** Redact declared identities and speaker attribution, not arbitrary public proper nouns. */
final class EntityRedactor
{
    private const TOKEN='asclaprivateentity';
    public static function detectEntities(string $text,array $known=[]): array
    {
        $entities=[];
        foreach($known as $identity){if(is_string($identity)&&mb_strlen(trim($identity))>=2){ $entities[]=trim($identity); }}
        $word='[\p{Lu}][\p{L}’\x27-]*';
        $name=$word.'(?:\h+(?:(?:de|del|la)\h+)?'.$word.'){0,3}';
        $role='(?:ponente|orador(?:a)?|director(?:a)?|gerente|president(?:e|a)|secretari[oa]|señor(?:a)?|doctor(?:a)?)';
        $organization='(?i:empresa|compañía|compania|organización|organizacion|corporación|corporacion|universidad|fundación|fundacion|banco|grupo)\h+'.$name;
        $speech='(?i:intervino|explicó|explico|afirmó|afirmo|señaló|señalo|indicó|indico|compartió|compartio|presentó|presento|describió|describio|respondió|respondio|comentó|comento|dijo|habló|hablo|expuso|aprobó|aprobo|propuso)';
        $patterns=[
            '/[\p{L}\p{N}.+_-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u',
            '~(?i:\b(?:contacto|contact|perfil|profile|correo|email))\h*:\h*[^\r\n]{0,180}?(https?://[^\s<>]+)~u',
            '/(?i:\b'.$role.'\h+)('.$name.')/u',
            '/(?i:\b'.$role.'\h+(?:de|en)\h+)('.$name.')/u',
            '/(?i:\b'.$role.'\h+)'.$name.'\h+(?i:de|en)\h+('.$organization.')/u',
            '/(?<!\p{L})(?<person>'.$word.'(?:\h+'.$word.'){1,3})\h+(?i:de|del|en)\h+(?<organization>'.$organization.')(?=\h+'.$speech.')/u',
            '/(?i:\b(?:en|desde)\h+)('.$organization.')(?=\h+(?i:trabajamos|hicimos|aplicamos|tomamos|implementamos|decidimos))/u',
            '/^\h*((?i:orador(?:a)?|ponente)\h+'.$name.'):\h*/mu',
        ];
        $public=array_map('mb_strtolower',['COSO ERM','API REAL','ASCLA','Círculo ASCLA','Chatham House','Centro de Conocimiento','Gobierno Corporativo','Inteligencia Artificial','Gestión de Riesgos','Gobierno de IA','Secretaría Corporativa']);
        foreach($patterns as $pattern){
            preg_match_all($pattern,$text,$matches,PREG_SET_ORDER);
            foreach($matches as $match){
                $values=isset($match['person'])?[$match['person'],$match['organization']]:[$match[1]??$match[0]];
                foreach($values as $value){$value=trim($value);if(!in_array(mb_strtolower($value),$public,true)){ $entities[]=$value; }}
            }
        }
        preg_match_all('~https?://[^\s<>]+~iu',$text,$urls);
        foreach($urls[0] as $url){
            // Public documents and video links remain usable; personal/profile and credential links do not.
            if(preg_match('~/(?:perfil|profiles?|usuarios?|users?|miembros?|members?|author|autor)(?:[/?#]|$)|[?&](?:access_token|token|session|password|auth|email)=~iu',$url)){ $entities[]=$url; }
        }
        return array_values(array_unique($entities));
    }
    public static function redactEntities(string $text,array $entities): string
    {
        $entities=array_map('trim',array_filter($entities,'is_string'));
        usort($entities,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        foreach($entities as $entity){
            if(mb_strlen($entity)<2){ continue; }
            $text=preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($entity,'/').'(?![\p{L}\p{N}])/iu',self::TOKEN,$text)??$text;
        }
        return self::cleanLabels($text);
    }
    public static function validateRedaction(string $text,array $known=[],array $references=[]): array
    {
        [$text]=self::maskReferences($text,$references,$known);
        $remaining=self::detectEntities($text,$known);
        $remaining=array_filter($remaining,static fn($entity)=>(bool)preg_match('/(?<![\p{L}\p{N}])'.preg_quote($entity,'/').'(?![\p{L}\p{N}])/iu',$text));
        return ['valid'=>!$remaining,'remaining_count'=>count($remaining)];
    }
    public static function redact(string $text,array $known=[],array $references=[]): string
    {
        // Discover attribution before removing a declared name, so its affiliation is also withheld.
        $entities=self::detectEntities($text,$known);
        $text=self::redactEntities($text,$known);
        [$text,$titles]=self::maskReferences($text,$references,$known);
        $text=self::redactEntities($text,array_merge($entities,self::detectEntities($text,$known)));
        return self::cleanLabels(strtr($text,$titles));
    }
    public static function tree(mixed $value,array $known=[],array $references=[]): mixed
    {
        if(is_array($value)){ return array_map(static fn($item)=>self::tree($item,$known,$references),$value); }
        return is_string($value)?self::redact($value,$known,$references):$value;
    }
    /** Remove legacy/provider anonymity labels without guessing or restoring missing identities. */
    public static function cleanLabels(string $text): string
    {
        $token=self::TOKEN;
        $text=preg_replace('/(?:\[\h*)?\b(?:identidad(?:es)? reservada(?:s)?|reserved identit(?:y|ies)|identity withheld|'.$token.')\b(?:\h*\])?/iu',$token,$text)??$text;
        if(!str_contains($text,$token)){ return $text; }
        $role='(?:ponente|orador(?:a)?|director(?:a)?|gerente|president(?:e|a)|secretari[oa]|señor(?:a)?|doctor(?:a)?)';
        $text=preg_replace('/^\h*'.$token.'\h*:\h*/mu','',$text)??$text;
        $text=preg_replace('/\b(?:(?:el|la|un|una)\h+)?'.$role.'\h+(?:(?:de|en)\h+)?'.$token.'(?:\h+(?:de|en)\h+'.$token.')?\h+/iu','se ',$text)??$text;
        $text=preg_replace('/\b(?:(?:el|la|un|una)\h+)?'.$token.'(?:\h+(?:de|en)\h+'.$token.')?\h+(?=(?:intervino|explicó|explico|afirmó|afirmo|señaló|señalo|indicó|indico|compartió|compartio|presentó|presento|describió|describio|respondió|respondio|comentó|comento|dijo|habló|hablo|expuso|aprobó|aprobo|propuso)\b)/iu','se ',$text)??$text;
        $text=preg_replace('/\b(?:de|del|en|con|a|por|para|desde|y|e)\h+'.$token.'\b/iu','',$text)??$text;
        $text=str_replace($token,'',$text);
        $text=preg_replace('/\h+([,.;:!?])/u','$1',$text)??$text;
        $text=preg_replace('/\h{2,}/u',' ',$text)??$text;
        $text=preg_replace('/\b(?:contacto|contact|correo|email)\h*:\h*(?:[.;])?\h*$/miu','',$text)??$text;
        $text=preg_replace('/^\h*[.;:]\h*/mu','',$text)??$text;
        $text=preg_replace_callback('/(^|[.!?]\h+|\n\h*)se\h+/u',static fn($m)=>$m[1].'Se ',$text)??$text;
        return trim($text);
    }
    public static function cleanTree(mixed $value): mixed
    {
        if(is_array($value)){ return array_map([self::class,'cleanTree'],$value); }
        return is_string($value)?self::cleanLabels($value):$value;
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
