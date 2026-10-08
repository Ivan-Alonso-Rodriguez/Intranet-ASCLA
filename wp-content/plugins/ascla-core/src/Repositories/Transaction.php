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
        self::afterCommit(static fn()=>wp_mail($mail['to'],$mail['subject'],$mail['message'],$mail['headers'],$mail['attachments']));
        return true;
    }

    private static function verifyEngines(): void
    {
        if (self::$enginesChecked) { return; }
        global $wpdb;
        $tables=[$wpdb->posts,$wpdb->postmeta,$wpdb->comments,$wpdb->commentmeta,$wpdb->users,$wpdb->usermeta,$wpdb->options,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships];
        foreach (['relations','conversations','participants','messages','registrations','notifications','audit','jobs','media','attendance','user_interests'] as $name) { $tables[]=Store::table($name); }
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
        try {
            $result=$callback();
            self::execute($level?'RELEASE SAVEPOINT '.$savepoint:'COMMIT');
        } catch (\Throwable $error) {
            global $wpdb;
            $wpdb->query($level?'ROLLBACK TO SAVEPOINT '.$savepoint:'ROLLBACK');
            self::$afterCommit=array_slice(self::$afterCommit,0,$queued);
            // WordPress metadata/options caches may contain values written before rollback.
            wp_cache_flush();
            throw $error;
        } finally { self::$depth=$level; }
        if (!$level) { self::deliver(); }
        return $result;
    }

    public static function afterCommit(callable $callback): void
    {
        if (self::$depth) { self::$afterCommit[]=$callback; }
        else { $callback(); }
    }

    private static function deliver(): void
    {
        $callbacks=self::$afterCommit;self::$afterCommit=[];
        foreach ($callbacks as $callback) {
            try { $callback(); }
            catch (\Throwable $error) { error_log('ASCLA post-commit delivery failed: '.get_class($error)); }
        }
    }
}
