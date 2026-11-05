<?php
namespace ASCLA\Core\Services;
final class Access
{
    public static function member(int $id=0): bool { return user_can($id?:get_current_user_id(),'ascla_access') && Membership::status($id?:get_current_user_id())==='active'; }
    /** A display label follows assigned roles, never their shared administrative capabilities. */
    public static function roleLabel(int $id=0): string
    {
        $user=get_userdata($id?:get_current_user_id());
        foreach (['administrator'=>'Administrador','ascla_executive'=>'Ejecutivo ASCLA','ascla_moderator'=>'Moderador ASCLA','ascla_member'=>'Asociado ASCLA'] as $role=>$label) {
            if ($user&&in_array($role,$user->roles,true)) { return $label; }
        }
        return 'Comunidad ASCLA';
    }
    public static function isAdministrator(int $id=0): bool
    {
        $id=$id?:get_current_user_id();
        return user_can($id,'ascla_manage') || user_can($id,'manage_options');
    }
    public static function networkingMember(int $id=0): bool { return self::member($id) && !self::isAdministrator($id); }
    /** Community account operations never grant native WordPress user capabilities. */
    public static function canManageAccount(string $operation,int $id=0): bool
    {
        if (!current_user_can('ascla_manage') || !in_array($operation,['create_users','edit_user','delete_user'],true)) { return false; }
        if (current_user_can($operation,$id)) { return true; }
        return self::executiveAccountPermission($operation,$id);
    }
    private static function executiveAccountPermission(string $operation,int $id): bool
    {
        $roles=RoleApprovals::effective(AccountPermissions::roles(get_current_user_id()),get_current_user_id());
        if (!in_array('ascla_executive',$roles,true)) { return false; }
        if ($operation==='create_users') { return true; }
        $target=get_userdata($id);
        return $target && ($operation==='edit_user' || $id!==get_current_user_id()) && !user_can($id,'manage_options')
            && (bool)array_intersect($target->roles,['ascla_member','ascla_moderator','ascla_executive']);
    }
    /** Ejecutivo (ascla_publish) or Administrator (ascla_manage): the two roles allowed to publish and manage events, session recordings and technical resources. */
    public static function canPublish(): bool { return current_user_can('ascla_manage') || current_user_can('ascla_publish'); }
    public static function require(bool $condition,string $message='Acceso no autorizado.',int $code=403): void
    {
        if (!$condition) { throw new \ASCLA\Core\Rest\ApiException($message,$code); }
    }
    public static function limit(string $bucket,int $max=30,int $seconds=60): void
    {
        $key='rate:'.get_current_user_id().':'.$bucket.':'.intdiv(time(),$seconds);
        \ASCLA\Core\Repositories\Store::lock($key,static function () use ($key,$max,$seconds) {
            $cache='ascla_'.hash('sha256',$key); $count=(int)get_transient($cache);
            self::require($count<$max,'Demasiadas solicitudes. Espere un momento.',429);
            set_transient($cache,$count+1,$seconds);
        });
    }
    public static function excerpt(mixed $value,int $max=180): string
    {
        $text=is_scalar($value)?sanitize_textarea_field((string)$value):'';
        return mb_strlen($text)>$max?mb_substr($text,0,max(0,$max-1)).'…':$text;
    }
    public static function text(mixed $value,int $max=2000): string
    {
        self::require(is_scalar($value)||$value===null,'Texto no válido.',400);
        $value=sanitize_textarea_field((string)$value);
        self::require(mb_strlen($value)<=$max,'El texto supera el límite de '.$max.' caracteres.',400);
        return $value;
    }
    public static function hasText(string $value): bool
    {
        return (bool)preg_match('/[^\s\p{Z}\p{Cf}\p{Cc}]/u',$value);
    }
}
