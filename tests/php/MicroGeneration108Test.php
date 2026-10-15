<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Events,MicroEvents,MicroPlanning,MicroArchive,Settings,EventReminders,ContentSchedule};
use ASCLA\Core\Repositories\Store;

final class MicroGeneration108Test extends TestCase
{
    private array $users=[],$posts=[],$settings=[],$history=[];
    private string $batch;
    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->history=get_option('ascla_group_history',[]);
        foreach (['administrator','ascla_member','ascla_member','ascla_member'] as $role) {
            $login='microgen_'.bin2hex(random_bytes(5));$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);$this->users[]=$id;
            update_user_meta($id,'_ascla_profile',ascla_test_profile(['microevents'=>true,'directory'=>false,'networking'=>false]));
        }
        wp_set_current_user($this->users[0]);Settings::save(['ai_provider'=>'mock','micro_min'=>4,'micro_capacity'=>6,'micro_limit'=>4]);
        $ids=$this->users;sort($ids);$this->batch='ascla_micro_demo-'.substr(hash('sha256',wp_json_encode($ids)),0,12).'-'.MicroPlanning::period();
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]);
        foreach ($this->posts as $id) { EventReminders::cancel($id);ContentSchedule::cancel($id);wp_clear_scheduled_hook('ascla_micro_tick',[$id]);Store::delete('registrations',['event_id'=>$id]);Store::delete('audit',['object_id'=>$id]);wp_delete_post($id,true); }
        foreach ($this->users as $id) { foreach (['relations','notifications','jobs'] as $table) { Store::delete($table,['user_id'=>$id]); }foreach (['audit','deliveries'] as $table) { Store::delete($table,['actor_id'=>$id]); }wp_clear_scheduled_hook('ascla_micro_candidate_changed',[$id]);wp_delete_user($id); }
        delete_option($this->batch);update_option('ascla_settings',$this->settings,false);update_option('ascla_group_history',$this->history,false);wp_set_current_user(0);
    }
    private function create(): array
    {
        $result=MicroEvents::create($this->users);$this->posts=array_values(array_unique(array_merge($this->posts,$result['events'])));return $result;
    }
    private function api(string $path,array $data): WP_REST_Response
    {
        $r=new WP_REST_Request('POST','/ascla/v1/'.$path);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));return rest_do_request($r);
    }
    public function testFourEligibleMembersWithACommonInterestCanCreateAndRepeatWithoutDuplicates(): void
    {
        $result=$this->create();self::assertCount(1,$result['events']);self::assertSame([],$result['waiting']);
        self::assertFalse($result['reused']);self::assertSame('ready',$result['diagnostics']['reason']);self::assertSame(4,$result['diagnostics']['max_shared_interest']);
        $again=$this->create();self::assertTrue($again['reused']);self::assertSame($result['events'],$again['events']);
    }
    public function testZeroResultsFromAHigherMinimumCanBeRetriedAfterCorrectingTheSetting(): void
    {
        Settings::save(['micro_min'=>5]);$result=$this->create();self::assertSame([],$result['events']);
        self::assertCount(4,$result['waiting']);self::assertSame('insufficient_candidates',$result['diagnostics']['reason']);
        Settings::save(['micro_min'=>4]);self::assertCount(1,$this->create()['events']);
    }
    public function testFourWaitingMembersWithDifferentInterestsExplainTheMissingSharedGroup(): void
    {
        $topics=ASCLA\Core\Services\Profiles::catalogs()['interest'];self::assertGreaterThanOrEqual(2,count($topics));
        foreach ($this->users as $i=>$id) { $p=get_user_meta($id,'_ascla_profile',true);$p['interests']=[(int)$topics[$i%2]['id']];update_user_meta($id,'_ascla_profile',$p); }
        $result=$this->create();self::assertSame([],$result['events']);self::assertCount(4,$result['waiting']);
        self::assertSame(4,$result['diagnostics']['eligible']);self::assertSame(2,$result['diagnostics']['max_shared_interest']);self::assertSame('insufficient_shared_interest',$result['diagnostics']['reason']);
        foreach ($this->users as $id) { $p=get_user_meta($id,'_ascla_profile',true);$p['interests']=[(int)$topics[0]['id']];update_user_meta($id,'_ascla_profile',$p); }
        self::assertCount(1,$this->create()['events']);
    }
    public function testHiddenInterestsAreNotUsedToForceAGroupOrLeakedInDiagnostics(): void
    {
        foreach ($this->users as $id) { $p=get_user_meta($id,'_ascla_profile',true);$p['hidden']=['interests'];update_user_meta($id,'_ascla_profile',$p); }
        $result=$this->create();self::assertSame([],$result['events']);self::assertSame(4,$result['diagnostics']['eligible']);self::assertSame(0,$result['diagnostics']['max_shared_interest']);
        self::assertArrayNotHasKey('users',$result['diagnostics']);self::assertArrayNotHasKey('interests',$result['diagnostics']);
    }
    public function testBlocksExplainIncompatibilityWithoutBypassingThem(): void
    {
        $id=Store::insert('relations',['user_id'=>$this->users[0],'target_id'=>$this->users[1],'kind'=>'block','created_at'=>current_time('mysql',true)]);
        $result=$this->create();self::assertSame([],$result['events']);self::assertSame('incompatible_group',$result['diagnostics']['reason']);self::assertSame(1,$result['diagnostics']['blocked_pairs']);
        Store::delete('relations',['id'=>$id]);self::assertCount(1,$this->create()['events']);
    }
    public function testCancelledAndDeniedProposalsCanBeArchivedAndRestoredWithoutDeletingTheirRecords(): void
    {
        $id=$this->create()['events'][0];self::assertSame(409,$this->api('events/'.$id.'/archive',['archived'=>true])->get_status());
        Events::cancel($id,'No se realizará.');$history=get_post_meta($id,'_ascla_micro_history',true);$meta=get_post_meta($id,'_ascla',true);
        $result=$this->api('events/'.$id.'/archive',['archived'=>true]);self::assertSame(200,$result->get_status());self::assertTrue($result->get_data()['meta']['micro_archived']);
        self::assertSame('ascla_hidden',get_post_status($id));self::assertSame($history,get_post_meta($id,'_ascla_micro_history',true));self::assertSame($meta,get_post_meta($id,'_ascla',true));
        self::assertFalse(Content::canDelete(get_post($id)));self::assertSame([$id],$this->create()['events']);
        self::assertFalse(MicroArchive::set($id,false)['meta']['micro_archived']);
        wp_set_current_user($this->users[1]);self::assertSame(403,$this->api('events/'.$id.'/archive',['archived'=>true])->get_status());
        wp_set_current_user($this->users[0]);self::assertSame(400,$this->api('events/'.$id.'/archive',['archived'=>[]])->get_status());
    }
    public function testDeniedArchiveKeepsTheDenialReasonAndRestoringDoesNotReopenApproval(): void
    {
        $id=$this->create()['events'][0];Content::moderate($id,'reject','Tema ya atendido.');$history=get_post_meta($id,'_ascla_micro_history',true);
        MicroArchive::set($id,true);$result=MicroArchive::set($id,false);
        self::assertSame('denied',$result['meta']['micro_state']);self::assertSame('ascla_rejected',$result['status']);self::assertFalse($result['can_moderate']);self::assertSame($history,get_post_meta($id,'_ascla_micro_history',true));
    }
}
