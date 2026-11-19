<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Repositories\{Transaction,WordPressWrites};
use ASCLA\Core\Services\{Membership,AccountPermissions};
require_once __DIR__.'/../shared-cache-fixture.php';

final class TransactionCacheTest extends TestCase
{
    private array $users=[];
    private object $original;
    private bool $external;
    private string $path,$option;
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member'] as $role) {
            $login='cache_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
        $this->original=$GLOBALS['wp_object_cache'];$this->external=(bool)wp_using_ext_object_cache();
        $this->path=tempnam(sys_get_temp_dir(),'ascla-shared-');$this->option='ascla_cache_test_'.bin2hex(random_bytes(5));
        $GLOBALS['wp_object_cache']=new AsclaSharedCacheFixture($this->path);wp_using_ext_object_cache(true);
        update_user_meta($this->users[1],'_ascla_profile',['company'=>'Original']);update_option($this->option,'Original',false);
        wp_cache_set('unrelated','Conservar','ascla_fixture');
        get_user_meta($this->users[1],'_ascla_profile',true);get_option($this->option);
    }
    protected function tearDown(): void
    {
        delete_option($this->option);
        foreach ($this->users as $id) { wp_delete_user($id); }
        $GLOBALS['wp_object_cache']=$this->original;wp_using_ext_object_cache($this->external);wp_set_current_user(0);unlink($this->path);
    }
    private function code(string $expression): string
    {
        return 'require '.var_export(ABSPATH.'wp-load.php',true).'; require '.var_export(dirname(__DIR__).'/shared-cache-fixture.php',true).'; $GLOBALS["wp_object_cache"]=new AsclaSharedCacheFixture('.var_export($this->path,true).'); wp_using_ext_object_cache(true); echo wp_json_encode('.$expression.');';
    }
    private function peer(): array
    {
        $expression='["profile"=>get_user_meta('.$this->users[1].',"_ascla_profile",true),"option"=>get_option('.var_export($this->option,true).'),"active"=>ASCLA\\Core\\Services\\Membership::status('.$this->users[1].'),"access"=>user_can('.$this->users[1].',"ascla_access")]';
        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($this->code($expression)),$output,$exit);
        self::assertSame(0,$exit);$data=json_decode(implode('', $output),true);self::assertIsArray($data);return $data;
    }
    public function testOtherProcessNeverReadsUncommittedProfileMembershipSessionOrCronChanges(): void
    {
        $id=$this->users[1];$manager=WP_Session_Tokens::get_instance($id);$token=$manager->create(time()+3600);
        $before=$this->peer();$cron=_get_cron_array();$cache=$GLOBALS['wp_object_cache'];$flushes=$cache->flushes();
        try {
            Transaction::run(function() use($id,$token,$manager,$before) {
                WordPressWrites::meta('user',$id,'_ascla_profile',['company'=>'Temporal']);WordPressWrites::option($this->option,'Temporal');
                Membership::apply($id,['status'=>'suspended','until'=>time()+7200]);
                self::assertSame('Temporal',get_user_meta($id,'_ascla_profile',true)['company']);self::assertFalse($manager->verify($token));
                self::assertSame($before,$this->peer(),'The shared cache must retain confirmed values while SQL is uncommitted.');
                throw new RuntimeException('Late failure');
            });
        } catch (RuntimeException $error) { self::assertSame('Late failure',$error->getMessage()); }
        self::assertSame($before,$this->peer());self::assertTrue($manager->verify($token));self::assertSame($cron,_get_cron_array());
        self::assertSame($flushes,$cache->flushes());self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));
    }
    public function testCommitInvalidatesSharedIdentityOptionsAndPermissionsWithoutGlobalFlush(): void
    {
        $id=$this->users[1];$before=$this->peer();$cache=$GLOBALS['wp_object_cache'];$flushes=$cache->flushes();
        Transaction::run(function() use($id,$before) {
            WordPressWrites::meta('user',$id,'_ascla_profile',['company'=>'Confirmada']);WordPressWrites::option($this->option,'Confirmada');
            $plan=AccountPermissions::plan($id,'ascla_member',['permission_overrides'=>['ascla_access'=>false]]);AccountPermissions::apply($id,$plan);
            self::assertSame($before,$this->peer());
        });
        $after=$this->peer();self::assertSame('Confirmada',$after['profile']['company']);self::assertSame('Confirmada',$after['option']);self::assertFalse($after['access']);
        self::assertSame($flushes,$cache->flushes());self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));
    }
    public function testSavepointRollbackRebuildsLocalViewAndPreservesTheOuterChange(): void
    {
        $id=$this->users[1];$before=$this->peer();
        Transaction::run(function() use($id,$before) {
            WordPressWrites::meta('user',$id,'_ascla_profile',['company'=>'Externa']);
            try { Transaction::run(static function() use($id) { WordPressWrites::meta('user',$id,'_ascla_profile',['company'=>'Interna']);throw new RuntimeException('Nested rollback'); }); }
            catch (RuntimeException) { self::assertSame('Externa',get_user_meta($id,'_ascla_profile',true)['company']); }
            self::assertSame($before,$this->peer());
        });
        self::assertSame('Externa',$this->peer()['profile']['company']);
    }
    public function testRolledBackCurrentUserSnapshotDoesNotKeepTemporaryCapabilities(): void
    {
        wp_set_current_user($this->users[1]);self::assertTrue(current_user_can('ascla_access'));
        try {
            Transaction::run(function() {
                $user=wp_get_current_user();$user->add_cap('ascla_access',false);self::assertFalse(current_user_can('ascla_access'));
                throw new RuntimeException('Rollback current user');
            });
        } catch (RuntimeException) {
            self::assertTrue(current_user_can('ascla_access'));self::assertSame($this->users[1],$GLOBALS['userdata']->ID);self::assertTrue(user_can($GLOBALS['userdata'],'ascla_access'));
        }
    }
    public function testCoreCacheOperationsRemainLocalAndObjectValuesAreCloned(): void
    {
        $cache=$GLOBALS['wp_object_cache'];$flushes=$cache->flushes();
        Transaction::run(static function() {
            self::assertTrue(wp_cache_add('counter',1,'ascla_fixture'));self::assertFalse(wp_cache_add('counter',5,'ascla_fixture'));
            self::assertSame(3,wp_cache_incr('counter',2,'ascla_fixture'));self::assertSame(0,wp_cache_decr('counter',5,'ascla_fixture'));
            self::assertTrue(wp_cache_replace('counter',10,'ascla_fixture'));self::assertSame(['counter'=>10,'missing'=>false],wp_cache_get_multiple(['counter','missing'],'ascla_fixture'));
            $value=(object)['name'=>'Original'];wp_cache_set('object',$value,'ascla_fixture');$value->name='Mutated';
            $copy=wp_cache_get('object','ascla_fixture');self::assertSame('Original',$copy->name);$copy->name='Other';self::assertSame('Original',wp_cache_get('object','ascla_fixture')->name);
            self::assertSame(['a'=>true,'b'=>true],wp_cache_set_multiple(['a'=>1,'b'=>2],'ascla_fixture'));
            self::assertSame(['a'=>true,'b'=>true],wp_cache_delete_multiple(['a','b'],'ascla_fixture'));
        });
        self::assertSame($flushes,$cache->flushes());self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));
        self::assertSame(10,wp_cache_get('counter','ascla_fixture'));self::assertSame('Original',wp_cache_get('object','ascla_fixture')->name);
    }
    public function testConcurrentGenerationIncrementsAreSerializedDespiteEarlierReads(): void
    {
        WordPressWrites::bumpProfileRevision();$before=(int)get_option('ascla_profile_revision');
        $ready=tempnam(sys_get_temp_dir(),'ascla-ready-');$done=tempnam(sys_get_temp_dir(),'ascla-done-');unlink($ready);unlink($done);$process=null;$pipes=[];
        try {
            Transaction::run(function() use($before,$ready,$done,&$process,&$pipes) {
                WordPressWrites::bumpProfileRevision();
                $expression='(function(){ $old=get_option("ascla_profile_revision");file_put_contents('.var_export($ready,true).',"ready");ASCLA\\Core\\Repositories\\WordPressWrites::bumpProfileRevision();file_put_contents('.var_export($done,true).',"done");return (int)get_option("ascla_profile_revision");})()';
                $process=proc_open([PHP_BINARY,'-r',$this->code($expression)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
                self::assertIsResource($process);$until=microtime(true)+5;
                while (!file_exists($ready) && microtime(true)<$until) { usleep(10000);clearstatcache(); }
                self::assertFileExists($ready);usleep(150000);clearstatcache();self::assertFileDoesNotExist($done,'The row lock is held until the parent commit.');
                self::assertSame($before+1,(int)get_option('ascla_profile_revision'));
            });
            $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            self::assertSame(0,proc_close($process));$process=null;self::assertSame($before+2,json_decode($output,true));self::assertSame($before+2,(int)get_option('ascla_profile_revision'));
        } finally {
            if (is_resource($process)) { proc_terminate($process);foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }proc_close($process); }
            foreach ([$ready,$done] as $file) { if (file_exists($file)) { unlink($file); } }
        }
    }
    public function testCacheOnlyTransientsCommitAndRollbackWithoutLosingExistingValues(): void
    {
        $key='ascla_transient_'.bin2hex(random_bytes(5));set_transient($key,'Original',3600);
        $read=function() use($key) {
            exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($this->code('get_transient('.var_export($key,true).')')),$output,$exit);
            self::assertSame(0,$exit);return json_decode(implode('',$output),true);
        };
        try {
            try { Transaction::run(function() use($key,$read) { self::assertSame('Original',get_transient($key));set_transient($key,'Temporal',3600);self::assertSame('Temporal',get_transient($key));self::assertSame('Original',$read());throw new RuntimeException('Rollback transient'); }); }
            catch (RuntimeException) { self::assertSame('Original',$read()); }
            Transaction::run(function() use($key,$read) { set_transient($key,'Externa',3600);
                try { Transaction::run(static function() use($key) { delete_transient($key);throw new RuntimeException('Nested transient'); }); }
                catch (RuntimeException) { self::assertSame('Externa',get_transient($key)); }
                self::assertSame('Original',$read());
            });
            self::assertSame('Externa',$read());Transaction::run(static fn()=>delete_transient($key));self::assertFalse($read());
        } finally { delete_transient($key); }
    }
    public function testRolledBackCacheFlushDoesNotEraseOtherSharedEntriesOnOuterCommit(): void
    {
        $cache=$GLOBALS['wp_object_cache'];$flushes=$cache->flushes();
        Transaction::run(static function() {
            wp_cache_set('outer','Confirmado','ascla_fixture');
            try { Transaction::run(static function() { wp_cache_flush();self::assertFalse(wp_cache_get('unrelated','ascla_fixture'));throw new RuntimeException('Cancel the cache flush'); }); }
            catch (RuntimeException) { self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));self::assertSame('Confirmado',wp_cache_get('outer','ascla_fixture')); }
        });
        self::assertSame($flushes,$cache->flushes());self::assertSame('Conservar',wp_cache_get('unrelated','ascla_fixture'));self::assertSame('Confirmado',wp_cache_get('outer','ascla_fixture'));
    }
}
