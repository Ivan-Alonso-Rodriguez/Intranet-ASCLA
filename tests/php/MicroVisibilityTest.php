<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{Content,Events,MicroLifecycle,AssistantContext};
use ASCLA\Core\Repositories\Store;

/** A published microevent remains private even after legacy public-opening dates. */
final class MicroVisibilityTest extends TestCase
{
    private array $users=[],$events=[];
    private string $prefix;
    protected function setUp(): void
    {
        $this->prefix='PrivateMicro'.bin2hex(random_bytes(5));
        foreach (['administrator','ascla_executive','ascla_moderator','ascla_member','ascla_member'] as $role) {
            $name='private_micro_'.bin2hex(random_bytes(5));
            $id=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);
            $this->users[]=$id;update_user_meta($id,'_ascla_profile',ascla_test_profile(['microevents'=>true]));
        }
        add_filter('pre_wp_mail','__return_true');wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach ($this->events as $id) {Store::delete('registrations',['event_id'=>$id]);wp_clear_scheduled_hook('ascla_micro_tick',[$id]);wp_delete_post($id,true);}
        foreach ($this->users as $id) {Store::delete('notifications',['user_id'=>$id]);wp_delete_user($id);}
        remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    private function event(array $meta=[],string $status='publish',?int $author=null): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>$status,'post_title'=>$this->prefix.' circle','post_content'=>'Private discussion','post_author'=>$author??$this->users[0]]);$this->events[]=$id;
        update_post_meta($id,'_ascla',$meta+['micro'=>true,'invitees'=>[$this->users[3]],'public_at'=>time()-DAY_IN_SECONDS,'start'=>gmdate('c',time()+2*DAY_IN_SECONDS),'end'=>gmdate('c',time()+2*DAY_IN_SECONDS+3600),'registration_deadline'=>gmdate('c',time()+DAY_IN_SECONDS),'capacity'=>4]);
        return $id;
    }
    private function get(string $route): WP_REST_Response
    {
        return rest_get_server()->dispatch(new WP_REST_Request('GET','/ascla/v1/'.$route));
    }
    public static function outsiders(): array {return [['moderator',2],['member',4]];}
    #[DataProvider('outsiders')]
    public function testUninvitedReadersCannotUseAnyDirectReadRouteOrRegister(string $role,int $index): void
    {
        unset($role);$id=$this->event();wp_set_current_user($this->users[$index]);
        self::assertFalse(Content::canRead(get_post($id)));
        foreach (['items/'.$id,'events/'.$id,'items/'.$id.'/comments'] as $route) {self::assertSame(404,$this->get($route)->get_status(),$route);}
        try {Events::register($id,'accepted');self::fail('Uninvited registration must fail');} catch (ASCLA\Core\Rest\ApiException $error) {self::assertSame(404,$error->getCode());}
        self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
    }
    public function testHiddenMicroeventsDoNotInflateSearchOrCalendarPageCounts(): void
    {
        for ($i=0;$i<24;$i++) {$this->event();}
        $public=$this->event(['micro'=>false]);$invited=$this->event(['invitees'=>[$this->users[4]]]);
        wp_set_current_user($this->users[4]);
        foreach ([[],['month'=>gmdate('Y-m',time()+2*DAY_IN_SECONDS)]] as $extra) {
            $list=Content::listing('event',$extra+['q'=>$this->prefix,'per_page'=>1]);$next=Content::listing('event',$extra+['q'=>$this->prefix,'per_page'=>1,'page'=>2]);
            self::assertSame(2,$list['total']);self::assertSame(2,$list['pages']);
            self::assertEqualsCanonicalizing([$public,$invited],array_column(array_merge($list['items'],$next['items']),'id'));
        }
    }
    public function testLegacyAuthorshipOrRegistrationDoesNotGrantInvitationAccess(): void
    {
        $id=$this->event([], 'publish',$this->users[4]);
        Store::insert('registrations',['event_id'=>$id,'user_id'=>$this->users[4],'status'=>'accepted','created_at'=>current_time('mysql',true)]);
        wp_set_current_user($this->users[4]);self::assertFalse(Content::canRead(get_post($id)));
        self::assertSame(0,Content::listing('event',['q'=>$this->prefix,'mine'=>true])['total']);
        self::assertSame(404,$this->get('items/'.$id)->get_status());
    }
    public function testInvitedReadersCanReadAndKeepTheirRsvpChoices(): void
    {
        $id=$this->event();wp_set_current_user($this->users[3]);
        self::assertSame(200,$this->get('items/'.$id)->get_status());self::assertSame(200,$this->get('events/'.$id)->get_status());
        self::assertSame('accepted',Events::register($id,'accepted')['registered']);
        self::assertSame('declined',Events::register($id,'declined')['registered']);
        self::assertTrue(Content::canRead(get_post($id)),'Declining does not erase the invitation.');
    }
    public function testManagersKeepAccessAndInvitedUsersCannotReadAnUnpublishedProposal(): void
    {
        $id=$this->event();$pending=$this->event([], 'pending');
        foreach ([0,1] as $manager) {wp_set_current_user($this->users[$manager]);self::assertTrue(Content::canRead(get_post($id)));self::assertSame(200,$this->get('items/'.$pending)->get_status());}
        wp_set_current_user($this->users[3]);self::assertSame(404,$this->get('items/'.$pending)->get_status());
        wp_set_current_user(0);self::assertFalse(Content::canRead(get_post($id)));
    }
    public function testPublishingOwnerKeepsAccessToManageOnlyTheirOwnProposal(): void
    {
        $own=$this->event([], 'pending',$this->users[1]);$foreign=$this->event();
        $owner=get_userdata($this->users[1]);$owner->add_cap('ascla_manage',false);wp_set_current_user($owner->ID);
        self::assertTrue(Content::canRead(get_post($own)));self::assertFalse(Content::canRead(get_post($foreign)));
        self::assertSame([$own],array_column(Content::listing('event',['q'=>$this->prefix,'mine'=>true])['items'],'id'));
    }
    public function testAssistantAndRevokedInvitationsUseCurrentPermissions(): void
    {
        $id=$this->event();wp_set_current_user($this->users[3]);self::assertTrue(Content::canRead(get_post($id)));
        $meta=get_post_meta($id,'_ascla',true);$meta['invitees']=[];update_post_meta($id,'_ascla',$meta);
        self::assertFalse(Content::canRead(get_post($id)));self::assertSame(0,Content::listing('event',['q'=>$this->prefix])['total']);
        self::assertNotContains($id,array_column(AssistantContext::build('Detalles del evento '.$this->prefix)['sources'],'id'));
        self::assertFalse(MicroLifecycle::canRegister($meta,$this->users[3]));
    }
}
