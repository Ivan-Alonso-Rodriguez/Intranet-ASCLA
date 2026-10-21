<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{MicroEvents,MicroPlanning,MicroLifecycle,MicroEligibility,ContentSchedule,EventReminders,Content,Events,Settings};
use ASCLA\Core\Repositories\Store;

final class MicroEventsTest extends TestCase
{
    private array $users=[],$events=[],$settings=[],$history=[];
    private string $key;
    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->history=get_option('ascla_group_history',[]);
        foreach(['administrator','ascla_executive','ascla_member','ascla_member','ascla_member','ascla_member','ascla_member'] as $role){
            $name='micro_'.bin2hex(random_bytes(5));
            $id=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);$this->users[]=$id;
            update_user_meta($id,'_ascla_profile',ascla_test_profile(['directory'=>false,'networking'=>false,'microevents'=>true]));
        }
        wp_set_current_user($this->users[0]);Settings::save(['ai_provider'=>'mock','micro_approval'=>false,'micro_min'=>4,'micro_capacity'=>6,'micro_limit'=>4,'micro_priority_hours'=>48]);
        $ids=$this->users;sort($ids);$this->key='ascla_micro_demo-'.substr(hash('sha256',wp_json_encode($ids)),0,12).'-'.MicroPlanning::period();
    }
    protected function tearDown(): void
    {
        foreach($this->events as $id){
            wp_clear_scheduled_hook('ascla_micro_tick',[$id]);wp_clear_scheduled_hook('ascla_event_offers',[$id]);
            foreach([1,24,48] as $h)wp_clear_scheduled_hook('ascla_event_reminder',[$id,$h]);
            Store::delete('registrations',['event_id'=>$id]);wp_delete_post($id,true);
        }
        foreach($this->users as $id){Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);Store::delete('relations',['user_id'=>$id]);wp_delete_user($id);}
        delete_option($this->key);update_option('ascla_settings',$this->settings,false);update_option('ascla_group_history',$this->history,false);wp_set_current_user(0);
    }
    private function proposal(): int
    {
        $result=MicroEvents::create($this->users);$this->events=array_unique(array_merge($this->events,$result['events']));
        self::assertNotEmpty($result['events']);return $result['events'][0];
    }
    private function reject(callable $fn,int $code): void
    {
        try{$fn();self::fail('Expected rejection');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame($code,$e->getCode(),$e->getMessage());}
    }
    private function publish(int $id): array
    {
        $post=get_post($id);
        return Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'publish'],$id);
    }
    public function testWeeklyBatchIsIdempotentWithIndependentConsentAndMandatoryReview(): void
    {
        wp_set_current_user($this->users[1]);$id=$this->proposal();$result=MicroEvents::create($this->users);
        self::assertSame([$id],$result['events']);self::assertSame('pending',get_post_status($id));
        self::assertTrue(Settings::get()['micro_approval']);self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
        wp_update_post(['ID'=>$id,'post_status'=>'publish']);self::assertSame('pending',get_post_status($id));
        $this->reject(fn()=>Content::moderate($id,'approve','Aprobar'),403);
        wp_set_current_user($this->users[0]);$approved=Content::moderate($id,'approve','Agenda revisada');
        self::assertSame('ascla_hidden',$approved['status']);self::assertFalse($approved['meta']['invited']);
        self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(['pending','approved'],array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
        $approved=$this->publish($id);
        self::assertSame('publish',$approved['status']);self::assertTrue($approved['meta']['invited']);self::assertSame(count($approved['meta']['invitees']),Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(['pending','approved','published'],array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
        MicroEvents::invite($id);self::assertSame(count($approved['meta']['invitees']),Store::count('registrations','event_id=%d',[$id]));
    }
    public function testPriorityWindowOpensToMembersWhoOptedOutAndCannotBeBypassedByAuthor(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$this->publish($id);$meta=get_post_meta($id,'_ascla',true);
        $outsider=array_values(array_diff($this->users,$meta['invitees']))[0];
        update_user_meta($outsider,'_ascla_profile',ascla_test_profile(['microevents'=>false,'directory'=>false]));
        wp_set_current_user($outsider);$this->reject(fn()=>Events::register($id,'accepted'),user_can($outsider,'ascla_manage')?403:404);
        $meta['public_at']=time()-1;update_post_meta($id,'_ascla',$meta);
        self::assertTrue(Content::canRead(get_post($id)));self::assertContains($id,array_column(Content::listing('event')['items'],'id'));
        self::assertSame('accepted',Events::register($id,'accepted')['registered']);
        self::assertSame('cancelled',Events::register($id,'cancelled')['registered']);
    }
    public function testInsufficientRegistrationsReopenAfterAdminExtendsDeadlineAndHistoryIsRetained(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$this->publish($id);$meta=get_post_meta($id,'_ascla',true);$meta['registration_deadline']=gmdate('c',time()-1);
        update_post_meta($id,'_ascla',$meta);MicroLifecycle::tick($id);self::assertSame('insufficient',get_post_meta($id,'_ascla_micro_state',true));
        wp_set_current_user($meta['invitees'][0]);$this->reject(fn()=>Events::register($id,'accepted'),409);
        wp_set_current_user($this->users[0]);$post=get_post($id);
        Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'publish','meta'=>['registration_deadline'=>gmdate('c',time()+DAY_IN_SECONDS),'micro_min'=>2]],$id);
        self::assertSame('published',get_post_meta($id,'_ascla_micro_state',true));
        self::assertContains('insufficient',array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
        $this->reject(fn()=>Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'publish','meta'=>['reminder_hours'=>[48]]],$id),409);
        Events::cancel($id,'No hay disponibilidad');
        self::assertSame('cancelled',get_post_meta($id,'_ascla_micro_state',true));self::assertSame('No hay disponibilidad',get_post_meta($id,'_ascla',true)['cancellation_reason']);
        self::assertFalse(Content::canDelete(get_post($id)));
    }
    public function testDenialNeedsReasonAndCannotDeleteOrRegenerateTheSameProposal(): void
    {
        $id=$this->proposal();$this->reject(fn()=>Content::moderate($id,'reject',''),400);
        Content::moderate($id,'reject','Tema ya cubierto');$this->reject(fn()=>Content::remove($id),403);
        self::assertSame('ascla_rejected',get_post_status($id));self::assertSame([$id],MicroEvents::create($this->users)['events']);
        self::assertSame('Tema ya cubierto',get_post_meta($id,'_ascla_micro_history',true)[1]['reason']);
    }
    public function testPlanningRespectsVolumeDistinctInterestsMinimumAndBlockedPairs(): void
    {
        $profiles=[];for($topic=1;$topic<=6;$topic++)for($i=1;$i<=6;$i++)$profiles[]=['id'=>$topic*100+$i,'interests'=>[$topic]];
        $plan=MicroPlanning::plan($profiles,[],['101:102'=>true]);
        self::assertCount(4,$plan['groups']);self::assertCount(4,array_unique(array_column($plan['groups'],'topic')));
        foreach($plan['groups'] as $group){self::assertGreaterThanOrEqual(4,count($group['members']));self::assertLessThanOrEqual(6,count($group['members']));self::assertFalse(in_array(101,$group['members'],true)&&in_array(102,$group['members'],true));}
        self::assertSame([],MicroPlanning::plan(array_slice($profiles,0,3),[],[])['groups']);
        $this->reject(fn()=>Settings::save(['micro_min'=>7,'micro_capacity'=>6]),400);
        $this->reject(fn()=>Settings::save(['micro_limit'=>5]),400);
    }
    public function testOnlyConfirmedMembersReceiveOneReminderAndFinishedStateIsRecorded(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$this->publish($id);$meta=get_post_meta($id,'_ascla',true);
        wp_set_current_user($meta['invitees'][0]);Events::register($id,'accepted');
        $meta['start']=gmdate('c',time()+3500);$meta['end']=gmdate('c',time()+7100);update_post_meta($id,'_ascla',$meta);
        EventReminders::send($id,1);EventReminders::send($id,1);
        self::assertSame(1,Store::count('notifications','user_id=%d AND kind=%s',[$meta['invitees'][0],'event_reminder']));
        foreach(array_slice($meta['invitees'],1) as $uid)self::assertSame(0,Store::count('notifications','user_id=%d AND kind=%s',[$uid,'event_reminder']));
        $meta['end']=gmdate('c',time()-1);update_post_meta($id,'_ascla',$meta);MicroLifecycle::tick($id);
        self::assertSame('finished',get_post_meta($id,'_ascla_micro_state',true));
    }

    public function testPendingProposalCanBeCancelledWithReasonWithoutLosingItsHistoryOrRecurringBatch(): void
    {
        $id=$this->proposal();$this->reject(fn()=>Events::cancel($id,''),400);
        $detail=Events::cancel($id,'No hay disponibilidad para esta propuesta.');
        self::assertTrue($detail['cancelled']);self::assertSame('ascla_hidden',get_post_status($id));
        self::assertFalse($detail['can_edit']);self::assertFalse($detail['can_moderate']);self::assertFalse($detail['can_delete']);
        self::assertSame(['pending','cancelled'],array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
        self::assertSame([$id],MicroEvents::create($this->users)['events']);
        $this->reject(fn()=>Events::cancel($id,'Otra vez'),409);$this->reject(fn()=>Content::remove($id),403);
        self::assertSame(2,count(get_post_meta($id,'_ascla_micro_history',true)));
    }

    public function testCancellingAnApprovedScheduledProposalStopsFuturePublication(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Convocatoria revisada');$post=get_post($id);
        $at=time()+3600;
        Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'scheduled','publish_at'=>gmdate('c',$at)],$id);
        self::assertNotFalse(wp_next_scheduled('ascla_publish_content',[$id,$at]));
        Events::cancel($id,'Se suspende la convocatoria.');$meta=get_post_meta($id,'_ascla',true);
        self::assertFalse($meta['micro_approved']);self::assertArrayNotHasKey('publish_at',$meta);
        self::assertFalse(wp_next_scheduled('ascla_publish_content',[$id,$at]));
        self::assertFalse(wp_next_scheduled('ascla_micro_tick',[$id]));
        ContentSchedule::publish($id,$at);
        self::assertSame('ascla_hidden',get_post_status($id));self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(['pending','approved','cancelled'],array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
    }

    public function testReviewRefreshReopensAnApprovedGroupAfterRemovingAnIncompleteCandidate(): void
    {
        $id=$this->proposal();$meta=get_post_meta($id,'_ascla',true);$meta['micro_min']=2;update_post_meta($id,'_ascla',$meta);
        Content::moderate($id,'approve','Grupo inicialmente revisado');self::assertSame('approved',get_post_meta($id,'_ascla_micro_state',true));
        $removed=$meta['invitees'][0];$profile=get_user_meta($removed,'_ascla_profile',true);$profile['bio']='';update_user_meta($removed,'_ascla_profile',$profile);
        $review=MicroEligibility::review($id);
        self::assertSame(1,$review['removed']);self::assertSame(count($meta['invitees'])-1,$review['candidates']);
        self::assertNotContains($removed,$review['item']['meta']['invitees']);self::assertFalse($review['item']['meta']['micro_approved']);
        self::assertSame('pending',$review['item']['status']);
        Content::moderate($id,'approve','Grupo actualizado y revisado');$saved=$this->publish($id);
        self::assertSame('publish',$saved['status']);self::assertSame($review['candidates'],Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(0,Store::count('registrations','event_id=%d AND user_id=%d',[$id,$removed]));
    }

    public function testReviewCancelsAnInsufficientGroupImmediatelyAndIsIdempotent(): void
    {
        $id=$this->proposal();$meta=get_post_meta($id,'_ascla',true);
        foreach (array_slice($meta['invitees'],1) as $uid) {
            $profile=get_user_meta($uid,'_ascla_profile',true);$profile['microevents']=false;update_user_meta($uid,'_ascla_profile',$profile);
        }
        $review=MicroEligibility::review($id);self::assertTrue($review['item']['meta']['cancelled']);self::assertSame(1,$review['candidates']);
        self::assertSame('cancelled',$review['item']['meta']['micro_state']);self::assertFalse($review['item']['can_moderate']);
        $history=get_post_meta($id,'_ascla_micro_history',true);self::assertCount(2,$history);self::assertStringContainsString('mínimo',$history[1]['reason']);
        MicroEligibility::review($id);self::assertSame($history,get_post_meta($id,'_ascla_micro_history',true));
        self::assertSame([$id],MicroEvents::create($this->users)['events']);self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
    }

    public function testConsentChangesAfterReviewCannotBeApprovedAndOnlyAdministrationMayReconcileOrCancel(): void
    {
        wp_set_current_user($this->users[1]);$id=$this->proposal();
        $this->reject(fn()=>MicroEligibility::review($id),403);$this->reject(fn()=>Events::cancel($id,'No corresponde'),403);
        wp_set_current_user($this->users[0]);MicroEligibility::review($id);$meta=get_post_meta($id,'_ascla',true);
        $uid=$meta['invitees'][0];$profile=get_user_meta($uid,'_ascla_profile',true);$profile['microevents']=false;update_user_meta($uid,'_ascla_profile',$profile);
        try { Content::moderate($id,'approve','Revisado antes del cambio');self::fail('Consent withdrawal must reject approval.'); }
        catch (ASCLA\Core\Rest\ApiException $error) { self::assertSame(400,$error->getCode());self::assertStringContainsString('consentimiento',$error->getMessage()); }
        self::assertSame('pending',get_post_status($id));self::assertCount(1,get_post_meta($id,'_ascla_micro_history',true));
        $this->reject(fn()=>Content::moderate($id,'hide','Ocultar no equivale a cancelar'),400);
        self::assertEmpty(get_post_meta($id,'_ascla',true)['cancelled']??false);
    }

    public function testPublishedCancellationPreservesRegistrationsNotifiesOnceAndStopsReminders(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$this->publish($id);$meta=get_post_meta($id,'_ascla',true);
        wp_set_current_user($meta['invitees'][0]);Events::register($id,'accepted');wp_set_current_user($this->users[0]);
        $before=Store::rows('registrations','event_id=%d',[$id],'ORDER BY id');Events::cancel($id,'Cancelación comprobada');
        self::assertSame($before,Store::rows('registrations','event_id=%d',[$id],'ORDER BY id'));
        foreach ($meta['invitees'] as $uid) { self::assertSame(1,Store::count('notifications',"user_id=%d AND kind='event_cancelled'",[$uid])); }
        foreach ([24,1] as $hour) { self::assertFalse(wp_next_scheduled('ascla_event_reminder',[$id,$hour])); }
        self::assertFalse(wp_next_scheduled('ascla_micro_tick',[$id]));self::assertFalse(Content::canModerate(get_post($id)));
        $this->reject(fn()=>Events::cancel($id,'Reintento'),409);
    }

    public function testFailedCancellationRollsBackTheProposalHistoryAndPublicationSchedule(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$post=get_post($id);$at=time()+3600;
        Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'scheduled','publish_at'=>gmdate('c',$at)],$id);
        $meta=get_post_meta($id,'_ascla',true);$history=get_post_meta($id,'_ascla_micro_history',true);
        $fail=static fn($result,$object,$key,$value)=>$object===$id && $key==='_ascla' && !empty($value['cancelled'])?false:$result;
        add_filter('update_post_metadata',$fail,10,4);
        try { Events::cancel($id,'Escritura rechazada');self::fail('Cancellation write must fail.'); }
        catch (ASCLA\Core\Repositories\RepositoryException $error) { self::assertNotSame('',$error->getMessage()); }
        finally { remove_filter('update_post_metadata',$fail,10); }
        self::assertSame($meta,get_post_meta($id,'_ascla',true));self::assertSame($history,get_post_meta($id,'_ascla_micro_history',true));
        self::assertNotFalse(wp_next_scheduled('ascla_publish_content',[$id,$at]));
    }

    public function testAdministrationCanInspectSelectedPeopleBeforeInvitationsExistWithoutChangingTheProposal(): void
    {
        $id=$this->proposal();$meta=get_post_meta($id,'_ascla',true);$history=get_post_meta($id,'_ascla_micro_history',true);
        $person=$meta['invitees'][0];$profile=get_user_meta($person,'_ascla_profile',true);
        $profile['hidden']=['first_name','last_name'];update_user_meta($person,'_ascla_profile',$profile);
        $detail=Events::detail($id);$roster=$detail['micro_invitations'];
        self::assertFalse($roster['created']);self::assertSame($meta['invitees'],array_column($roster['items'],'id'));
        self::assertSame(array_fill(0,count($meta['invitees']),'selected'),array_column($roster['items'],'status'));
        self::assertSame(get_userdata($person)->display_name,$roster['items'][0]['name']);
        self::assertSame($meta,get_post_meta($id,'_ascla',true));self::assertSame($history,get_post_meta($id,'_ascla_micro_history',true));
        self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
        self::assertArrayNotHasKey('micro_invitations',Content::serialize(get_post($id)));
        self::assertSame($roster,MicroEligibility::review($id)['item']['micro_invitations']);
    }

    public function testSelectedRosterTracksRealRsvpSeparatelyFromPeopleJoiningThePublicEvent(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$this->publish($id);$meta=get_post_meta($id,'_ascla',true);
        wp_set_current_user($meta['invitees'][0]);Events::register($id,'accepted');
        wp_set_current_user($meta['invitees'][1]);Events::register($id,'declined');wp_set_current_user($this->users[0]);
        $outsider=array_values(array_diff($this->users,$meta['invitees']))[0];
        Store::insert('registrations',['event_id'=>$id,'user_id'=>$outsider,'status'=>'accepted','created_at'=>current_time('mysql',true)]);
        $roster=Events::detail($id)['micro_invitations'];$statuses=array_column($roster['items'],'status','id');
        self::assertTrue($roster['created']);self::assertCount(count($meta['invitees']),$roster['items']);
        self::assertArrayNotHasKey($outsider,$statuses);self::assertSame('accepted',$statuses[$meta['invitees'][0]]);
        self::assertSame('declined',$statuses[$meta['invitees'][1]]);self::assertSame('invited',$statuses[$meta['invitees'][2]]);
        $meta['public_at']=time()-1;update_post_meta($id,'_ascla',$meta);
        foreach (array_slice($this->users,1) as $uid) {
            wp_set_current_user($uid);self::assertArrayNotHasKey('micro_invitations',Events::detail($id));
        }
        wp_set_current_user($this->users[0]);
        $deleted=$meta['invitees'][2];wp_delete_user($deleted);
        $items=array_column(Events::detail($id)['micro_invitations']['items'],null,'id');
        self::assertFalse($items[$deleted]['available']);self::assertSame('',$items[$deleted]['name']);
        self::assertSame('invited',$items[$deleted]['status']);
    }

    public function testCancelledAndEmptySelectionsDoNotClaimInvitationsWereSent(): void
    {
        $id=$this->proposal();$detail=Events::cancel($id,'Propuesta cancelada antes de publicar.');
        self::assertFalse($detail['micro_invitations']['created']);
        self::assertSame(['not_invited'],array_unique(array_column($detail['micro_invitations']['items'],'status')));
        $meta=get_post_meta($id,'_ascla',true);$meta['invitees']=[];update_post_meta($id,'_ascla',$meta);
        self::assertSame([],Events::detail($id)['micro_invitations']['items']);
        $meta['micro']=false;update_post_meta($id,'_ascla',$meta);
        self::assertArrayNotHasKey('micro_invitations',Events::detail($id));
    }
}
