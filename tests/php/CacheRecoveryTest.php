<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Repositories\{Transaction,WordPressWrites,Store,CacheRecovery,DatabaseCache,RepositoryException};
use ASCLA\Core\Jobs\{Deliveries,DeliveryPayload};
use ASCLA\Core\Services\{Membership,Access};
require_once __DIR__.'/../shared-cache-fixture.php';

final class CacheRecoveryTest extends TestCase
{
    private array $users=[];
    private object $original;
    private AsclaSharedCacheFixture $cache;
    private bool $external;
    private string $path,$option;
    private int $baseline;
    protected function setUp(): void
    {
        global $wpdb;$this->baseline=(int)$wpdb->get_var('SELECT MAX(id) FROM '.Store::table('deliveries'));
        foreach (['administrator','ascla_member'] as $role) {
            $this->users[]=wp_insert_user(['user_login'=>'recovery_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);$this->original=$GLOBALS['wp_object_cache'];$this->external=(bool)wp_using_ext_object_cache();
        $this->path=tempnam(sys_get_temp_dir(),'ascla-recovery-');$this->option='ascla_recovery_'.bin2hex(random_bytes(5));
        $this->cache=new AsclaSharedCacheFixture($this->path);$GLOBALS['wp_object_cache']=$this->cache;wp_using_ext_object_cache(true);
        update_user_meta($this->users[1],'_ascla_profile',['company'=>'Original']);update_option($this->option,'Original',false);
        wp_cache_set('unrelated','Conservar','ascla_fixture');get_user_meta($this->users[1],'_ascla_profile',true);get_option($this->option);
    }
    protected function tearDown(): void
    {
        global $wpdb;$GLOBALS['wp_object_cache']=$this->original;wp_using_ext_object_cache($this->external);wp_set_current_user($this->users[0]);
        delete_option($this->option);foreach ($this->users as $id) { wp_delete_user($id); }
        $wpdb->query($wpdb->prepare("DELETE FROM ".Store::table('deliveries')." WHERE kind='cache' AND id>%d",$this->baseline));
        wp_set_current_user(0);unlink($this->path);
    }
    private function rows(): array { return Store::rows('deliveries',"kind='cache' AND id>%d",[$this->baseline],'ORDER BY id ASC'); }
    private function code(string $body): string
    {
        return 'require '.var_export(ABSPATH.'wp-load.php',true).';require '.var_export(dirname(__DIR__).'/shared-cache-fixture.php',true).';$GLOBALS["wp_object_cache"]=new AsclaSharedCacheFixture('.var_export($this->path,true).');wp_using_ext_object_cache(true);'.$body;
    }
    private function execute(string $body): string
    {
        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($this->code($body)),$output,$exit);self::assertSame(0,$exit);return implode('',$output);
    }
    private function crash(string $operation): void
    {
        $body='wp_set_current_user('.$this->users[0].');$commit=null;$commit=static function($sql) use(&$commit) { if($sql==="COMMIT") { remove_filter("query",$commit);global $wpdb;if($wpdb->query("COMMIT")===false)exit(9);exit(0); } return $sql; };add_filter("query",$commit);ASCLA\\Core\\Repositories\\Transaction::run(static function(){'.$operation.'});exit(7);';
        $this->execute($body);
    }
    private function due(): void { foreach ($this->rows() as $row) { Store::update('deliveries',['available_at'=>gmdate('Y-m-d H:i:s',time()-1)],['id'=>$row['id']]); } }
    public function testProcessDeathAfterCommitIsRecoveredWithoutPublishingOlderValuesOrFlushingOtherKeys(): void
    {
        $id=$this->users[1];$token=WP_Session_Tokens::get_instance($id)->create(time()+3600);$flushes=$this->cache->flushes();
        get_user_meta($id,'_ascla_profile',true);
        $this->crash('ASCLA\\Core\\Repositories\\WordPressWrites::meta("user",'.$id.',"_ascla_profile",["company"=>"Confirmada"]);ASCLA\\Core\\Repositories\\WordPressWrites::option('.var_export($this->option,true).',"Confirmada");ASCLA\\Core\\Services\\Membership::apply('.$id.',["status"=>"suspended","until"=>0]);');
        $rows=$this->rows();self::assertCount(1,$rows);self::assertSame('pending',$rows[0]['status']);
        $plan=DeliveryPayload::open('cache',$rows[0]['payload']);self::assertSame(['flush','entries','groups'],array_keys($plan));
        self::assertStringNotContainsString('Confirmada',wp_json_encode($plan));
        self::assertSame('Original',get_user_meta($id,'_ascla_profile',true)['company'],'Physical shared cache is stale before recovery.');
        $this->execute('ASCLA\\Core\\Repositories\\CacheRecovery::reconcile();echo ASCLA\\Core\\Repositories\\Store::one("deliveries",'.$rows[0]['id'].')["status"];');
        self::assertSame('sent',Store::one('deliveries',(int)$rows[0]['id'])['status']);
        self::assertSame('Confirmada',get_user_meta($id,'_ascla_profile',true)['company']);self::assertSame('Confirmada',get_option($this->option));
        self::assertSame('suspended',Membership::status($id));self::assertFalse(WP_Session_Tokens::get_instance($id)->verify($token));self::assertFalse(Access::member($id));
        self::assertSame('',$this->rows()[0]['payload']);self::assertSame($flushes,$this->cache->flushes());self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));
    }
    public function testRollbackAndRejectedRecoveryRecordLeaveNeitherChangesNorPendingInvalidation(): void
    {
        try { Transaction::run(function() { WordPressWrites::option($this->option,'Temporal');throw new RuntimeException('Rollback'); }); }
        catch (RuntimeException) { self::assertSame('Original',get_option($this->option)); }
        self::assertSame([],$this->rows());global $wpdb;$old=$wpdb->suppress_errors(true);
        $fail=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.Store::table('deliveries').'`')?str_replace(Store::table('deliveries'),Store::table('deliveries').'_missing',$sql):$sql;
        add_filter('query',$fail);
        try { Transaction::run(function() { WordPressWrites::option($this->option,'Rechazada'); });self::fail('Expected recovery journal rejection'); }
        catch (RepositoryException) { self::assertSame('Original',get_option($this->option)); }
        finally { remove_filter('query',$fail);$wpdb->suppress_errors($old);$wpdb->last_error=''; }
        self::assertSame([],$this->rows());
    }
    public function testFalseAndThrownProviderFailuresKeepCommittedDataFreshAndRecoverAfterReconnection(): void
    {
        foreach (['failDeletes','throwDeletes'] as $failure) {
            $this->cache->$failure=true;
            Transaction::run(function() use($failure) { WordPressWrites::option($this->option,$failure); });
            self::assertInstanceOf(DatabaseCache::class,$GLOBALS['wp_object_cache']);self::assertSame($failure,get_option($this->option));
            self::assertSame('pending',$this->rows()[array_key_last($this->rows())]['status']);
            $this->cache->$failure=false;$this->due();CacheRecovery::reconcile();self::assertSame($this->cache,$GLOBALS['wp_object_cache']);
            self::assertSame($failure,get_option($this->option));
        }
        foreach ($this->rows() as $row) { self::assertSame('sent',$row['status']);self::assertSame('',$row['payload']); }
        self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));
    }
    public function testPendingRecoveryRefreshesCurrentCapabilitiesAndPreservesAtomicCacheOnlyReservations(): void
    {
        wp_set_current_user($this->users[1]);self::assertTrue(current_user_can('ascla_access'));$this->cache->failDeletes=true;
        Transaction::run(function() { $user=wp_get_current_user();$user->add_cap('ascla_access',false); });
        self::assertInstanceOf(DatabaseCache::class,$GLOBALS['wp_object_cache']);self::assertFalse(current_user_can('ascla_access'));self::assertFalse(user_can($GLOBALS['userdata'],'ascla_access'));
        self::assertTrue(wp_cache_add('lease','A','ascla_fixture'));self::assertFalse(wp_cache_add('lease','B','ascla_fixture'));
        self::assertSame('A',$this->execute('echo wp_cache_get("lease","ascla_fixture");'));
        wp_cache_set('counter',5,'ascla_fixture');self::assertSame(7,wp_cache_incr('counter',2,'ascla_fixture'));self::assertSame(7,$this->cache->get('counter','ascla_fixture'));
        $this->cache->failDeletes=false;$this->due();CacheRecovery::reconcile();self::assertFalse(current_user_can('ascla_access'));
    }
    public function testLateInvalidationCannotOverwriteASecondCommittedProfile(): void
    {
        $id=$this->users[1];$this->cache->failDeletes=true;
        Transaction::run(static fn()=>WordPressWrites::meta('user',$id,'_ascla_profile',['company'=>'Primera']));
        Transaction::run(static fn()=>WordPressWrites::meta('user',$id,'_ascla_profile',['company'=>'Segunda']));
        self::assertSame('Segunda',get_user_meta($id,'_ascla_profile',true)['company']);self::assertGreaterThanOrEqual(2,count($this->rows()));
        $this->cache->failDeletes=false;$this->due();CacheRecovery::reconcile();self::assertSame('Segunda',get_user_meta($id,'_ascla_profile',true)['company']);
        foreach ($this->rows() as $row) { self::assertSame('sent',$row['status']); }
    }
    public function testHealthyCacheMissIsAcknowledgedAndEncryptedKeysAreNotExposedByOverview(): void
    {
        Transaction::run(static fn()=>wp_cache_get('missing_private_key@example.invalid','posts'));
        $rows=$this->rows();self::assertCount(1,$rows);self::assertSame('sent',$rows[0]['status']);self::assertSame($this->cache,$GLOBALS['wp_object_cache']);
        $this->cache->failDeletes=true;Transaction::run(function() { WordPressWrites::option($this->option,'Nueva'); });
        $status=Deliveries::overview();self::assertStringNotContainsString($this->option,wp_json_encode($status));
        foreach ($status['items'] as $item) { self::assertArrayNotHasKey('payload',$item);self::assertArrayNotHasKey('actor_id',$item); }
    }
    public function testOldPendingInvalidationsAreNotExpiredAndCronAlsoRecoversThem(): void
    {
        $plan=['flush'=>false,'entries'=>[[get_current_blog_id(),$this->option,'options']],'groups'=>[]];
        $id=Store::insert('deliveries',['kind'=>'cache','delivery_key'=>bin2hex(random_bytes(16)),'actor_id'=>0,'payload'=>DeliveryPayload::seal('cache',$plan),'status'=>'pending','created_at'=>gmdate('Y-m-d H:i:s',time()-10*DAY_IN_SECONDS),'available_at'=>gmdate('Y-m-d H:i:s',time()+3600)]);
        Deliveries::run($id);self::assertSame('pending',Store::one('deliveries',$id)['status']);self::assertNotSame('',Store::one('deliveries',$id)['payload']);
        $this->due();do_action('ascla_deliveries');self::assertSame('sent',Store::one('deliveries',$id)['status']);
    }
    public function testExplicitFlushSurvivesProcessDeathAndNoPendingRecoveryIsScheduledWithoutExternalCache(): void
    {
        $flushes=$this->cache->flushes();$this->crash('wp_cache_flush();');self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));
        CacheRecovery::reconcile();self::assertFalse(wp_cache_get('unrelated','ascla_fixture'));self::assertSame($flushes+1,$this->cache->flushes());
        $before=count($this->rows());wp_using_ext_object_cache(false);Transaction::run(function() { WordPressWrites::option($this->option,'Sin proveedor'); });
        self::assertCount($before,$this->rows());self::assertSame('Sin proveedor',get_option($this->option));
    }
    public function testRejectedHealthProbeRetainsInvalidationBeyondEightAttemptsUntilRecovery(): void
    {
        $this->cache->failDeletes=true;$this->cache->failWrites=true;
        Transaction::run(function() { WordPressWrites::option($this->option,'Conservar en SQL'); });
        $id=(int)$this->rows()[0]['id'];Store::update('deliveries',['attempts'=>8],['id'=>$id]);$this->due();Deliveries::run($id);
        $row=Store::one('deliveries',$id);self::assertSame('pending',$row['status']);self::assertSame(9,(int)$row['attempts']);self::assertNotSame('',$row['payload']);
        self::assertSame('Conservar en SQL',get_option($this->option));
        $this->cache->failDeletes=false;$this->cache->failWrites=false;$this->due();CacheRecovery::reconcile();
        self::assertSame('sent',Store::one('deliveries',$id)['status']);self::assertSame('Conservar en SQL',get_option($this->option));
    }
    public function testAnActiveWorkerDoesNotBlockRequestsOrCreateSpuriousCriticalErrors(): void
    {
        $this->crash('ASCLA\\Core\\Repositories\\WordPressWrites::option('.var_export($this->option,true).',"Actualizada");');
        $ready=tempnam(sys_get_temp_dir(),'ascla-lock-');$done=tempnam(sys_get_temp_dir(),'ascla-release-');unlink($ready);unlink($done);
        $body='ASCLA\\Core\\Repositories\\Store::lock("deliveries",static function(){file_put_contents('.var_export($ready,true).',"ready");$until=microtime(true)+10;while(!file_exists('.var_export($done,true).') && microtime(true)<$until){usleep(10000);clearstatcache();}});';
        $process=proc_open([PHP_BINARY,'-r',$this->code($body)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
        try {
            $until=microtime(true)+5;while(!file_exists($ready) && microtime(true)<$until) { usleep(10000);clearstatcache(); }
            self::assertFileExists($ready);$errors=Store::count('audit','action=%s',['technical_error']);$started=microtime(true);
            CacheRecovery::reconcile();self::assertLessThan(1.5,microtime(true)-$started);self::assertInstanceOf(DatabaseCache::class,$GLOBALS['wp_object_cache']);
            self::assertSame('Actualizada',get_option($this->option));self::assertSame($errors,Store::count('audit','action=%s',['technical_error']));self::assertSame('pending',$this->rows()[0]['status']);
        } finally {
            file_put_contents($done,'release');foreach ($pipes as $pipe) { fclose($pipe); }proc_close($process);unlink($ready);unlink($done);
        }
        CacheRecovery::reconcile();self::assertSame('sent',$this->rows()[0]['status']);
    }
}
