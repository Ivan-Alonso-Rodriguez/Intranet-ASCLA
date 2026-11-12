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
        foreach($this->users as $id){Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);Store::delete('relations',['user_id'=>$id]);Store::delete('jobs',['user_id'=>$id]);wp_delete_user($id);}
        delete_option($this->key);update_option('ascla_settings',$this->settings,false);update_option('ascla_group_history',$this->history,false);wp_set_current_user(0);
    }
    private function proposal(): int
    {
        $result=MicroEvents::create($this->users);$this->events=array_unique(array_merge($this->events,$result['events']));
        self::assertNotEmpty($result['events']);$id=$result['events'][0];
        $meta=get_post_meta($id,'_ascla',true);
        $meta['url']='https://example.invalid/meeting';
        update_post_meta($id,'_ascla',$meta);
        return $id;
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
    private function approveLater(int $id,string $reason): array
    {
        $post=get_post($id);
        Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'scheduled','publish_at'=>gmdate('c',time()+3600)],$id);
        return Content::moderate($id,'approve',$reason);
    }
    private function replacementMember(): int
    {
        $name='micro_replacement_'.bin2hex(random_bytes(5));
        $id=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        $this->users[]=$id;update_user_meta($id,'_ascla_profile',ascla_test_profile(['microevents'=>true]));return $id;
    }
    public function testWeeklyBatchIsIdempotentWithIndependentConsentAndMandatoryReview(): void
    {
        wp_set_current_user($this->users[1]);$id=$this->proposal();$result=MicroEvents::create($this->users);
        self::assertSame([$id],$result['events']);self::assertSame('pending',get_post_status($id));
        self::assertTrue(Settings::get()['micro_approval']);self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
        wp_update_post(['ID'=>$id,'post_status'=>'publish']);self::assertSame('pending',get_post_status($id));
        wp_set_current_user($this->users[2]);$this->reject(fn()=>Content::moderate($id,'approve','Aprobar'),404);
        wp_set_current_user($this->users[0]);$approved=Content::moderate($id,'approve','Agenda revisada');
        self::assertSame('publish',$approved['status']);self::assertTrue($approved['meta']['invited']);
        self::assertSame(count($approved['meta']['invitees']),Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(['pending','approved','published'],array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
        $approved=$this->publish($id);
        self::assertSame('publish',$approved['status']);self::assertTrue($approved['meta']['invited']);self::assertSame(count($approved['meta']['invitees']),Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(['pending','approved','published'],array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
        MicroEvents::invite($id);self::assertSame(count($approved['meta']['invitees']),Store::count('registrations','event_id=%d',[$id]));
    }
    public function testCandidateReplacementIsAuditedAndApprovalPublishesOnlyTheNewGroup(): void
    {
        $id=$this->proposal();$before=get_post_meta($id,'_ascla',true)['invitees'];$new=$this->replacementMember();
        $this->approveLater($id,'Selección inicialmente aprobada y programada');
        $ids=$before;$removed=array_shift($ids);$ids[]=$new;
        $result=MicroEligibility::replace($id,$ids,'Reemplazo por disponibilidad');
        self::assertSame($ids,$result['item']['meta']['invitees']);self::assertSame('pending',$result['item']['status']);
        self::assertFalse($result['item']['meta']['micro_approved']);self::assertArrayNotHasKey('publish_at',$result['item']['meta']);self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(1,Store::count('audit',"object_id=%d AND action='microevent_candidates_updated'",[$id]));
        $published=Content::moderate($id,'approve','Grupo verificado');self::assertSame('publish',$published['status']);
        self::assertTrue($published['meta']['invited']);self::assertSame(count($ids),Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(0,Store::count('registrations','event_id=%d AND user_id=%d',[$id,$removed]));
        $this->reject(fn()=>MicroEligibility::replace($id,$before,'Cambio tardío'),409);
    }
    public function testInvalidCandidatesCannotChangeTheGroupAndBlocksAreRecheckedAtSave(): void
    {
        $id=$this->proposal();$before=get_post_meta($id,'_ascla',true);$new=$this->replacementMember();
        $ids=$before['invitees'];array_shift($ids);$ids[]=$new;
        $this->reject(fn()=>MicroEligibility::replace($id,array_slice($ids,0,1),'Demasiado pequeño'),400);
        $this->reject(fn()=>MicroEligibility::replace($id,[-1],'ID no válido'),400);
        $this->reject(fn()=>MicroEligibility::replace($id,$ids,''),400);
        $profile=get_user_meta($new,'_ascla_profile',true);$profile['microevents']=false;update_user_meta($new,'_ascla_profile',$profile);
        $this->reject(fn()=>MicroEligibility::replace($id,$ids,'Sin consentimiento'),400);
        $profile['microevents']=true;update_user_meta($new,'_ascla_profile',$profile);
        Store::insert('relations',['user_id'=>$new,'target_id'=>$ids[0],'kind'=>'block','created_at'=>current_time('mysql',true)]);
        $this->reject(fn()=>MicroEligibility::replace($id,$ids,'Bloqueo vigente'),400);
        self::assertSame($before,get_post_meta($id,'_ascla',true));self::assertSame('pending',get_post_status($id));
    }
    public function testCandidateSearchIsPrivatePaginatedAndFiltersConsent(): void
    {
        $id=$this->proposal();$new=$this->replacementMember();$name=get_userdata($new)->display_name;
        $options=MicroEligibility::options($id,$name,1000);self::assertSame(1,$options['page']);self::assertSame([$new],array_column($options['items'],'id'));
        $profile=get_user_meta($new,'_ascla_profile',true);$profile['microevents']=false;update_user_meta($new,'_ascla_profile',$profile);
        self::assertSame([],MicroEligibility::options($id,$name)['items']);
        wp_set_current_user($this->users[2]);$this->reject(fn()=>MicroEligibility::options($id),403);
        $this->reject(fn()=>MicroEligibility::replace($id,[],'Sin permiso'),403);
    }
    public function testCandidateReplacementRollsBackWhenTheDatabaseRefusesIt(): void
    {
        $id=$this->proposal();$before=get_post_meta($id,'_ascla',true);$ids=$before['invitees'];array_shift($ids);$ids[]=$this->replacementMember();
        $fail=static fn($result,$object,$key)=>$object===$id && $key==='_ascla'?false:$result;
        add_filter('update_post_metadata',$fail,10,3);
        try { MicroEligibility::replace($id,$ids,'Prueba de fallo');self::fail('Write must fail'); }
        catch(ASCLA\Core\Repositories\RepositoryException $error) { self::assertNotSame('',$error->getMessage()); }
        finally { remove_filter('update_post_metadata',$fail,10); }
        self::assertSame($before,get_post_meta($id,'_ascla',true));self::assertSame('pending',get_post_status($id));
        self::assertSame(0,Store::count('audit',"object_id=%d AND action='microevent_candidates_updated'",[$id]));
    }
    public function testPriorityWindowOpensToMembersWhoOptedOutAndCannotBeBypassedByAuthor(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$this->publish($id);$meta=get_post_meta($id,'_ascla',true);
        $outsider=array_values(array_diff($this->users,$meta['invitees']))[0];
        update_user_meta($outsider,'_ascla_profile',ascla_test_profile(['microevents'=>false,'directory'=>false]));
        wp_set_current_user($outsider);$this->reject(fn()=>Events::register($id,'accepted'),user_can($outsider,'ascla_manage')?403:404);
        $meta['public_at']=time()-1;update_post_meta($id,'_ascla',$meta);
        self::assertTrue(Content::canRead(get_post($id)));self::assertContains($id,array_column(Content::listing('event',['author'=>(int)get_post($id)->post_author])['items'],'id'));
        self::assertSame('accepted',Events::register($id,'accepted')['registered']);
        self::assertSame('cancelled',Events::register($id,'cancelled')['registered']);
    }
    public function testInsufficientRegistrationsReopenAfterAdminExtendsDeadlineAndHistoryIsRetained(): void
    {
        $id=$this->proposal();Content::moderate($id,'approve','Revisado');$this->publish($id);$meta=get_post_meta($id,'_ascla',true);$meta['registration_deadline']=gmdate('c',time()-1);
        update_post_meta($id,'_ascla',$meta);MicroLifecycle::tick($id);self::assertSame('insufficient',get_post_meta($id,'_ascla_micro_state',true));
        wp_set_current_user($meta['invitees'][0]);$this->reject(fn()=>Events::register($id,'accepted'),409);
        wp_set_current_user($this->users[0]);$post=get_post($id);
        $changes=['registration_deadline'=>gmdate('c',time()+DAY_IN_SECONDS),'micro_min'=>2];
        $this->reject(fn()=>Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'publish','meta'=>$changes],$id),400);
        $changes['change_reason']='Ampliamos la convocatoria para completar el grupo.';
        Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'publish','meta'=>$changes],$id);
        self::assertSame('published',get_post_meta($id,'_ascla_micro_state',true));
        self::assertContains('insufficient',array_column(get_post_meta($id,'_ascla_micro_history',true),'state'));
        $history=get_post_meta($id,'_ascla_micro_history',true);
        self::assertSame($changes['change_reason'],end($history)['reason']);
        $this->reject(fn()=>Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'publish','meta'=>['reminder_hours'=>[48]]],$id),409);
        Events::cancel($id,'No hay disponibilidad');
        self::assertSame('cancelled',get_post_meta($id,'_ascla_micro_state',true));self::assertSame('No hay disponibilidad',get_post_meta($id,'_ascla',true)['cancellation_reason']);
        self::assertFalse(Content::canDelete(get_post($id)));
    }
    public function testDenialAllowsOptionalReasonAndCannotDeleteOrRegenerateTheSameProposal(): void
    {
        $id=$this->proposal();Content::moderate($id,'reject','');$this->reject(fn()=>Content::remove($id),403);
        self::assertSame('ascla_rejected',get_post_status($id));self::assertSame([$id],MicroEvents::create($this->users)['events']);
        self::assertTrue((bool)get_post_meta($id,'_ascla_micro_archived',true));
        self::assertSame('',get_post_meta($id,'_ascla_micro_history',true)[1]['reason']);
    }
    public function testProposalEditsRemainPendingAndBiographyIsOptionalForEligibility(): void
    {
        foreach($this->users as $uid){
            $profile=get_user_meta($uid,'_ascla_profile',true);$profile['bio']='';update_user_meta($uid,'_ascla_profile',$profile);
        }
        $id=$this->proposal();$post=get_post($id);$meta=get_post_meta($id,'_ascla',true);
        $meta['url']='https://example.invalid/meeting';
        $result=Content::save('event',['title'=>$post->post_title,'body'=>$post->post_content,'status'=>'draft','meta'=>$meta],$id);
        self::assertSame('pending',$result['status']);
        self::assertSame('pending',get_post_status($id));
    }

    public function testReviewCanCompleteTheMeetingLinkBeforePublishing(): void
    {
        $id=$this->proposal();$meta=get_post_meta($id,'_ascla',true);unset($meta['url']);update_post_meta($id,'_ascla',$meta);
        $this->reject(fn()=>Content::moderate($id,'approve',''),400);
        $this->reject(fn()=>Content::moderate($id,'approve','',false,['url'=>'invalid']),400);
        self::assertSame('pending',get_post_status($id));
        $approved=Content::moderate($id,'approve','',false,['modality'=>'Virtual','url'=>'https://example.invalid/review-meeting']);
        self::assertSame('publish',$approved['status']);
        self::assertSame('https://example.invalid/review-meeting',$approved['meta']['url']);
        self::assertTrue($approved['meta']['invited']);
    }
    public function testEditingRefreshesProposedMessagesAndPreservesTheManualProgram(): void
    {
        $id=$this->proposal();$post=get_post($id);
        Content::save('event',['title'=>'Laboratorio Boreal','body'=>'Evaluar decisiones de sostenibilidad.','status'=>'pending','meta'=>['agenda'=>'Análisis de un caso y acuerdos del grupo.']],$id);
        $state=ASCLA\Core\Services\MicroProposalText::status($id);
        self::assertSame('pending',$state['status']);
        $firstSignature=ASCLA\Core\Services\MicroProposalText::signature(get_post($id),get_post_meta($id,'_ascla',true));
        Content::save('event',['title'=>'Laboratorio Austral','body'=>'Evaluar decisiones de cumplimiento.','status'=>'pending','meta'=>['agenda'=>'Preguntas sobre cumplimiento y compromisos.']],$id);
        self::assertTrue(ASCLA\Core\Services\MicroProposalText::run($id,$state['job_id'],$firstSignature)['skipped']);
        for ($i=0;$i<5 && ASCLA\Core\Services\MicroProposalText::status($id)['status']==='pending';$i++) { ASCLA\Core\Jobs\Queue::run(); }
        self::assertSame('completed',ASCLA\Core\Services\MicroProposalText::status($id)['status']);
        $meta=get_post_meta($id,'_ascla',true);
        self::assertSame('Laboratorio Austral',get_post($id)->post_title);
        self::assertSame('Preguntas sobre cumplimiento y compromisos.',$meta['agenda']);
        self::assertStringContainsString('Laboratorio Austral',$meta['agenda_ai']['icebreaker']);
        self::assertStringContainsString('Laboratorio Austral',$meta['agenda_ai']['closing_question']);
        self::assertStringNotContainsString('Laboratorio Boreal',wp_json_encode($meta['agenda_ai']));
        $completed=ASCLA\Core\Services\MicroProposalText::status($id);
        Store::delete('jobs',['id'=>$completed['job_id']]);
        self::assertArrayHasKey('agenda_ai',Content::serialize(get_post($id))['meta']);
    }
    public function testFailedTextRefreshKeepsEditsAndCanBeRetried(): void
    {
        $id=$this->proposal();$old=get_post_meta($id,'_ascla',true)['agenda_ai'];
        $key=ASCLA\Core\Integrations\Secrets::get('ai_key');
        $offline=static fn()=>new WP_Error('offline','Synthetic transport failure');
        try {
            Settings::save(['ai_provider'=>'gemini','ai_key'=>'synthetic-key-for-copy-test','ai_model'=>'gemini-copy-offline-test']);
            add_filter('pre_http_request',$offline,10,3);
            Content::save('event',['title'=>'Propuesta editada','body'=>'Descripción guardada por administración.','status'=>'pending'],$id);
            ASCLA\Core\Jobs\Queue::run();
            self::assertSame('error',ASCLA\Core\Services\MicroProposalText::status($id)['status']);
            self::assertSame('Propuesta editada',get_post($id)->post_title);
            self::assertSame($old,get_post_meta($id,'_ascla',true)['agenda_ai']);
            self::assertArrayNotHasKey('agenda_ai',Content::serialize(get_post($id))['meta']);
        } finally {
            remove_filter('pre_http_request',$offline,10);
            ASCLA\Core\Integrations\Secrets::remove('ai_key');
            if ($key!=='') { ASCLA\Core\Integrations\Secrets::set('ai_key',$key); }
            Settings::save(['ai_provider'=>'mock']);
        }
        ASCLA\Core\Services\MicroProposalText::schedule($id,true);
        ASCLA\Core\Jobs\Queue::run();
        self::assertSame('completed',ASCLA\Core\Services\MicroProposalText::status($id)['status']);
        self::assertStringContainsString('Propuesta editada',get_post_meta($id,'_ascla',true)['agenda_ai']['icebreaker']);
        self::assertArrayHasKey('agenda_ai',Content::serialize(get_post($id))['meta']);
    }
    public function testProposalOwnerWithPublishingPermissionCanRefreshAfterEditing(): void
    {
        $owner=get_userdata($this->users[1]);
        $owner->add_cap('ascla_manage',false);
        wp_set_current_user($owner->ID);
        $id=$this->proposal();
        self::assertTrue(Content::canEdit(get_post($id)));
        Content::save('event',['title'=>'Propuesta del ejecutivo','body'=>'Programa revisado por su autor.','status'=>'pending'],$id);
        ASCLA\Core\Jobs\Queue::run();
        self::assertSame('completed',ASCLA\Core\Services\MicroProposalText::status($id)['status']);
        self::assertStringContainsString('Propuesta del ejecutivo',Content::serialize(get_post($id))['meta']['agenda_ai']['icebreaker']);
    }
    public function testExecutiveCanApproveWithoutReasonAndMembersCanCommentAndReplyWithoutUnpublishing(): void
    {
        $id=$this->proposal();
        wp_set_current_user($this->users[1]);
        self::assertTrue(Content::canModerate(get_post($id)));
        $approved=Content::moderate($id,'approve','');
        self::assertSame('publish',$approved['status']);
        $invitees=get_post_meta($id,'_ascla',true)['invitees'];
        $history=get_post_meta($id,'_ascla_micro_history',true);
        $registrationCount=Store::count('registrations','event_id=%d',[$id]);

        Settings::save(['moderate_comments'=>false]);
        wp_set_current_user($invitees[0]);
        $comment=Content::comment($id,'Buena propuesta para compartir experiencias.');
        self::assertSame('publish',$comment['status']);
        self::assertSame('publish',get_post_status($id));
        self::assertSame($id,(int)Content::get($id)->ID);
        self::assertContains($comment['id'],array_column(Content::comments($id),'id'));

        wp_set_current_user($invitees[1]);
        $reply=Content::comment($id,'De acuerdo, participaré.',$comment['id']);
        self::assertSame($comment['id'],(int)get_comment($reply['id'])->comment_parent);
        self::assertSame('publish',get_post_status($id));

        wp_set_current_user($this->users[0]);Settings::save(['moderate_comments'=>true]);
        wp_set_current_user($invitees[0]);
        self::assertSame('pending',Content::comment($id,'Comentario que requiere revisión.')['status']);
        self::assertSame('publish',get_post_status($id));
        self::assertSame($history,get_post_meta($id,'_ascla_micro_history',true));
        self::assertSame($registrationCount,Store::count('registrations','event_id=%d',[$id]));
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
        $id=$this->proposal();$this->approveLater($id,'Convocatoria revisada');$post=get_post($id);
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
        $this->approveLater($id,'Grupo inicialmente revisado');self::assertSame('approved',get_post_meta($id,'_ascla_micro_state',true));
        $removed=$meta['invitees'][0];$profile=get_user_meta($removed,'_ascla_profile',true);$profile['position']='';update_user_meta($removed,'_ascla_profile',$profile);
        $review=MicroEligibility::review($id);
        self::assertSame(1,$review['removed']);self::assertSame(count($meta['invitees'])-1,$review['candidates']);
        self::assertNotContains($removed,$review['item']['meta']['invitees']);self::assertFalse($review['item']['meta']['micro_approved']);
        self::assertSame('pending',$review['item']['status']);
        $saved=Content::moderate($id,'approve','Grupo actualizado y revisado');
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
        self::assertIsArray(MicroEligibility::review($id));wp_set_current_user($this->users[2]);
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
        $id=$this->proposal();$this->approveLater($id,'Revisado');$post=get_post($id);$at=time()+3600;
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
        wp_set_current_user($this->users[1]);self::assertArrayHasKey('micro_invitations',Events::detail($id));
        foreach (array_slice($this->users,2) as $uid) {
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
