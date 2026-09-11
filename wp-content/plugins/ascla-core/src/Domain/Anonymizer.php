<?php
namespace ASCLA\Core\Domain;
final class Anonymizer
{
    public static function redact(string $text,array $identities=[]): string
    {
        return EntityRedactor::redact($text,$identities);
    }
}
