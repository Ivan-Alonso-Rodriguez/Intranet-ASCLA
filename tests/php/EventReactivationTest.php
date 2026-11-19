<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Events,EventReactivation,EventParticipation,EventReminders,Notifications};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Integrations\{Secrets,GoogleOAuth};

final class EventReactivationTest extends TestCase
{
    private array $users=[];
    private int $event;
    private $http;
    protected function setUp(): void
    {
        foreach (['administrator','ascla_executive','ascla_member','ascla_member','ascla_moderator'] as $role) {
            $login='reactivate_'.bin2hex(random_bytes(5));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(30),'user_email'=>$login.'@example.invalid','role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
        $this->event=Content::save('event',['title'=>'Reactivación de encuentro','body'=>'Prueba local','status'=>'publish','meta'=>['start'=>gmdate('c',time()+3*DAY_IN_SECONDS),'end'=>gmdate('c',time()+3*DAY_IN_SECONDS+3600),'capacity'=>2,'url'=>'https://us02web.zoom.us/j/123456789']])['id'];
    }
    protected function tearDown(): void
    {
        if ($this->http) remove_filter('pre_http_request',$this->http,10);
        EventReminders::cancel($this->event);wp_clear_scheduled_hook('ascla_event_offers',[$this->event]);wp_clear_scheduled_hook('ascla_micro_tick',[$this->event]);
        Store::delete('registrations',['event_id'=>$this->event]);wp_delete_post($this->event,true);
        foreach ($this->users as $user) { Secrets::remove('google_calendar_'.$user);Store::delete('notifications',['user_id'=>$user]);Store::delete('deliveries',['actor_id'=>$user]);wp_delete_user($user); }
        wp_set_current_user(0);
    }
    private function denied(callable $action,int $code): void
    {
        try { $action();self::fail('Expected refusal'); }
        catch (ASCLA\Core\Rest\ApiException $e) { self::assertSame($code,$e->getCode()); }
    }
    private function cancel(): void
    {
        wp_set_current_user($this->users[0]);Events::cancel($this->event,'Cambio operativo');
    }
    public function testExecutiveReopensAndPreservesRegistrationHistoryAndNotifiesEachCycle(): void
    {
        wp_set_current_user($this->users[2]);Events::register($this->event,'accepted');$this->cancel();
        wp_set_current_user($this->users[1]);$active=EventReactivation::run($this->event);
        self::assertFalse($active['cancelled']);self::assertSame(1,$active['attending']);
        self::assertArrayNotHasKey('cancellation_reason',$active['meta']);
        $history=get_post_meta($this->event,'_ascla_event_reactivations',true);
        self::assertSame('Cambio operativo',$history[0]['cancellation_reason']);self::assertSame($this->users[1],$history[0]['reactivated_by']);
        self::assertNotFalse(wp_next_scheduled('ascla_event_reminder',[$this->event,24]));
        $this->denied(fn()=>EventReactivation::run($this->event),409);
        self::assertCount(1,get_post_meta($this->event,'_ascla_event_reactivations',true));
        Events::cancel($this->event,'Segunda cancelación');EventReactivation::run($this->event);
        foreach (['event_cancelled','event_reactivated'] as $kind) self::assertSame(2,Store::count('notifications','user_id=%d AND kind=%s',[$this->users[2],$kind]));
        wp_set_current_user($this->users[2]);self::assertSame('accepted',Events::detail($this->event)['registered']);
        wp_set_current_user($this->users[3]);self::assertSame('accepted',Events::register($this->event,'accepted')['registered']);
    }
    public function testMembersAndModeratorsCannotReactivateAndRestRejectsTheirRequest(): void
    {
        $this->cancel();
        foreach ([2,4] as $index) {
            wp_set_current_user($this->users[$index]);$this->denied(fn()=>EventReactivation::run($this->event),403);
            $request=new WP_REST_Request('POST','/ascla/v1/events/'.$this->event.'/reactivate');
            $request->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
            self::assertSame(403,rest_do_request($request)->get_status());
        }
        self::assertTrue(get_post_meta($this->event,'_ascla',true)['cancelled']);
    }
    public function testPastEventRequiresNewDatesAndKeepsCancellationOnFailure(): void
    {
        $this->cancel();$meta=get_post_meta($this->event,'_ascla',true);$meta['end']=gmdate('c',time()-60);update_post_meta($this->event,'_ascla',$meta);
        $this->denied(fn()=>EventReactivation::run($this->event),400);
        self::assertTrue(get_post_meta($this->event,'_ascla',true)['cancelled']);self::assertSame('',get_post_meta($this->event,'_ascla_event_reactivations',true));
    }
    public function testChangedScheduleDuringCancellationRequiresReconfirmation(): void
    {
        wp_set_current_user($this->users[2]);Events::register($this->event,'accepted');$this->cancel();
        $post=get_post($this->event);
        Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'publish','meta'=>['start'=>gmdate('c',time()+4*DAY_IN_SECONDS),'end'=>gmdate('c',time()+4*DAY_IN_SECONDS+3600)]],$this->event);
        EventReactivation::run($this->event);wp_set_current_user($this->users[2]);
        $detail=Events::detail($this->event);self::assertSame('reconfirm',$detail['registered']);self::assertArrayNotHasKey('url',$detail['meta']);
        self::assertSame('accepted',Events::register($this->event,'accepted')['registered']);
    }
    public function testOfferedSeatsReceiveFreshDeadlineAndWaitlistNeverOverbooks(): void
    {
        $meta=get_post_meta($this->event,'_ascla',true);$meta['capacity']=1;update_post_meta($this->event,'_ascla',$meta);
        wp_set_current_user($this->users[2]);Events::register($this->event,'accepted');
        wp_set_current_user($this->users[3]);Events::register($this->event,'waitlisted');
        wp_set_current_user($this->users[2]);Events::register($this->event,'cancelled');$this->cancel();
        $offers=get_post_meta($this->event,'_ascla_waitlist_offers',true);foreach($offers as &$deadline){$deadline=time()-60;}unset($deadline);update_post_meta($this->event,'_ascla_waitlist_offers',$offers);
        EventReactivation::run($this->event);
        wp_set_current_user($this->users[3]);$detail=Events::detail($this->event);self::assertSame('offered',$detail['registered']);self::assertGreaterThan(time(),strtotime($detail['offer_expires_at']));
        wp_set_current_user($this->users[2]);$this->denied(fn()=>Events::register($this->event,'accepted'),409);
        wp_set_current_user($this->users[3]);self::assertSame('accepted',Events::register($this->event,'accepted')['registered']);
    }
    public function testPublishedMicroeventRequiresManagementCapabilityAndRestoresLifecycle(): void
    {
        $meta=get_post_meta($this->event,'_ascla',true);$meta+=['micro'=>true,'micro_approved'=>true,'registration_deadline'=>gmdate('c',time()+DAY_IN_SECONDS)];update_post_meta($this->event,'_ascla',$meta);$this->cancel();
        $publisher=get_user_by('id',$this->users[4]);$publisher->add_cap('ascla_publish');
        wp_set_current_user($this->users[4]);$this->denied(fn()=>EventReactivation::run($this->event),403);
        wp_set_current_user($this->users[1]);$active=EventReactivation::run($this->event);
        self::assertTrue($active['meta']['micro_approved']);self::assertSame('published',get_post_meta($this->event,'_ascla_micro_state',true));self::assertNotFalse(wp_next_scheduled('ascla_micro_tick',[$this->event]));
    }
    public function testUnpublishedMicroeventReturnsToDraftWithoutBypassingReview(): void
    {
        $meta=get_post_meta($this->event,'_ascla',true);$meta+=['micro'=>true,'registration_deadline'=>gmdate('c',time()+DAY_IN_SECONDS)];update_post_meta($this->event,'_ascla',$meta);wp_update_post(['ID'=>$this->event,'post_status'=>'draft']);$this->cancel();
        $result=EventReactivation::run($this->event);self::assertSame('draft',$result['status']);self::assertFalse($result['meta']['micro_approved']);self::assertSame('pending',get_post_meta($this->event,'_ascla_micro_state',true));
    }
    public function testExpiredMicroeventRegistrationWindowCannotReopen(): void
    {
        $meta=get_post_meta($this->event,'_ascla',true);$meta+=['micro'=>true,'registration_deadline'=>gmdate('c',time()-60)];update_post_meta($this->event,'_ascla',$meta);$this->cancel();
        $this->denied(fn()=>EventReactivation::run($this->event),400);self::assertTrue(get_post_meta($this->event,'_ascla',true)['cancelled']);
    }
    public function testReactivationRestoresPersonalGoogleCopyWithNewIdAfterDeletion(): void
    {
        $user=$this->users[2];Secrets::set('google_calendar_'.$user,wp_json_encode(['access_token'=>'local-fixture','expires_at'=>time()+3600]));$ids=[];$methods=[];
        $this->http=static function($pre,$args,$url)use(&$ids,&$methods){
            if (!str_starts_with($url,'https://www.googleapis.com/calendar/')) return $pre;
            $methods[]=$args['method'];$body=json_decode($args['body']??'{}',true);
            if ($args['method']==='POST') $ids[]=$body['id'];
            return ['headers'=>[],'body'=>wp_json_encode(['id'=>$body['id']??'fixture']),'response'=>['code'=>200,'message'=>'fixture'],'cookies'=>[]];
        };add_filter('pre_http_request',$this->http,10,3);
        wp_set_current_user($user);Events::register($this->event,'accepted');$this->cancel();
        self::assertSame('',get_user_meta($user,'_ascla_google_event_'.$this->event,true));
        EventReactivation::run($this->event);
        self::assertSame(['POST','DELETE','POST'],$methods);self::assertNotSame($ids[0],$ids[1]);
        self::assertSame($ids[1],get_user_meta($user,'_ascla_google_event_'.$this->event,true));self::assertSame($this->users[0],get_current_user_id());
    }
    public function testOrganizerIsRestoredBeforeConnectedMembersWithoutDuplicatePersonalCopies(): void
    {
        wp_set_current_user($this->users[2]);Events::register($this->event,'accepted');
        foreach ([0,2] as $index) Secrets::set('google_calendar_'.$this->users[$index],wp_json_encode(['access_token'=>'fixture-'.$index,'expires_at'=>time()+3600]));
        update_post_meta($this->event,'_ascla_google_organizer_user',$this->users[0]);
        update_post_meta($this->event,'_ascla_google_organizer_event','old-organizer');
        update_user_meta($this->users[2],'_ascla_google_event_'.$this->event,'old-personal');
        $creates=[];$guests=[];
        $this->http=static function($pre,$args,$url)use(&$creates,&$guests){
            if (!str_starts_with($url,'https://www.googleapis.com/calendar/')) return $pre;
            $body=json_decode($args['body']??'{}',true);
            if ($args['method']==='POST') { $creates[]=$args['headers']['Authorization'];self::assertStringContainsString('sendUpdates=all',$url); }
            if (isset($body['attendees'])) $guests=array_column($body['attendees'],'email');
            return ['headers'=>[],'body'=>wp_json_encode(['id'=>$body['id']??'fixture','attendees'=>[]]),'response'=>['code'=>200,'message'=>'fixture'],'cookies'=>[]];
        };add_filter('pre_http_request',$this->http,10,3);
        $this->cancel();EventReactivation::run($this->event);
        self::assertSame(['Bearer fixture-0'],$creates);
        self::assertSame('',get_user_meta($this->users[2],'_ascla_google_event_'.$this->event,true));
        self::assertSame('invited',get_user_meta($this->users[2],'_ascla_calendar_status_'.$this->event,true));
        self::assertContains(get_userdata($this->users[2])->user_email,$guests);
    }
}
