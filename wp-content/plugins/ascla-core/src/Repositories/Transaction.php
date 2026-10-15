<?php
namespace ASCLA\Core\Repositories;

/** Local writes commit together; complementary email waits for the outer commit. */
final class Transaction
{
    private static int $depth=0;
    private static array $afterCommit=[];
    private static bool $enginesChecked=false;

    public static function boot(): void
    {
        // WordPress itself sends account/comment mail; keep that mail behind the same commit.
        add_filter('pre_wp_mail',[self::class,'deferMail'],PHP_INT_MAX,2);
    }

    public static function deferMail(mixed $result,array $mail): mixed
    {
        if ($result!==null || !self::$depth) { return $result; }
        \ASCLA\Core\Jobs\Deliveries::enqueue('mail',\ASCLA\Core\Jobs\DeliveryMail::snapshot($mail));
        return true;
    }

    private static function verifyEngines(): void
    {
        if (self::$enginesChecked) { return; }
        global $wpdb;
        $tables=[$wpdb->posts,$wpdb->postmeta,$wpdb->comments,$wpdb->commentmeta,$wpdb->users,$wpdb->usermeta,$wpdb->options,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships];
        foreach (['relations','conversations','participants','messages','registrations','notifications','audit','jobs','deliveries','media','attendance','imports','import_rows','user_interests'] as $name) { $tables[]=Store::table($name); }
        $placeholders=implode(',',array_fill(0,count($tables),'%s'));
        $engines=$wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($placeholders)",...$tables),ARRAY_A)?:[];
        if (count($engines)!==count($tables) || array_filter($engines,static fn($row)=>strtolower((string)$row['ENGINE'])!=='innodb')) {
            throw new RepositoryException('Las operaciones críticas requieren tablas InnoDB. Contacta al administrador.');
        }
        self::$enginesChecked=true;
    }

    private static function execute(string $sql): void
    {
        global $wpdb;
        if ($wpdb->query($sql)===false) { throw new RepositoryException('No se pudo confirmar la operación. Reinténtalo.'); }
    }

    public static function run(callable $callback): mixed
    {
        self::verifyEngines();
        $level=self::$depth;$savepoint='ascla_transaction_'.$level;$queued=count(self::$afterCommit);
        // A nested resource lock may be acquired after earlier reads. Read its latest committed state.
        if (!$level) { self::execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        self::execute($level?'SAVEPOINT '.$savepoint:'START TRANSACTION');
        self::$depth++;
        $originalCache=null;$cache=null;$committed=false;$recovery=0;
        $checkpoint=$GLOBALS['wp_object_cache'] instanceof TransactionCache?$GLOBALS['wp_object_cache']->checkpoint():null;
        $currentUser=null;
        try {
            $currentUser=self::snapshotCurrentUser();
            if (!$level) {
                $originalCache=$GLOBALS['wp_object_cache'];$cache=new TransactionCache($originalCache);
                $GLOBALS['wp_object_cache']=$cache;
            }
            $result=$callback();
            if ($cache) { $recovery=CacheRecovery::stage($cache->recovery()); }
            self::execute($level?'RELEASE SAVEPOINT '.$savepoint:'COMMIT');
            $committed=true;
        } catch (\Throwable $error) {
            self::rollback($level,$savepoint,$queued,$checkpoint,$currentUser);
            throw $error;
        } finally {
            self::$depth=$level;
            if ($originalCache) { $GLOBALS['wp_object_cache']=$originalCache; }
            if ($cache) { self::finishCache($cache,$committed,$recovery); }
        }
        if (!$level) { self::deliver(); }
        return $result;
    }

    private static function snapshotCurrentUser(): ?\WP_User
    {
        if (!(($GLOBALS['current_user']??null) instanceof \WP_User)) { return null; }
        $user=clone $GLOBALS['current_user'];
        if (is_object($user->data)) { $user->data=clone $user->data; }
        return $user;
    }

    private static function rollback(int $level,string $savepoint,int $queued,?array $checkpoint,?\WP_User $user): void
    {
        global $wpdb;
        $wpdb->query($level?'ROLLBACK TO SAVEPOINT '.$savepoint:'ROLLBACK');
        self::$afterCommit=array_slice(self::$afterCommit,0,$queued);
        // A savepoint rollback invalidates only this transaction's local view.
        if ($GLOBALS['wp_object_cache'] instanceof TransactionCache) {
            if ($checkpoint) { $GLOBALS['wp_object_cache']->rollback($checkpoint); }
            else { $GLOBALS['wp_object_cache']->clear(); }
        }
        if (!$user) { return; }
        $GLOBALS['current_user']=$user;
        try { setup_userdata((int)$user->ID); }
        catch (\Throwable $cleanupError) { \ASCLA\Core\Services\TechnicalErrors::record('database','request_failed',$cleanupError); }
    }

    public static function afterCommit(callable $callback): void
    {
        if (self::$depth) { self::$afterCommit[]=$callback; }
        else { $callback(); }
    }

    private static function finishCache(TransactionCache $cache,bool $committed,int $recovery): void
    {
        try { $cache->finish($committed);if ($committed) { CacheRecovery::complete($recovery); } }
        catch (\Throwable $error) {
            // SQL already committed: a provider outage must not report a rolled-back write.
            CacheRecovery::protect();
            \ASCLA\Core\Services\TechnicalErrors::record('database','request_failed',$error,severity:'critical');
        }
    }

    private static function deliver(): void
    {
        $callbacks=self::$afterCommit;self::$afterCommit=[];
        foreach ($callbacks as $callback) {
            try { $callback(); }
            catch (\Throwable $error) { \ASCLA\Core\Services\TechnicalErrors::record('delivery','delivery_failed',$error); }
        }
    }
}
