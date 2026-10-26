<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Events,EventCalendar,Notifications};
use ASCLA\Core\Integrations\{GoogleOAuth,Secrets};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Jobs\{Deliveries,DeliveryHandlers};

final class UserExperience1110Test extends TestCase
{
    private array $users=[];
    private int $event;
    private $http;
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member','ascla_member'] as $index=>$role) {
            $login='ux1110_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(30),'user_email'=>$login.'@example.invalid','role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
        $this->event=Content::save('event',['title'=>'UX 1.11 fixture','body'=>'Encuentro de prueba','status'=>'publish','meta'=>['start'=>gmdate('c',time()+86400),'end'=>gmdate('c',time()+90000),'url'=>'https://us02web.zoom.us/j/123456789','capacity'=>1]])['id'];
    }
    protected function tearDown(): void
    {
        if ($this->http) { remove_filter('pre_http_request',$this->http,10); }
        wp_set_current_user($this->users[0]);wp_delete_post($this->event,true);
        foreach ($this->users as $user) {
            Secrets::remove('google_calendar_'.$user);
            foreach (['registrations','notifications'] as $table) { Store::delete($table,['user_id'=>$user]); }
            Store::delete('deliveries',['actor_id'=>$user]);wp_delete_user($user);
        }
    }
    private function connected(int $user): void
    {
        Secrets::set('google_calendar_'.$user,wp_json_encode(['access_token'=>'fixture-only','expires_at'=>time()+3600]));
    }
    private function mock(callable $handler): void
    {
        $this->http=static fn($pre,$args,$url)=>str_starts_with($url,'https://www.googleapis.com/calendar/')?$handler($args,$url):$pre;
        add_filter('pre_http_request',$this->http,10,3);
    }
    private static function response(array $body=[],int $code=200): array
    {
        return ['headers'=>[],'body'=>wp_json_encode($body),'response'=>['code'=>$code,'message'=>'fixture'],'cookies'=>[]];
    }
    public function testManualCalendarIncludesJoinUrlOnlyForConfirmedRegistration(): void
    {
        wp_set_current_user($this->users[1]);
        self::assertStringNotContainsString(rawurlencode('https://us02web.zoom.us/j/123456789'),Events::detail($this->event)['google_url']);
        $registered=Events::register($this->event,'accepted');
        parse_str((string)parse_url($registered['google_url'],PHP_URL_QUERY),$template);
        self::assertSame('TEMPLATE',$template['action']);
        self::assertStringContainsString('https://us02web.zoom.us/j/123456789',$template['details']);
        Events::register($this->event,'cancelled');
        self::assertStringNotContainsString(rawurlencode('https://us02web.zoom.us/j/123456789'),Events::detail($this->event)['google_url']);
    }
    public function testRegistrationAutomaticallySavesAndCancellationRemovesPersonalCalendar(): void
    {
        $member=$this->users[1];wp_set_current_user($member);$this->connected($member);$calls=[];
        $this->mock(static function($args,$url)use(&$calls) {
            $calls[]=$args['method'];
            if ($args['method']==='POST') {
                $body=json_decode($args['body'],true);
                self::assertStringContainsString('https://us02web.zoom.us/j/123456789',$body['description']);
                self::assertSame('private',$body['visibility']);
                self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/',$body['id']);
            }
            return self::response(['id'=>'personal-fixture']);
        });
        self::assertArrayNotHasKey('url',Events::detail($this->event)['meta']);
        $registered=Events::register($this->event,'accepted');
        self::assertSame('synced',$registered['calendar_status']);
        self::assertSame('https://us02web.zoom.us/j/123456789',$registered['meta']['url']);
        Events::register($this->event,'accepted');
        Events::register($this->event,'cancelled');
        self::assertSame(['POST','PATCH','DELETE'],$calls);
        self::assertSame('',get_user_meta($member,'_ascla_google_event_'.$this->event,true));
        self::assertArrayNotHasKey('url',Events::detail($this->event)['meta']);
    }
    public function testPersonalRetryUsesDeterministicIdAfterAmbiguousCreate(): void
    {
        wp_set_current_user($this->users[1]);$this->connected($this->users[1]);$calls=[];$requestId='';
        $this->mock(static function($args,$url)use(&$calls,&$requestId) {
            $calls[]=$args['method'];$body=json_decode($args['body'],true);
            if ($args['method']==='POST') { $requestId=$body['id'];return self::response([],409); }
            self::assertStringEndsWith('/'.$requestId,$url);
            return self::response(['id'=>$requestId]);
        });
        self::assertSame('synced',Events::register($this->event,'accepted')['calendar_status']);
        self::assertSame(['POST','PATCH'],$calls);
    }
    public function testWaitlistedMemberDoesNotReceiveJoinUrlOrPersonalCopy(): void
    {
        wp_set_current_user($this->users[1]);Events::register($this->event,'accepted');
        wp_set_current_user($this->users[2]);$this->connected($this->users[2]);$calls=0;
        $this->mock(static function()use(&$calls){$calls++;return self::response();});
        $detail=Events::register($this->event,'waitlisted');
        self::assertArrayNotHasKey('url',$detail['meta']);self::assertSame(0,$calls);
        $this->expectException(ASCLA\Core\Rest\ApiException::class);
        GoogleOAuth::calendar($this->event,'save');
    }
    public function testOrganizerInvitationIsReconciledOnAcceptanceAndCancellation(): void
    {
        $organizer=$this->users[0];$member=$this->users[1];$this->connected($organizer);
        update_post_meta($this->event,'_ascla_google_organizer_user',$organizer);
        update_post_meta($this->event,'_ascla_google_organizer_event','organizer-fixture');
        $email=get_userdata($member)->user_email;$guests=[];$calls=0;
        $this->mock(static function($args,$url)use(&$guests,&$calls,$email) {
            if ($args['method']==='GET') return self::response(['attendees'=>[['email'=>$email],['email'=>'external@example.invalid']]]);
            self::assertSame('PATCH',$args['method']);self::assertStringContainsString('sendUpdates=all',$url);
            $guests[]=array_column(json_decode($args['body'],true)['attendees'],'email');$calls++;
            return self::response(['id'=>'organizer-fixture']);
        });
        wp_set_current_user($member);
        self::assertSame('invited',Events::register($this->event,'accepted')['calendar_status']);
        Events::register($this->event,'cancelled');
        self::assertSame(2,$calls);self::assertContains($email,$guests[0]);
        self::assertNotContains($email,$guests[1]);self::assertContains('external@example.invalid',$guests[1]);
    }
    public function testFailedGoogleDeliveryPreservesRegistrationAndReportsPending(): void
    {
        wp_set_current_user($this->users[1]);$this->connected($this->users[1]);
        $this->mock(static fn()=>self::response([],503));
        $detail=Events::register($this->event,'accepted');
        self::assertSame('accepted',$detail['registered']);self::assertSame('pending',$detail['calendar_status']);
        self::assertSame('accepted',Events::detail($this->event)['registered']);
    }
    public function testDelayedJobNeverRecreatesACancelledRegistration(): void
    {
        wp_set_current_user($this->users[1]);Events::register($this->event,'accepted');Events::register($this->event,'cancelled');
        $this->connected($this->users[1]);$calls=0;
        $this->mock(static function()use(&$calls){$calls++;return self::response();});
        self::assertTrue(DeliveryHandlers::send(['kind'=>'calendar'],['id'=>$this->event,'participation'=>true]));
        self::assertSame(0,$calls);
    }
    public function testBulkDeleteIncludesOtherPagesButPreservesOtherUsersAndNewNotices(): void
    {
        $user=$this->users[1];$other=$this->users[2];
        for ($i=0;$i<25;$i++) Notifications::send($user,'test','Fixture '.$i,'');
        Notifications::send($other,'test','Other account','');
        wp_set_current_user($user);$snapshot=Notifications::summary()['latest_id'];
        Notifications::send($user,'test','Arrived after confirmation','');
        $result=Notifications::deleteAll($snapshot);
        self::assertSame(25,$result['deleted']);self::assertSame(1,Notifications::feed([])['total']);
        self::assertSame(1,Store::count('notifications','user_id=%d',[$other]));
        self::assertSame(0,Notifications::deleteAll($snapshot)['deleted']);
    }
    public function testBulkDeleteRejectsMissingSnapshot(): void
    {
        $this->expectException(ASCLA\Core\Rest\ApiException::class);Notifications::deleteAll(0);
    }
}
