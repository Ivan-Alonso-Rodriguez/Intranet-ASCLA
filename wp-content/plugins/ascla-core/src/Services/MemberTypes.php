<?php
namespace ASCLA\Core\Services;

/** Membership classification is assigned by administration, separately from account roles. */
final class MemberTypes
{
    public const OPTIONS=['independent'=>'Individual','company'=>'Empresa asociada'];

    public static function code(string $value): string
    {
        return match(mb_strtolower(trim($value))) {
            'independent','independiente','individual'=>'independent',
            'company','empresa asociada','associated company'=>'company',
            default=>'',
        };
    }
    public static function selected(mixed $value): string
    {
        Access::require(is_string($value),'Tipo de asociado no válido.',400);
        $code=self::code($value);
        Access::require($code!=='','Selecciona Individual o Empresa asociada.',400);
        return $code;
    }
    public static function profile(array $profile,array $input,int $id): array
    {
        if (!array_key_exists('member_type',$input)) { return $profile; }
        $previous=(string)($profile['member_type']??'');$value=$input['member_type'];
        $code=is_string($value)?self::code($value):null;
        // Reposting a visible label is not a classification change. Preserve unknown
        // legacy values when their public projection is empty, rather than clearing them.
        if ($value===$previous || ($code===self::code($previous) && ($code!=='' || $value===''))) { return $profile; }
        Access::require(current_user_can('ascla_manage') && current_user_can('edit_user',$id),'Solo administración puede establecer el tipo de asociado.',403);
        $profile['member_type']=self::selected($value);
        return $profile;
    }
}
