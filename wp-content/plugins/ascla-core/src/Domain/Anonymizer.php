<?php
namespace ASCLA\Core\Domain;
final class Anonymizer
{
    /** Best-effort redaction. Human review remains mandatory. */
    public static function redact(string $text,array $identities=[]): string
    {
        usort($identities,static fn($a,$b)=>strlen($b)<=>strlen($a));
        foreach (array_unique($identities) as $identity) {
            $identity=trim($identity);
            if (mb_strlen($identity)>=3) { $text=preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($identity,'/').'(?![\p{L}\p{N}])/iu','[identidad reservada]',$text); }
        }
        $text=preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u','[correo reservado]',$text);
        $text=preg_replace('/https?:\/\/\S+/u','[enlace reservado]',$text);
        $text=preg_replace('/^\s*[\p{L}][\p{L} .-]{2,60}:\s*/mu','Participante: ',$text);
        return $text;
    }
}
