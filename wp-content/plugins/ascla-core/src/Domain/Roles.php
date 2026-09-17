<?php
namespace ASCLA\Core\Domain;

/** Owns the cumulative role definition and its non-destructive WordPress migration. */
final class Roles
{
    public const VERSION=1;
    public const OPTION='ascla_roles_version';
    private const CAPABILITIES=['ascla_access','ascla_write','ascla_admin_area','ascla_moderate','ascla_publish','ascla_manage'];

    public static function capabilities(): array
    {
        $member=['read'=>true,'ascla_access'=>true,'ascla_write'=>true];
        $moderator=$member+['ascla_admin_area'=>true,'ascla_moderate'=>true];
        $executive=$moderator+['ascla_publish'=>true];
        return ['ascla_member'=>$member,'ascla_moderator'=>$moderator,
            'ascla_executive'=>$executive,'administrator'=>$executive+['ascla_manage'=>true]];
    }

    public static function sync(): void
    {
        $labels=['ascla_member'=>'Asociado ASCLA','ascla_moderator'=>'Moderador ASCLA','ascla_executive'=>'Ejecutivo ASCLA'];
        foreach(self::capabilities() as $name=>$caps){
            if(isset($labels[$name])){add_role($name,$labels[$name],$caps);}
            $role=get_role($name);
            if(!$role){continue;}
            // Reconcile only plugin-owned capabilities; retain unrelated custom permissions.
            foreach(self::CAPABILITIES as $cap){
                if(isset($caps[$cap])){$role->add_cap($cap);}else{$role->remove_cap($cap);}
            }
            $role->add_cap('read');
        }
        // The current user may already have loaded the old role capabilities in this request.
        wp_get_current_user()->get_role_caps();
        update_option(self::OPTION,self::VERSION,false);
    }

    public static function upgrade(): void
    {
        if((int)get_option(self::OPTION,0)<self::VERSION){self::sync();}
    }
}
