<?php
namespace ASCLA\Core\Services;

/** Shared validation for self-service and administrative profile changes. */
final class ProfileValidation
{
    private const REQUIRED=['first_name'=>'Nombres','last_name'=>'Apellidos','position'=>'Cargo','company'=>'Empresa / organización'];

    public static function validateSocialLink(string $field,string $value): void
    {
        $url=wp_parse_url($value);$host=strtolower($url['host']??'');
        $domains=$field==='linkedin'?['linkedin.com']:['x.com','twitter.com'];
        $allowed=false;
        foreach ($domains as $domain) { $allowed=$allowed || $host===$domain || str_ends_with($host,'.'.$domain); }
        $defaultPort=($url['scheme']??'')==='https'?443:80;
        Access::require($allowed && !isset($url['user']) && !isset($url['pass']) && (!isset($url['port']) || $url['port']===$defaultPort),
            $field==='linkedin'?'Introduce un enlace de linkedin.com.':'Introduce un enlace de x.com o twitter.com.',400);
    }

    /** Partial updates preserve omitted fields; supplied required fields cannot be cleared. */
    public static function validateRequired(array $input): void
    {
        foreach (self::REQUIRED as $field=>$label) {
            if (!array_key_exists($field,$input)) { continue; }
            $value=Access::text($input[$field],200);
            Access::require(Access::hasText($value),$label.': este campo es obligatorio.',400);
        }
    }
}
