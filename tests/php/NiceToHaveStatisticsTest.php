<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Audit,Content,Profiles,Statistics};
use ASCLA\Core\Repositories\{StatisticsCache,Store,Transaction};

final class NiceToHaveStatisticsTest extends TestCase
{
    private array $users=[],$posts=[],$comments=[];
    private array $filter=[];

    protected function setUp(): void
    {
        foreach (['administrator','ascla_executive','ascla_member','ascla_member','ascla_moderator'] as $role) {
            $this->users[]=wp_insert_user(['user_login'=>'nice_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>$role,'display_name'=>'Private marker '.bin2hex(random_bytes(4))]);
        }
        wp_set_current_user($this->users[0]);
        $this->filter=['from'=>wp_date('Y-m-d',time()-DAY_IN_SECONDS),'to'=>wp_date('Y-m-d')];
    }

    protected function tearDown(): void
    {
        foreach ($this->comments as $id) { wp_delete_comment($id,true); }
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->users as $id) {
            foreach (['relations','registrations','attendance','notifications'] as $table) { Store::delete($table,['user_id'=>$id]); }
            Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        StatisticsCache::invalidate();wp_set_current_user(0);
    }

    private function post(string $type,string $status='publish'): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_status'=>$status,'post_title'=>'Community totals fixture','post_author'=>$this->users[2]]);
        $this->posts[]=$id;return $id;
    }

    public function testCommunityCountsDeduplicateConnectionsAndExcludeDraftsAndUnapprovedComments(): void
    {
        $before=Statistics::dashboard($this->filter)['community'];
        $id=Store::insert('relations',['user_id'=>$this->users[2],'target_id'=>$this->users[3],'kind'=>'connected','created_at'=>current_time('mysql',true)]);
        Store::insert('relations',['user_id'=>$this->users[3],'target_id'=>$this->users[2],'kind'=>'connected','created_at'=>current_time('mysql',true)]);
        Audit::record('connection_accepted',$id);
        $topic=$this->post('topic');$this->post('hub');$this->post('hub','draft');$this->post('contact');
        foreach ([1,0] as $approved) {
            $this->comments[]=wp_insert_comment(['comment_post_ID'=>$topic,'user_id'=>$this->users[3],'comment_content'=>'Never return this comment text in analytics','comment_approved'=>$approved]);
        }
        $data=Statistics::dashboard($this->filter);$after=$data['community'];
        self::assertSame($before['active_connections']+1,$after['active_connections']);
        self::assertSame($before['accepted_connections']+1,$after['accepted_connections']);
        self::assertSame($before['publications']+2,$after['publications']);
        self::assertSame($before['forum_topics']+1,$after['forum_topics']);
        self::assertSame($before['comments']+1,$after['comments']);
        self::assertSame($before['forum_comments']+1,$after['forum_comments']);
        self::assertStringNotContainsString('Never return this comment text',wp_json_encode($data));
    }

    public function testRepeatedDashboardUsesFewerQueriesAndRetainsNoProfileNamesInItsSnapshot(): void
    {
        global $wpdb;
        StatisticsCache::invalidate();$start=$wpdb->num_queries;$first=Statistics::dashboard($this->filter);$cold=$wpdb->num_queries-$start;
        $start=$wpdb->num_queries;$second=Statistics::dashboard($this->filter);$warm=$wpdb->num_queries-$start;
        self::assertSame($first,$second);self::assertLessThan($cold/2,$warm);
        $slot='ascla_statistics_'.get_current_blog_id().'_'.$this->users[0];$snapshot=get_transient($slot);
        self::assertIsArray($snapshot);
        self::assertSame(30,StatisticsCache::TTL);
        foreach ($this->users as $id) { self::assertStringNotContainsString(get_userdata($id)->display_name,wp_json_encode($snapshot)); }
        $this->post('topic');$next=Statistics::dashboard($this->filter);
        self::assertSame($first['community']['publications']+1,$next['community']['publications']);
        $names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",'_transient_ascla_statistics_'.get_current_blog_id().'_'.$this->users[0]));
        self::assertCount(1,$names,'Filter changes must not create an unbounded collection of cache slots.');
    }

    public function testRollbackNeverPublishesUncommittedStatisticsAndCommitInvalidatesTheSnapshot(): void
    {
        $before=Statistics::dashboard($this->filter)['community']['active_connections'];
        try {
            Transaction::run(function()use($before){
                Store::insert('relations',['user_id'=>$this->users[2],'target_id'=>$this->users[3],'kind'=>'connected','created_at'=>current_time('mysql',true)]);
                self::assertSame($before+1,Statistics::dashboard($this->filter)['community']['active_connections']);
                throw new RuntimeException('Rollback analytics fixture');
            });
        } catch (RuntimeException $error) { self::assertSame('Rollback analytics fixture',$error->getMessage()); }
        self::assertSame($before,Statistics::dashboard($this->filter)['community']['active_connections']);
        Transaction::run(function(){Store::insert('relations',['user_id'=>$this->users[2],'target_id'=>$this->users[3],'kind'=>'connected','created_at'=>current_time('mysql',true)]);});
        self::assertSame($before+1,Statistics::dashboard($this->filter)['community']['active_connections']);
    }

    public function testNestedRollbackDoesNotSuppressTheInvalidationOfALaterCommittedWrite(): void
    {
        $before=Statistics::dashboard($this->filter)['community']['active_connections'];
        $revision=get_option('ascla_statistics_revision');
        Transaction::run(function()use($revision){
            try {
                Transaction::run(function(){StatisticsCache::invalidate();throw new RuntimeException('Inner rollback');});
            } catch (RuntimeException $error) { self::assertSame('Inner rollback',$error->getMessage()); }
            Store::insert('relations',['user_id'=>$this->users[2],'target_id'=>$this->users[3],'kind'=>'connected','created_at'=>current_time('mysql',true)]);
            StatisticsCache::invalidate();
            self::assertSame($revision,get_option('ascla_statistics_revision'));
        });
        self::assertNotSame($revision,get_option('ascla_statistics_revision'));
        self::assertSame($before+1,Statistics::dashboard($this->filter)['community']['active_connections']);
    }

    public function testCachedStaffDataCannotBeReadAfterSwitchingToAMemberOrModerator(): void
    {
        Statistics::dashboard($this->filter);
        foreach ([$this->users[2],$this->users[4],0] as $id) {
            wp_set_current_user($id);
            try { Statistics::dashboard($this->filter);self::fail('Unauthorized analytics access'); }
            catch (ASCLA\Core\Rest\ApiException $error) { self::assertContains($error->getCode(),[401,403]); }
        }
        wp_set_current_user($this->users[1]);self::assertArrayHasKey('active_connections',Statistics::dashboard($this->filter)['community']);
    }

    public function testMicroeventEditsRecordTheActualDatesAndResponsibleStaffInTheAudit(): void
    {
        $start=gmdate('c',time()+14*DAY_IN_SECONDS);$end=gmdate('c',time()+14*DAY_IN_SECONDS+3600);
        $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'draft','post_title'=>'Reviewed circle','post_content'=>'Agenda','post_author'=>$this->users[0]]);$this->posts[]=$id;
        update_post_meta($id,'_ascla',ASCLA\Core\Services\MicroLifecycle::defaults(['micro'=>true,'start'=>$start,'end'=>$end,'capacity'=>4,'micro_min'=>2,'url'=>'https://example.invalid/meeting']));
        $saved=Content::serialize(get_post($id));
        $later=gmdate('c',strtotime($start)+DAY_IN_SECONDS);
        Content::save('event',['title'=>$saved['title'],'body'=>$saved['body'],'status'=>'draft','meta'=>['start'=>$later,'end'=>gmdate('c',strtotime($end)+DAY_IN_SECONDS)]],$saved['id']);
        $rows=Store::rows('audit',"object_id=%d AND action='microevent_updated'",[$saved['id']]);self::assertCount(1,$rows);
        $diff=json_decode($rows[0]['detail'],true);
        self::assertSame($this->users[0],(int)$rows[0]['actor_id']);
        self::assertSame(['before'=>$start,'after'=>$later],$diff['start']);
        self::assertArrayHasKey('end',$diff);self::assertNotEmpty($rows[0]['created_at']);
    }
}
