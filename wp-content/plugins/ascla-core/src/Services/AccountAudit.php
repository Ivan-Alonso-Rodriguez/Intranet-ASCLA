<?php
namespace ASCLA\Core\Services;

/** Observe WordPress metadata writes, including role changes outside ASCLA forms. */
final class AccountAudit
{
    private static array $before=[];

    public static function boot(): void
    {
        foreach (['add','update','delete'] as $operation) {
            add_filter($operation.'_user_metadata',[self::class,'capture'],20,4);
        }
        foreach (['added','updated','deleted'] as $operation) {
            add_action($operation.'_user_meta',[self::class,'record'],20,4);
        }
    }

    private static function kind(string $key): string
    {
        global $wpdb;
        if ($key===$wpdb->prefix.'capabilities') { return 'permissions'; }
        return in_array($key,['_ascla_suspended','_ascla_membership_status','_ascla_membership_until'],true)?'membership':'';
    }

    private static function snapshot(int $id,string $key,string $kind): array
    {
        if ($kind==='permissions') { return ['capabilities'=>(array)get_user_meta($id,$key,true)]; }
        return Membership::view($id);
    }

    public static function capture(mixed $check,int $id,string $key,mixed $value): mixed
    {
        unset($value);
        $kind=self::kind($key);
        if ($check===null && $kind!=='' && get_userdata($id)) {
            self::$before[$id.':'.$key]=self::snapshot($id,$key,$kind);
        }
        return $check;
    }

    public static function record(mixed $metaId,int $id,string $key,mixed $value): void
    {
        unset($metaId,$value);
        $index=$id.':'.$key;$before=self::$before[$index]??null;
        unset(self::$before[$index]);
        if ($before===null || !get_userdata($id)) { return; }
        $kind=self::kind($key);
        Audit::changes('account_'.$kind.'_changed',$id,$before,self::snapshot($id,$key,$kind));
    }
}
