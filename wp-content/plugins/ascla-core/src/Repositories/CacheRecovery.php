<?php
namespace ASCLA\Core\Repositories;
use ASCLA\Core\Jobs\DeliveryPayload;
use ASCLA\Core\Services\{SessionPolicy,TechnicalErrors};

/** Durable invalidations contain keys only: retry can never restore an older SQL value. */
final class CacheRecovery
{
    private const SQL_DATE_FORMAT='Y-m-d H:i:s';
    public static function boot(): void { self::reconcile(); }
    public static function stage(array $plan): int
    {
        if (!wp_using_ext_object_cache() || empty($plan['flush']) && empty($plan['entries'])) { return 0; }
        return Store::insert('deliveries',['kind'=>'cache','delivery_key'=>bin2hex(random_bytes(16)),'actor_id'=>0,
            'payload'=>DeliveryPayload::seal('cache',$plan),'status'=>'pending','available_at'=>gmdate(self::SQL_DATE_FORMAT),'created_at'=>gmdate(self::SQL_DATE_FORMAT)]);
    }
    public static function complete(int $id): void
    {
        if ($id>0) { Store::update('deliveries',['status'=>'sent','payload'=>'','locked_at'=>null,'completed_at'=>gmdate(self::SQL_DATE_FORMAT)],['id'=>$id,'kind'=>'cache']); }
    }
    public static function apply(array $plan,?object $provider=null): bool
    {
        $provider??=$GLOBALS['wp_object_cache'];
        while ($provider instanceof DatabaseCache) { $provider->clear();$provider=$provider->provider; }
        if ($provider instanceof TransactionCache) { throw new RepositoryException('La recuperación requiere una transacción confirmada.'); }
        $blog=get_current_blog_id();
        try {
            $provider->add_global_groups((array)($plan['groups']??[]));
            if (!empty($plan['flush'])) {
                if (!$provider->flush()) { throw new RepositoryException('No se pudo limpiar el caché confirmado.'); }
            } else {
                foreach ((array)($plan['entries']??[]) as $entry) { self::invalidate($provider,$entry); }
            }
        } finally { $provider->switch_to_blog($blog); }
        return true;
    }
    private static function invalidate(object $provider,array $entry): void
    {
        [$blog,$id,$group]=$entry;
        if (!CacheJournal::databaseGroup($group) || !is_int($blog) || $blog<1 || (!is_int($id) && !is_string($id))) { throw new RepositoryException('Invalidación no válida.'); }
        $provider->switch_to_blog($blog);
        if ($provider->delete($id,$group)) { return; }
        // A miss and a disconnected provider can both return false. Check the API before retry.
        self::checkProvider($provider);
        if ($provider->delete($id,$group)) { return; }
        $provider->get($id,$group,true,$found);
        if ($found) { throw new RepositoryException('No se pudo invalidar el caché confirmado.'); }
    }
    private static function checkProvider(object $provider): void
    {
        $key=bin2hex(random_bytes(16));$group='ascla_cache_recovery';
        if (!$provider->set($key,$key,$group,10)) { throw new RepositoryException('Caché no disponible.'); }
        $value=$provider->get($key,$group,true,$found);$deleted=$provider->delete($key,$group);
        if (!$found || $value!==$key || !$deleted) { throw new RepositoryException('Caché no disponible.'); }
    }
    public static function protect(): void
    {
        if (!wp_using_ext_object_cache() || $GLOBALS['wp_object_cache'] instanceof DatabaseCache) { return; }
        $GLOBALS['wp_object_cache']=new DatabaseCache($GLOBALS['wp_object_cache']);
        $user=$GLOBALS['current_user']??null;
        if ($user instanceof \WP_User) {
            $GLOBALS['current_user']=new \WP_User((int)$user->ID);
            wp_set_current_user((int)SessionPolicy::validateUser($user->ID));setup_userdata(get_current_user_id());
        }
    }
    public static function flush(DatabaseCache $cache): bool
    {
        $plan=['flush'=>true,'entries'=>[],'groups'=>[]];$id=self::stage($plan);$cache->clear();
        self::apply($plan,$cache);self::complete($id);return true;
    }
    private static function pending(): bool
    {
        global $wpdb;$table=Store::table('deliveries');
        $exists=$wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table));
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo comprobar el caché pendiente.'); }
        if (!$exists) { return false; }
        $value=$wpdb->get_var("SELECT id FROM $table WHERE kind='cache' AND status IN ('pending','processing','failed') LIMIT 1");
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo comprobar el caché pendiente.'); }
        return $value!==null;
    }
    public static function reconcile(): void
    {
        if (!wp_using_ext_object_cache() || $GLOBALS['wp_object_cache'] instanceof TransactionCache) { return; }
        try {
            if (self::pending()) { self::protect();Store::tryLock('deliveries',static fn()=>self::drain()); }
            if ($GLOBALS['wp_object_cache'] instanceof DatabaseCache && !self::pending()) { $GLOBALS['wp_object_cache']=$GLOBALS['wp_object_cache']->provider; }
        } catch (\Throwable $error) {
            self::protect();TechnicalErrors::record('database','request_failed',$error,severity:'critical');
        }
    }
    private static function drain(): void
    {
        // The common advisory lock proves no live worker owns a processing cache invalidation.
        foreach (Store::rows('deliveries',"kind='cache' AND status IN ('pending','processing','failed') AND (available_at<=UTC_TIMESTAMP() OR status='processing')",[],'ORDER BY id ASC LIMIT 10') as $row) {
            Store::update('deliveries',['status'=>'processing','attempts'=>(int)$row['attempts']+1,'locked_at'=>gmdate(self::SQL_DATE_FORMAT)],['id'=>$row['id']]);
            try { self::apply(DeliveryPayload::open('cache',$row['payload']));self::complete((int)$row['id']); }
            catch (\Throwable $error) {
                Store::update('deliveries',['status'=>'pending','available_at'=>gmdate(self::SQL_DATE_FORMAT,time()+60),'locked_at'=>null],['id'=>$row['id']]);
                TechnicalErrors::record('database','request_failed',$error,(int)$row['id'],severity:'critical');break;
            }
        }
    }
}
