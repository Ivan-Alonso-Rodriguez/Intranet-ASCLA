<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Roles;
use ASCLA\Core\Repositories\WordPressWrites;

/** Individual WordPress capabilities may restrict, but never exceed, the assigned role. */
final class AccountPermissions
{
    public const LABELS=['ascla_access'=>'Acceder a ASCLA','ascla_write'=>'Crear contenido y enviar mensajes','ascla_admin_area'=>'Acceder al área de administración','ascla_moderate'=>'Moderar contenido e interacciones','ascla_publish'=>'Publicar contenido editorial y gestionar eventos','ascla_manage'=>'Gestionar la administración ASCLA','ascla_configure'=>'Configurar ASCLA'];

    public static function boot(): void
    {
        add_filter('user_has_cap',[self::class,'filter'],20,4);
        foreach (['add','update'] as $operation) { add_filter($operation.'_user_metadata',[RoleApprovals::class,'guard'],5,4); }
        add_filter('rest_request_before_callbacks',[RoleApprovals::class,'validateNative'],5,3);
    }

    public static function roles(int $id): array
    {
        global $wpdb;$caps=(array)get_user_meta($id,$wpdb->prefix.'capabilities',true);
        return array_values(array_intersect(array_keys(array_filter($caps)),array_keys(Roles::capabilities())));
    }

    public static function ceiling(array $roles): array
    {
        $allowed=[];$definitions=Roles::capabilities();
        foreach ($roles as $role) { $allowed=array_merge($allowed,$definitions[$role]??[]); }
        return $allowed;
    }

    public static function filter(array $allcaps,array $requested,array $args,\WP_User $user): array
    {
        unset($requested,$args);
        $assigned=self::roles((int)$user->ID);$roles=RoleApprovals::effective($assigned,(int)$user->ID);
        $allcaps=self::withoutUnapproved($allcaps,$assigned,$roles);
        $allowed=self::ceiling($roles);
        foreach (self::LABELS as $cap=>$label) { if (empty($allowed[$cap])) { $allcaps[$cap]=false; } }
        return $allcaps;
    }

    private static function withoutUnapproved(array $allcaps,array $assigned,array $effective): array
    {
        $extra=array_diff($assigned,$effective);
        if (!$extra) { return $allcaps; }
        $allowed=[];
        foreach ($effective as $role) { $allowed=array_merge($allowed,get_role($role)?->capabilities??[]); }
        foreach ($extra as $role) {
            foreach (get_role($role)?->capabilities??[] as $cap=>$value) { if (empty($allowed[$cap])) { $allcaps[$cap]=false; } }
        }
        return $allcaps;
    }

    public static function view(int $id): array
    {
        global $wpdb;$raw=(array)get_user_meta($id,$wpdb->prefix.'capabilities',true);
        return ['assigned_roles'=>self::roles($id),'permission_options'=>self::LABELS,'permission_overrides'=>array_intersect_key($raw,self::LABELS),
            'role_approval'=>(array)get_user_meta($id,RoleApprovals::META,true)];
    }

    public static function plan(int $id,string $primary,array $input): array
    {
        $current=self::roles($id);
        $roles=$input['assigned_roles']??(($current[0]??'')===$primary?$current:[$primary]);
        Access::require(is_array($roles) && array_is_list($roles) && $roles && in_array($primary,$roles,true),'Roles de cuenta no válidos.',400);
        foreach ($roles as $role) { Access::require(is_string($role) && in_array($role,['ascla_member','ascla_moderator','ascla_executive'],true),'Rol no válido para la comunidad.',400); }
        $roles=array_values(array_unique(array_merge([$primary],$roles)));
        $overrides=$input['permission_overrides']??self::view($id)['permission_overrides'];
        Access::require(is_array($overrides),'Permisos de cuenta no válidos.',400);
        $allowed=self::ceiling($roles);$clean=[];
        foreach ($overrides as $cap=>$value) {
            Access::require(isset(self::LABELS[$cap]) && is_bool($value),'Permisos de cuenta no válidos.',400);
            Access::require(!$value || !empty($allowed[$cap]),'Los permisos no pueden superar los del rol asignado.',403);
            $clean[$cap]=$value;
        }
        return ['roles'=>$roles,'overrides'=>$clean,'approval'=>RoleApprovals::prepare($id,$roles,$input)];
    }

    public static function apply(int $id,array $plan): void
    {
        global $wpdb;$key=$wpdb->prefix.'capabilities';$old=(array)get_user_meta($id,$key,true);$caps=$old;
        foreach (array_merge(array_keys(Roles::capabilities()),array_keys(self::LABELS)) as $keyToRemove) { unset($caps[$keyToRemove]); }
        foreach ($plan['roles'] as $role) { $caps[$role]=true; }
        $caps=array_merge($caps,$plan['overrides']);
        RoleApprovals::save($id,$plan['approval']);
        WordPressWrites::meta('user',$id,$key,$caps);
        clean_user_cache($id);
        Audit::changes('member_roles_updated',$id,['roles'=>self::rolesFrom($old)],['roles'=>$plan['roles']]);
    }

    private static function rolesFrom(array $caps): array
    {
        return array_values(array_intersect(array_keys(array_filter($caps)),array_keys(Roles::capabilities())));
    }
}
