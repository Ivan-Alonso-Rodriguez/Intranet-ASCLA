<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Events,EventParticipation,Messaging};
use ASCLA\Core\Repositories\Store;

final class EventParticipationTest extends TestCase
{
    private array $users=[],$posts=[];
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member','ascla_member','ascla_member'] as $role) {
            $this->users[]=wp_insert_user(['user_login'=>'rsvp_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) { wp_clear_scheduled_hook('ascla_event_offers',[$id]);Store::delete('registrations',['event_id'=>$id]);wp_delete_post($id,true); }
        foreach ($this->users as $id) { Store::delete('notifications',['user_id'=>$id]);Store::delete('relations',['user_id'=>$id]);wp_delete_user($id); }
        wp_set_current_user(0);
    }
    private function event(int $capacity=4): array
    {
        $post=Content::save('event',['title'=>'Revisión de RSVP','body'=>'Encuentro profesional','status'=>'publish','meta'=>['start'=>gmdate('c',time()+DAY_IN_SECONDS),'end'=>gmdate('c',time()+2*DAY_IN_SECONDS),'modality'=>'Virtual','capacity'=>$capacity,'offer_hours'=>2]]);
        $this->posts[]=$post['id'];return $post;
    }
    private function edit(array $post,array $meta): array
    {
        return Content::save('event',['title'=>$post['title'],'body'=>$post['body'],'status'=>'publish','meta'=>$meta],$post['id']);
    }
    private function denied(callable $fn,int $status): void
    {
        try { $fn();self::fail('Expected rejection'); }
        catch (ASCLA\Core\Rest\ApiException $e) { self::assertSame($status,$e->getCode()); }
    }
    public function testScheduleChangeRequiresNewRsvpAndCancellationPreservesReasonAndHistory(): void
    {
        $post=$this->event();$id=$post['id'];wp_set_current_user($this->users[1]);Events::register($id,'accepted');
        wp_set_current_user($this->users[0]);$this->edit($post,['agenda'=>'Nueva descripción']);
        wp_set_current_user($this->users[1]);self::assertSame('accepted',Events::detail($id)['registered']);
        wp_set_current_user($this->users[0]);$this->edit($post,['modality'=>'Presencial','change_reason'=>'Cambio de sede']);
        wp_set_current_user($this->users[1]);$detail=Events::detail($id);
        self::assertSame('reconfirm',$detail['registered']);self::assertSame(0,$detail['attending']);self::assertArrayNotHasKey('attendees',$detail);
        self::assertSame('accepted',get_post_meta($id,'_ascla_rsvp_history',true)[0]['registrations'][0]['status']);
        self::assertSame('accepted',Events::register($id,'accepted')['registered']);
        wp_set_current_user($this->users[0]);$this->denied(fn()=>Events::cancel($id),400);
        self::assertEmpty(get_post_meta($id,'_ascla',true)['cancelled']??false);
        $cancelled=Events::cancel($id,'Problema con la sede');
        self::assertSame('Problema con la sede',$cancelled['meta']['cancellation_reason']);
        self::assertSame(1,Store::count('registrations','event_id=%d',[$id]));
        wp_set_current_user($this->users[1]);$this->denied(fn()=>Events::register($id,'accepted'),409);
    }
    public function testExpiredOfferAdvancesFifoAndCannotTakeTheNextMembersReservedSeat(): void
    {
        $post=$this->event(1);$id=$post['id'];
        wp_set_current_user($this->users[1]);Events::register($id,'accepted');
        foreach ([2,3] as $n) { wp_set_current_user($this->users[$n]);Events::register($id,'waitlisted'); }
        wp_set_current_user($this->users[1]);Events::register($id,'cancelled');
        wp_set_current_user($this->users[2]);$detail=Events::detail($id);self::assertSame('offered',$detail['registered']);
        self::assertEqualsWithDelta(time()+7200,strtotime($detail['offer_expires_at']),3);
        $offers=get_post_meta($id,'_ascla_waitlist_offers',true);foreach($offers as &$deadline){$deadline=time()-1;}unset($deadline);update_post_meta($id,'_ascla_waitlist_offers',$offers);
        self::assertSame('expired',Events::detail($id)['registered']);
        $this->denied(fn()=>Events::register($id,'accepted'),409);
        wp_set_current_user($this->users[3]);self::assertSame('offered',Events::detail($id)['registered']);
        self::assertSame('accepted',Events::register($id,'accepted')['registered']);
        self::assertSame(1,Events::detail($id)['reserved']);
    }
    public function testMicroeventReschedulingNeedsReasonBeforeChangingStoredData(): void
    {
        $post=$this->event();$id=$post['id'];$old=get_post_meta($id,'_ascla',true);$old['micro']=true;$old['invitees']=$this->users;$old['invited']=true;
        update_post_meta($id,'_ascla',$old);
        $this->denied(fn()=>$this->edit($post,['modality'=>'Presencial']),400);
        self::assertSame('Virtual',get_post_meta($id,'_ascla',true)['modality']);
        $this->edit($post,['modality'=>'Presencial','change_reason'=>'Preferencia del grupo']);
        self::assertSame('Preferencia del grupo',get_post_meta($id,'_ascla_rsvp_history',true)[0]['reason']);
    }
}
