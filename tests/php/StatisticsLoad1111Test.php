<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Repositories\{StatisticsCache,Transaction};

final class StatisticsLoad1111Test extends TestCase
{
    private array $users=[];
    protected function setUp(): void
    {
        foreach(['administrator','ascla_executive'] as $role){$this->users[]=wp_insert_user(['user_login'=>'stats_load_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(30),'role'=>$role]);}
        wp_set_current_user($this->users[0]);StatisticsCache::invalidate();
    }
    protected function tearDown(): void
    {
        foreach($this->users as $id){delete_transient('ascla_statistics_'.get_current_blog_id().'_'.$id);wp_delete_user($id);}wp_set_current_user(0);
    }
    private function slot(): string {return 'ascla_statistics_'.get_current_blog_id().'_'.get_current_user_id();}
    public function testAlternatingFiltersReuseTheirOwnSnapshotAndKeepOnlyEightContexts(): void
    {
        $calls=0;$calculate=static function()use(&$calls){return ['number'=>++$calls];};
        for($n=0;$n<3;$n++)StatisticsCache::remember(['page'=>$n],$calculate);
        for($n=0;$n<3;$n++)self::assertSame($n+1,StatisticsCache::remember(['page'=>$n],$calculate)['number']);
        self::assertSame(3,$calls);
        for($n=3;$n<20;$n++)StatisticsCache::remember(['page'=>$n],$calculate);
        self::assertCount(StatisticsCache::MAX_CONTEXTS,get_transient($this->slot())['entries']);self::assertSame(8,StatisticsCache::MAX_CONTEXTS);
    }
    public function testEntriesExpireIndividuallyAndAnotherFilterCannotExtendTheirLifetime(): void
    {
        $calls=0;$calculate=static function()use(&$calls){return ['number'=>++$calls];};
        StatisticsCache::remember(['page'=>1],$calculate);$slot=$this->slot();$cached=get_transient($slot);foreach($cached['entries'] as &$entry)$entry['expires']=time()-1;unset($entry);set_transient($slot,$cached,30);
        StatisticsCache::remember(['page'=>2],$calculate);self::assertSame(3,StatisticsCache::remember(['page'=>1],$calculate)['number']);self::assertSame(30,StatisticsCache::TTL);
    }
    public function testDifferentAccountsAndCommittedInvalidationCannotReuseCachedResults(): void
    {
        $calls=0;$calculate=static function()use(&$calls){return ['number'=>++$calls];};
        self::assertSame(1,StatisticsCache::remember([],$calculate)['number']);wp_set_current_user($this->users[1]);self::assertSame(2,StatisticsCache::remember([],$calculate)['number']);
        wp_set_current_user($this->users[0]);self::assertSame(1,StatisticsCache::remember([],$calculate)['number']);StatisticsCache::invalidate();self::assertSame(3,StatisticsCache::remember([],$calculate)['number']);
    }
    public function testTransactionsBypassEveryContextAndDoNotPublishUncommittedSnapshots(): void
    {
        $calls=0;$calculate=static function()use(&$calls){return ['number'=>++$calls];};StatisticsCache::remember([],$calculate);
        Transaction::run(function()use($calculate){self::assertSame(2,StatisticsCache::remember([],$calculate)['number']);self::assertSame(3,StatisticsCache::remember([],$calculate)['number']);});
        self::assertSame(1,StatisticsCache::remember([],$calculate)['number']);
    }
}
