<?php
namespace ASCLA\Core\Services;

/** Normalize generated lists without changing original editorial metadata. */
final class KnowledgeMaterial
{
    public static function infographic(array $result): array
    {
        $info=is_array($result['infographic']??null)?$result['infographic']:[];
        foreach (['sections','key_points'] as $field) {
            if (isset($info[$field])) { $info[$field]=self::cleanList((array)$info[$field]); }
        }

        return $info;
    }

    public static function cleanList(array $values): array
    {
        $clean=[];
        foreach (array_slice($values,0,40) as $value) {
            if (!is_string($value)) { continue; }
            $value=trim(preg_replace('/\s+/u',' ',sanitize_text_field($value)));
            if ($value==='') { continue; }
            $plain=mb_strtolower(remove_accents(trim($value," \t\n\r\0\x0B.,;:–—-")));
            if (in_array($plain,['participante','participantes','una persona','persona','dato reservado','informacion reservada','identidad reservada','organizacion reservada'],true)) { continue; }
            $clean[]=$value;
        }
        return array_values(array_unique($clean));
    }
}
