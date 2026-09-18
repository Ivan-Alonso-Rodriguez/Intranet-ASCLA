<?php
namespace ASCLA\Core\Domain;
use ASCLA\Core\Services\Access;
final class Phone
{
    public static function normalize(mixed $value): string
    {
        Access::require(is_string($value),'El teléfono debe ser texto.',400);
        $value=trim($value);if($value==='')return '';
        Access::require(strlen($value)<=40 && preg_match('/^\+[0-9 ()-]+$/D',$value)===1,'Usa un teléfono internacional válido que empiece con + y el código de país, por ejemplo +51 999 123 456.',400);
        $digits=preg_replace('/\D/','',$value);
        $normalized='+'.$digits;
        Access::require(preg_match('/^\+[1-9][0-9]{7,14}$/D',$normalized)===1,'El teléfono debe usar formato internacional: +, código de país y entre 8 y 15 dígitos en total.',400);
        return $normalized;
    }
    public static function visibility(mixed $value): string
    {
        Access::require(is_string($value) && in_array($value,['private','members'],true),'Privacidad del teléfono no válida.',400);
        return $value;
    }
}
