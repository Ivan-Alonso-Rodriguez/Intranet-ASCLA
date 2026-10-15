<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Roles;
use ASCLA\Core\Repositories\WordPressWrites;

/** The administrator records a prior Board decision; this form does not issue that decision. */
final class RoleApprovals
{
    public const META='_ascla_role_approval';

    public static function covered(array $record,array $roles): bool
    {
        $reference=$record['reference']??null;$date=$record['decision_date']??null;$approved=$record['roles']??null;
        if (!is_string($reference) || !preg_match('/[^\\s\\p{Z}\\x{200B}\\x{FEFF}]/u',$reference)) { return false; }
        if (!self::validDate($date) || !is_array($approved) || !array_is_list($approved)) { return false; }
        $recorded=$record['recorded_at']??null;
        if (!is_string($recorded) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',$recorded) || (int)($record['recorded_by']??0)<=0) { return false; }
        foreach ($approved as $role) { if (!is_string($role) || !array_key_exists($role,Roles::capabilities())) { return false; } }
        return !array_diff($roles,$approved);
    }

    private static function validDate(mixed $date): bool
    {
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$parts)!==1) { return false; }
        return checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]) && $date<=wp_date('Y-m-d');
    }

    public static function prepare(int $id,array $roles,array $input): ?array
    {
        $existing=(array)get_user_meta($id,self::META,true);
        $reference=trim(Access::text($input['board_reference']??'',200));
        if (count($roles)<2 || ($reference==='' && self::covered($existing,$roles))) { return null; }
        Access::require(rest_sanitize_boolean($input['board_confirmed']??false) && (bool)preg_match('/[^\\s\\p{Z}\\x{200B}\\x{FEFF}]/u',$reference),'Registra la autorización de la Junta para asignar varios roles.',403);
        $date=Access::text($input['board_date']??'',10);
        Access::require(self::validDate($date),'Fecha de autorización no válida.',400);
        return ['roles'=>array_values($roles),'reference'=>$reference,'decision_date'=>$date,'recorded_by'=>get_current_user_id(),'recorded_at'=>gmdate('c')];
    }

    public static function save(int $id,?array $record): void
    {
        if ($record===null) { return; }
        $before=(array)get_user_meta($id,self::META,true);
        WordPressWrites::meta('user',$id,self::META,$record);
        Audit::changes('board_role_authorization',$id,['role_approval'=>$before],['role_approval'=>$record]);
    }

    /** Technical accounts remain managed by WordPress; their Board decision can still be recorded. */
    public static function register(int $id,array $input): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage') && current_user_can('edit_user',$id));
        Access::require((bool)get_userdata($id),'Usuario no encontrado.',404);
        $roles=$input['roles']??null;
        Access::require(is_array($roles) && array_is_list($roles),'Roles de cuenta no válidos.',400);
        foreach ($roles as $role) { Access::require(is_string($role) && array_key_exists($role,Roles::capabilities()),'Roles de cuenta no válidos.',400); }
        $roles=array_values(array_unique($roles));Access::require(count($roles)>=2,'Selecciona al menos dos roles para registrar una autorización.',400);
        return \ASCLA\Core\Repositories\Store::atomic('profile-interests:'.$id,static function()use($id,$roles,$input) {
            self::save($id,self::prepare($id,$roles,$input));
            return (array)get_user_meta($id,self::META,true);
        });
    }

    public static function effective(array $roles,int $id): array
    {
        if (count($roles)<2 || self::covered((array)get_user_meta($id,self::META,true),$roles)) { return $roles; }
        return array_slice($roles,0,1);
    }

    public static function guard(mixed $check,int $id,string $key,mixed $value): mixed
    {
        global $wpdb;
        if ($check!==null || $key!==$wpdb->prefix.'capabilities' || !is_array($value)) { return $check; }
        $roles=array_values(array_intersect(array_keys(array_filter($value)),array_keys(Roles::capabilities())));
        if (count($roles)>1 && !self::covered((array)get_user_meta($id,self::META,true),$roles)) { return false; }
        $allowed=AccountPermissions::ceiling($roles);
        foreach (AccountPermissions::LABELS as $cap=>$label) {
            if (!empty($value[$cap]) && empty($allowed[$cap])) { return false; }
        }
        return $check;
    }

    public static function validateNative(mixed $response,array $handler,\WP_REST_Request $request): mixed
    {
        unset($handler);
        if ($response!==null || !in_array($request->get_method(),['POST','PUT','PATCH'],true)
            || !preg_match('#^/wp/v2/users(?:/(\d+|me))?/?$#',$request->get_route(),$match) || !$request->has_param('roles')) { return $response; }
        $id=($match[1]??'')==='me'?get_current_user_id():(int)($match[1]??0);
        $roles=array_values(array_intersect((array)$request['roles'],array_keys(Roles::capabilities())));
        if (count($roles)>1 && !self::covered((array)get_user_meta($id,self::META,true),$roles)) {
            return new \WP_Error('ascla_board_authorization','Registra la autorización de la Junta desde Administración ASCLA antes de acumular roles.',['status'=>403]);
        }
        return $response;
    }
}
