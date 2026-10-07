<?php
namespace ASCLA\Core\Services;

/** Membership expiry revokes credentials without deleting profiles, relations or messages. */
final class Membership
{
    private const NOT_EXISTS='NOT EXISTS';
    public static function boot(): void
    {
        add_filter('authenticate',[self::class,'authenticate'],110,3);
        add_action('added_user_meta',[self::class,'changed'],10,4);
        add_action('updated_user_meta',[self::class,'changed'],10,4);
        add_action('ascla_membership_expired',[self::class,'expire']);
    }
    public static function status(int $id): string
    {
        if (get_user_meta($id,'_ascla_suspended',true)) { return 'suspended'; }
        $until=(int)get_user_meta($id,'_ascla_membership_until',true);
        return get_user_meta($id,'_ascla_membership_status',true)==='expired' || ($until>0 && time()>=$until)?'expired':'active';
    }
    public static function view(int $id): array
    {
        $until=(int)get_user_meta($id,'_ascla_membership_until',true);
        return ['membership_status'=>self::status($id),'membership_until'=>$until>0?wp_date('Y-m-d',$until-1,wp_timezone()):''];
    }
    public static function authenticate(mixed $user,string $username='',string $password=''): mixed
    {
        unset($username,$password); // WordPress authenticate filter passes all three values.
        if ($user instanceof \WP_User && user_can($user,'ascla_access') && self::status((int)$user->ID)!=='active') {
            self::revoke((int)$user->ID);
            return new \WP_Error('ascla_membership_inactive','Tu membresía está vencida o suspendida. Contacta con ASCLA.');
        }
        return $user;
    }
    public static function validate(array $input,int $id=0): array
    {
        $current=$id?self::view($id):['membership_status'=>'active','membership_until'=>''];
        $status=Access::text($input['membership_status']??$current['membership_status'],20);
        Access::require(in_array($status,['active','suspended','expired'],true),'Estado de membresía no válido.',400);
        if (!$id) { Access::require($status!=='expired','La cuenta debe iniciar Activa o Suspendida.',400); }
        $date=trim(Access::text($input['membership_until']??$current['membership_until'],10));$until=0;
        if ($date!=='') {
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,wp_timezone());
            Access::require($parsed && $parsed->format('Y-m-d')===$date,'Fecha de vigencia no válida.',400);
            $until=$id && !array_key_exists('membership_until',$input)
                ? (int)get_user_meta($id,'_ascla_membership_until',true) : $parsed->modify('+1 day')->getTimestamp();
        }
        Access::require($status!=='active' || $until===0 || $until>time(),'Para activar la membresía, selecciona una vigencia futura o elimina su fecha límite.',400);
        return ['status'=>$status,'until'=>$until];
    }
    /** Apply after validating the complete administrative form. */
    public static function apply(int $id,array $value): void
    {
        Access::require(current_user_can('ascla_manage') && current_user_can('edit_user',$id));
        Access::require($id!==get_current_user_id() && !user_can($id,'manage_options'),'Cuenta no disponible.',400);
        $before=self::view($id);
        update_user_meta($id,'_ascla_membership_until',$value['until']);
        update_user_meta($id,'_ascla_membership_status',$value['status']);
        update_user_meta($id,'_ascla_suspended',$value['status']==='suspended');
        Audit::changes('membership_updated',$id,$before,self::view($id));
    }
    public static function revoke(int $id): void
    {
        \WP_Session_Tokens::get_instance($id)->destroy_all();
    }
    public static function changed(int $metaId,int $id,string $key,mixed $value): void
    {
        unset($metaId,$value); // WordPress metadata hooks include these values.
        if (!in_array($key,['_ascla_suspended','_ascla_membership_status','_ascla_membership_until'],true)) { return; }
        wp_clear_scheduled_hook('ascla_membership_expired',[$id]);
        $until=(int)get_user_meta($id,'_ascla_membership_until',true);
        if ($until>time()) { wp_schedule_single_event($until,'ascla_membership_expired',[$id]); }
        if (self::status($id)!=='active') { self::revoke($id); }
    }
    public static function expire(int $id): void
    {
        if (self::status($id)!=='active') { self::revoke($id); }
    }
    public static function filter(string $state): array
    {
        $notSuspended=['relation'=>'OR',['key'=>'_ascla_suspended','compare'=>self::NOT_EXISTS],['key'=>'_ascla_suspended','value'=>'1','compare'=>'!=']];
        $expired=['relation'=>'OR',['key'=>'_ascla_membership_status','value'=>'expired'],['relation'=>'AND',['key'=>'_ascla_membership_until','value'=>0,'compare'=>'>','type'=>'NUMERIC'],['key'=>'_ascla_membership_until','value'=>time(),'compare'=>'<=','type'=>'NUMERIC']]];
        if ($state==='suspended') { return [['key'=>'_ascla_suspended','value'=>'1']]; }
        if ($state==='expired') { return ['relation'=>'AND',$notSuspended,$expired]; }
        return ['relation'=>'AND',$notSuspended,
            ['relation'=>'OR',['key'=>'_ascla_membership_status','compare'=>self::NOT_EXISTS],['key'=>'_ascla_membership_status','value'=>'expired','compare'=>'!=']],
            ['relation'=>'OR',['key'=>'_ascla_membership_until','compare'=>self::NOT_EXISTS],['key'=>'_ascla_membership_until','value'=>0,'type'=>'NUMERIC'],['key'=>'_ascla_membership_until','value'=>time(),'compare'=>'>','type'=>'NUMERIC']]];
    }
}
