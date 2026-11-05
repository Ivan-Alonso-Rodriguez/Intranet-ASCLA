<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Repositories\{RelationReads,StatisticsCache,Store,Transaction};
use ASCLA\Core\Services\{Content,ContentReactions,Matching,Messaging,Profiles,Statistics};

final class QueryOptimizationTest extends TestCase
{
    private array $users=[],$posts=[];
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $id=wp_insert_user(['user_login'=>'query_opt_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>$role]);$this->users[]=$id;
            update_user_meta($id,'_ascla_profile',ascla_test_profile());
        }
        wp_set_current_user($this->users[1]);
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->users as $id) {
            foreach (['relations','notifications','registrations','attendance'] as $table) { Store::delete($table,['user_id'=>$id]); }
            Store::delete('relations',['target_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }

    public function testBatchedReadImmediatelyObservesAChangedBlockAndDoesNotSurviveItsScope(): void
    {
        [$a,$b]=array_slice($this->users,1);
        RelationReads::run($a,function()use($a,$b){
            self::assertFalse(Messaging::blocked($a,$b));
            $id=Store::insert('relations',['user_id'=>$b,'target_id'=>$a,'kind'=>'block','created_at'=>current_time('mysql',true)]);
            self::assertTrue(Messaging::blocked($a,$b));Store::delete('relations',['id'=>$id]);self::assertFalse(Messaging::blocked($a,$b));
        });
        self::assertNull(RelationReads::pair($a,$b));
    }

    public function testPermissionReadsInsideATransactionStayLiveAndRollbackCannotPoisonTheOuterRead(): void
    {
        [$a,$b]=array_slice($this->users,1);
        RelationReads::run($a,function()use($a,$b){
            self::assertFalse(Messaging::blocked($a,$b));
            try {
                Transaction::run(function()use($a,$b){
                    self::assertNull(RelationReads::pair($a,$b));
                    Store::insert('relations',['user_id'=>$b,'target_id'=>$a,'kind'=>'block','created_at'=>current_time('mysql',true)]);
                    self::assertTrue(Messaging::blocked($a,$b));throw new RuntimeException('Rollback block fixture');
                });
            } catch (RuntimeException $error) { self::assertSame('Rollback block fixture',$error->getMessage()); }
            self::assertFalse(Messaging::blocked($a,$b));
        });
    }

    public function testSwitchingAccountsAndExceptionsCannotReuseAnotherReadScope(): void
    {
        [$a,$b]=array_slice($this->users,1);
        try {
            RelationReads::run($a,function()use($a,$b){
                self::assertSame([],RelationReads::pair($a,$b));wp_set_current_user($b);
                self::assertNull(RelationReads::pair($a,$b));
                RelationReads::run($b,function()use($a,$b){self::assertSame([],RelationReads::pair($b,$a));});
                wp_set_current_user($a);throw new RuntimeException('Exit reader');
            });
        } catch (RuntimeException $error) { self::assertSame('Exit reader',$error->getMessage()); }
        self::assertNull(RelationReads::pair($a,$b));
    }

    public function testFailedBatchPermissionQueryFailsClosed(): void
    {
        global $wpdb;[$a,$b]=array_slice($this->users,1);$old=$wpdb->suppress_errors(true);
        $filter=static fn($sql)=>str_contains($sql,"kind IN ('block','recommendation_ignore','connection_cooldown')")?'SELECT * FROM ascla_nonexistent_permission_fixture':$sql;
        add_filter('query',$filter);
        try {
            RelationReads::run($a,static fn()=>Messaging::blocked($a,$b));self::fail('Failed permission query treated as an empty block list');
        } catch (ASCLA\Core\Repositories\RepositoryException $error) { self::assertStringContainsString('permisos',$error->getMessage()); }
        finally { remove_filter('query',$filter);$wpdb->suppress_errors($old);$wpdb->last_error=''; }
        self::assertNull(RelationReads::pair($a,$b));
    }

    public function testBatchCardsMatchIndividualCardsAndKeepReactionsPrivateToTheCurrentViewer(): void
    {
        wp_set_current_user($this->users[0]);
        $p=Content::save('hub',['title'=>'Tarjeta optimizada','body'=>'Contenido publicado','status'=>'publish']);$id=$p['id'];$this->posts[]=$id;
        $private=wp_insert_post(['post_type'=>'ascla_hub','post_status'=>'draft','post_title'=>'Borrador privado','post_author'=>$this->users[0]]);$this->posts[]=$private;
        $terms=Profiles::catalogs();wp_set_object_terms($id,[(int)$terms['interest'][0]['id']],'ascla_interest');
        Store::insert('relations',['user_id'=>$this->users[1],'target_id'=>$id,'kind'=>'like','reason'=>'legacy_love','created_at'=>current_time('mysql',true)]);
        foreach (array_slice($this->users,1) as $user) {
            wp_set_current_user($user);$cards=Content::listing('hub');$index=array_search($id,array_column($cards['items'],'id'),true);
            self::assertNotFalse($index);self::assertNotContains($private,array_column($cards['items'],'id'));
            self::assertSame(Content::serialize(get_post($id)),$cards['items'][$index]);
            self::assertSame($user===$this->users[1],$cards['items'][$index]['liked']);
        }
        Store::delete('relations',['user_id'=>$this->users[1],'target_id'=>$id,'kind'=>'like']);
        self::assertSame(0,ContentReactions::summaries([$id])[$id]['reaction_total']);
    }

    public function testRecommendationProseAndMailPreferencesDoNotInvalidateAnalyticsButProfilePermissionsDo(): void
    {
        StatisticsCache::invalidate();$revision=get_option('ascla_statistics_revision');$id=$this->users[1];
        foreach (['_ascla_recommendation_batch','_ascla_network_ai','_ascla_email_notifications','_ascla_profile_review'] as $key) { update_user_meta($id,$key,['test'=>'unrelated']); }
        self::assertSame($revision,get_option('ascla_statistics_revision'));
        $profile=Profiles::raw($id);$profile['hidden']=['company'];update_user_meta($id,'_ascla_profile',$profile);
        self::assertNotSame($revision,get_option('ascla_statistics_revision'));
        wp_set_current_user($this->users[0]);$first=Statistics::dashboard([]);
        $user=$this->users[2];$raw=Profiles::raw($user);$raw['hidden']=['first_name'];update_user_meta($user,'_ascla_profile',$raw);
        $next=Statistics::dashboard([]);self::assertSame($first['summary'],$next['summary']);
    }

    public function testStatisticsPreserveUnregisteredAttendanceUnknownMinutesAndLegacyDuplicateEventMetadata(): void
    {
        wp_set_current_user($this->users[0]);
        $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'Legacy analytical fixture','post_author'=>$this->users[0]]);$this->posts[]=$id;
        update_post_meta($id,'_ascla_start',gmdate('c',time()-7200));$end=gmdate('c',time()-3600);
        update_post_meta($id,'_ascla_end',$end);add_post_meta($id,'_ascla_end',$end);update_post_meta($id,'_ascla_attendance_complete',1);
        foreach (array_slice($this->users,1) as $index=>$user) {
            Store::insert('registrations',['event_id'=>$id,'user_id'=>$user,'status'=>$index===0?'accepted':'cancelled','created_at'=>current_time('mysql',true)]);
            Store::insert('attendance',['event_id'=>$id,'user_id'=>$user,'status'=>'present','minutes'=>$index===0?null:60,'source'=>'manual','recorded_by'=>$this->users[0],'updated_at'=>current_time('mysql',true)]);
        }
        $summary=Statistics::dashboard(['event'=>$id])['summary'];
        foreach (['registrations'=>1,'attendees'=>2,'attendances'=>2,'minutes'=>60,'duration_known'=>1,'rate_registered'=>1,'rate_attended'=>1] as $key=>$expected) { self::assertSame($expected,$summary[$key],$key); }
    }

    public function testConcurrentProfileRevisionWritersDoNotDeadlockOrLoseIncrements(): void
    {
        global $wpdb;
        ASCLA\Core\Repositories\WordPressWrites::bumpProfileRevision();$before=(int)get_option('ascla_profile_revision');
        $dir=sys_get_temp_dir().'/ascla-opt-counter-'.bin2hex(random_bytes(6));mkdir($dir,0700);$workers=[];
        try {
            Transaction::run(function()use($dir,&$workers,$wpdb){
                $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='ascla_profile_revision' LOCK IN SHARE MODE");
                for ($i=0;$i<4;$i++) {
                    $code='require '.var_export(ABSPATH.'wp-load.php',true).';file_put_contents('.var_export($dir.'/'.$i,true).',"ready");ASCLA\\Core\\Repositories\\WordPressWrites::bumpProfileRevision();echo "OK";';
                    $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                    self::assertIsResource($process);fclose($pipes[0]);$workers[]=[$process,$pipes];
                }
                $deadline=microtime(true)+10;
                while (count(glob($dir.'/*'))<4 && microtime(true)<$deadline) { usleep(10000); }
                self::assertCount(4,glob($dir.'/*'));usleep(200000);
            });
            foreach ($workers as [$process,$pipes]) {
                $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                self::assertSame(0,proc_close($process),$errors);self::assertSame('OK',$output);
            }
            wp_cache_delete('ascla_profile_revision','options');
            self::assertSame($before+4,(int)get_option('ascla_profile_revision'));
        } finally {
            foreach (glob($dir.'/*') as $file) { unlink($file); }rmdir($dir);
        }
    }

    public function testCheapScoresUseNoPairTransientsAndStillHonorLivePrivacy(): void
    {
        global $wpdb;[$a,$b]=array_slice($this->users,1);$prior=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_ascla_match_%'");
        $score=Matching::between($a,$b,false,false);self::assertGreaterThan(0,$score['score']);
        self::assertSame($prior,$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_ascla_match_%'"));
        $profile=Profiles::raw($b);$profile['networking']=false;update_user_meta($b,'_ascla_profile',$profile);
        $this->expectException(ASCLA\Core\Rest\ApiException::class);Matching::between($a,$b,false,false);
    }
}
