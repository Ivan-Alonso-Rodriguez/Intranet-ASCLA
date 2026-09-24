<?php
namespace ASCLA\Core\Services;

/** Shared validation for self-service and administrative profile changes. */
final class ProfileValidation
{
    private const REQUIRED=['first_name'=>'Nombres','last_name'=>'Apellidos','position'=>'Cargo','company'=>'Empresa / organización'];

    /** Partial updates preserve omitted fields; supplied required fields cannot be cleared. */
    public static function validateRequired(array $input): void
    {
        foreach (self::REQUIRED as $field=>$label) {
            if (!array_key_exists($field,$input)) { continue; }
            $value=Access::text($input[$field],200);
            Access::require((bool)preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u',$value),$label.': este campo es obligatorio.',400);
        }
    }
}
