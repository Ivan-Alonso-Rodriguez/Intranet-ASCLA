<?php

use PHPUnit\Framework\TestCase;

use ASCLA\Core\Services\{Profiles,Content,Events,Messaging,Notifications,Settings,Matching,Knowledge,Access,Media};

use ASCLA\Core\Repositories\Store;

use ASCLA\Core\Database\Installer;

use ASCLA\Core\Jobs\Queue;

final class IntegrationTest extends TestCase

{

    private array $users=[]; private array $posts=[]; private array $settings=[];

    protected function setUp(): void

    {

        $this->settings=Settings::get();$nonce=bin2hex(random_bytes(4));

        foreach(['administrator','ascla_member','ascla_member','ascla_member'] as $i=>$role){$id=wp_insert_user(['user_login'=>'test_'.$nonce.'_'.$i,'user_pass'=>wp_generate_password(30),'user_email'=>'test_'.$nonce.'_'.$i.'@example.invalid','role'=>$role]);self::assertIsInt($id);$this->users[]=$id;}

        wp_set_current_user($this->users[0]);Settings::save(['moderation_required'=>true,'moderate_comments'=>false,'ai_mode'=>'mock','youtube_mode'=>'mock','micro_enabled'=>false]);

    }

    protected function tearDown(): void

    {

        wp_set_current_user($this->users[0]);

        foreach($this->posts as $id)wp_delete_post($id,true);

        foreach($this->users as $id){

            foreach(['messages'=>['sender_id'=>$id],'participants'=>['user_id'=>$id],'relations'=>['user_id'=>$id],'registrations'=>['user_id'=>$id],'notifications'=>['user_id'=>$id],'jobs'=>['user_id'=>$id],'media'=>['user_id'=>$id]] as $t=>$where)Store::delete($t,$where);

            wp_delete_user($id);

        }

        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);

    }

    private function user(int $index): void {wp_set_current_user($this->users[$index]);}

    private function make(string $type,array $data=[]): array

    {

        $item=Content::save($type,array_merge(['title'=>'Test '.$type,'body'=>'La gobernanza y los riesgos requieren supervisión.','status'=>'publish'],$data));$this->posts[]=$item['id'];return $item;

    }

    private function api(string $method,string $route,array $body=[]): WP_REST_Response

    {

        $r=new WP_REST_Request($method,'/ascla/v1'.$route);if($body){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}return rest_do_request($r);

    }

    public function testParticipationDefaultsEnableNetworkingAndMicroevents(): void

    {

        $this->user(1);$profile=Profiles::raw($this->users[1]);self::assertTrue($profile['networking']);self::assertTrue($profile['microevents']);

        Profiles::save(['networking'=>false,'microevents'=>false]);$profile=Profiles::raw($this->users[1]);self::assertFalse($profile['networking']);self::assertFalse($profile['microevents']);

    }

    public function testPastEventsCannotBeRegisteredOrFollowed(): void

    {

        $p=$this->make('event',['meta'=>['start'=>gmdate('c',time()-7200),'end'=>gmdate('c',time()-3600),'capacity'=>20,'modality'=>'Virtual']]);$this->user(1);

        $detail=Events::detail($p['id']);self::assertTrue($detail['is_past']);self::assertSame('', $detail['google_url']);self::assertArrayNotHasKey('ics',$detail);

        self::assertSame(400,$this->api('POST','/events/'.$p['id'].'/register',['status'=>'accepted'])->get_status());
        self::assertSame(400,$this->api('POST','/events/'.$p['id'].'/google',['operation'=>'save'])->get_status());

        self::assertSame(400,$this->api('POST','/items/'.$p['id'].'/reaction',['kind'=>'follow','active'=>true])->get_status());

        self::assertSame(0,Store::count('registrations','event_id=%d AND user_id=%d',[$p['id'],$this->users[1]]));

        self::assertSame(0,Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',[$this->users[1],$p['id'],'follow']));

    }

    public function testRecommendationsRespectMinimumAffinity(): void
    {
        $interests=Profiles::catalogs()['interest'];self::assertGreaterThanOrEqual(2,count($interests));$one=(int)$interests[0]['id'];$two=(int)$interests[1]['id'];
        $this->user(1);Profiles::save(['networking'=>true,'directory'=>true,'interests'=>[$one],'areas'=>[],'industries'=>[],'goals'=>[],'languages'=>[]]);
        $this->user(2);Profiles::save(['networking'=>true,'directory'=>true,'interests'=>[$one],'areas'=>[],'industries'=>[],'goals'=>[],'languages'=>[]]);
        $this->user(1);Settings::save(['matching_weights'=>['interests'=>100,'areas'=>0,'industries'=>0,'goals'=>0,'languages'=>0],'matching_min_affinity'=>100]);
        $recommended=Matching::recommendations();self::assertNotEmpty($recommended);self::assertSame(100,(int)$recommended[0]['affinity']['score']);
        Profiles::save(['interests'=>[$two]],$this->users[2]);
        self::assertSame([],Matching::recommendations());
    }

    public function testInstallerIsIdempotentAndKeepsExistingPages(): void

    {

        $before=get_option('ascla_pages');Installer::activate(false);Installer::activate(false);self::assertSame($before,get_option('ascla_pages'));self::assertCount(12,$before);

        foreach($before as $slug=>$id)self::assertSame($slug,get_post_meta($id,'_ascla_page',true));

        update_option('ascla_schema',0);Installer::activate(false);self::assertSame(7,(int)get_option('ascla_schema'));

    }

    public function testAdminProfileMediaLibraryRemainsPersonalWhileAdminScopeCanSeeAll(): void
    {
        $adminFile=Store::insert('media',['user_id'=>$this->users[0],'post_id'=>0,'original_id'=>0,'name'=>'admin-file.pdf','mime'=>'application/pdf','bytes'=>'admin','created_at'=>current_time('mysql',true)]);
        $memberFile=Store::insert('media',['user_id'=>$this->users[1],'post_id'=>0,'original_id'=>0,'name'=>'member-file.pdf','mime'=>'application/pdf','bytes'=>'member','created_at'=>current_time('mysql',true)]);
        $this->user(0);$mine=Media::listing(['scope'=>'mine']);self::assertContains($adminFile,array_column($mine['items'],'id'));self::assertNotContains($memberFile,array_column($mine['items'],'id'));
        $all=Media::listing(['scope'=>'all']);self::assertContains($adminFile,array_column($all['items'],'id'));self::assertContains($memberFile,array_column($all['items'],'id'));
        $this->user(1);$forced=Media::listing(['scope'=>'all']);self::assertContains($memberFile,array_column($forced['items'],'id'));self::assertNotContains($adminFile,array_column($forced['items'],'id'));
    }

    public function testReportedContentCanBeMarkedReviewedAndNewReportReopensIt(): void
    {
        $p=$this->make('hub');$this->user(1);Content::report($p['id'],'spam','Reporte de prueba.');
        $row=Store::rows('relations','user_id=%d AND target_id=%d AND kind=%s',[$this->users[1],$p['id'],'report'],'LIMIT 1')[0];self::assertEmpty($row['reviewed_at']);
        $this->user(0);$reviewed=$this->api('POST','/admin/reports/'.(int)$row['id'].'/review');self::assertSame(200,$reviewed->get_status());
        $row=Store::one('relations',(int)$row['id']);self::assertNotEmpty($row['reviewed_at']);self::assertSame($this->users[0],(int)$row['reviewed_by']);
        $this->user(1);Content::report($p['id'],'spam','Información adicional nueva.');$row=Store::one('relations',(int)$row['id']);self::assertEmpty($row['reviewed_at']);self::assertSame(0,(int)$row['reviewed_by']);
    }

    public function testProfilePrivacyCannotBeBypassedBySearchOrMatching(): void

    {

        $this->user(1);$tax=Profiles::catalogs();$profile=Profiles::save(['first_name'=>'Ficticia','last_name'=>'Prueba','company'=>'Empresa Secreta QXYZ','networking'=>true,'interests'=>[$tax['interest'][0]['id']],'hidden'=>['company']]);self::assertSame('Empresa Secreta QXYZ',$profile['company']);

        $this->user(2);Profiles::save(['networking'=>true,'interests'=>[$tax['interest'][0]['id']]]);self::assertArrayNotHasKey('company',Profiles::visible($this->users[1]));self::assertSame(0,Profiles::directory(['q'=>'QXYZ'])['total']);self::assertSame(30,Matching::between($this->users[2],$this->users[1])['score']);

        $this->user(1);Profiles::save(['directory'=>false]);$this->user(2);self::assertSame(404,$this->api('GET','/profiles/'.$this->users[1])->get_status());

    }

    public function testProfileRejectsUnknownTaxonomiesAndUnsafeURLs(): void

    {

        $this->user(1);self::assertSame(400,$this->api('POST','/profiles/me',['website'=>'javascript:alert(1)'])->get_status());self::assertSame(400,$this->api('POST','/profiles/me',['interests'=>[999999999]])->get_status());

    }

    public function testProfileCompletionAndCountryNormalization(): void
    {
        $this->user(1);$before=Profiles::completion($this->users[1]);self::assertLessThan(40,$before['percent']);
        Profiles::save(['first_name'=>'Ana','last_name'=>'Asociada','position'=>'Secretaria corporativa','company'=>'Empresa Demo','country'=>'Peru','city'=>'Lima']);
        $profile=Profiles::raw($this->users[1]);self::assertSame('Perú',$profile['country']);self::assertGreaterThanOrEqual(40,Profiles::completion($this->users[1])['percent']);
        self::assertSame(400,$this->api('POST','/profiles/me',['country'=>'Pais Inventado QXYZ'])->get_status());
    }


    public function testMemberCannotPublishOrModerateOrReadOthersDrafts(): void

    {

        $this->user(1);$p=$this->make('hub');self::assertSame('pending',$p['status']);$this->user(2);self::assertSame(404,$this->api('GET','/items/'.$p['id'])->get_status());self::assertSame(403,$this->api('POST','/items/'.$p['id'].'/moderate',['decision'=>'approve','reason'=>'Test'])->get_status());

        $this->user(0);$p=Content::moderate($p['id'],'approve','Contenido relevante.');self::assertSame('publish',$p['status']);$this->user(2);self::assertSame(200,$this->api('GET','/items/'.$p['id'])->get_status());

        self::assertSame(403,$this->api('POST','/content/resource',['title'=>'Prueba','body'=>'Prueba'])->get_status());

    }

    public function testCommentsReactionsAndReports(): void

    {

        $p=$this->make('hub');$this->user(1);$r=Content::comment($p['id'],'Un comentario constructivo.');self::assertSame('publish',$r['status']);self::assertCount(1,Content::comments($p['id']));

        Content::react($p['id'],'like',true);Content::react($p['id'],'like',true);Content::react($p['id'],'follow',true);Content::react($p['id'],'report',true);self::assertSame(1,Content::serialize(Content::get($p['id']))['reactions']);Content::react($p['id'],'like',false);self::assertSame(0,Content::serialize(Content::get($p['id']))['reactions']);

        $this->user(0);Settings::save(['moderate_comments'=>true]);$this->user(2);self::assertSame('pending',Content::comment($p['id'],'Revisión previa.')['status']);

    }

    public function testCommentReportsCannotTargetOwnComment(): void

    {

        $p=$this->make('hub');
        $this->user(1);$own=Content::comment($p['id'],'Comentario propio para validar reporte.');
        self::assertSame(400,$this->api('POST','/comments/'.$own['id'].'/report',['reason'=>'spam'])->get_status());
        self::assertSame(0,Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',[$this->users[1],$own['id'],'comment_report']));

        $this->user(2);
        self::assertSame(400,$this->api('POST','/comments/'.$own['id'].'/report',['reason'=>'other','detail'=>'   '])->get_status());
        self::assertSame(0,Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',[$this->users[2],$own['id'],'comment_report']));
        $reported=$this->api('POST','/comments/'.$own['id'].'/report',['reason'=>'other','detail'=>'Motivo específico de prueba.']);
        self::assertSame(200,$reported->get_status());
        self::assertSame(1,Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',[$this->users[2],$own['id'],'comment_report']));
        self::assertSame(400,$this->api('POST','/items/'.$p['id'].'/report',['reason'=>'other','detail'=>''])->get_status());
        $comments=Content::comments($p['id']);self::assertTrue($comments[0]['can_report']);

        $this->user(1);$comments=Content::comments($p['id']);self::assertFalse($comments[0]['can_report']);

    }

    public function testMessagesArePrivateAndRespectBlocking(): void

    {

        Store::insert('relations',['user_id'=>$this->users[1],'target_id'=>$this->users[2],'kind'=>'connected','created_at'=>current_time('mysql',true)]);$this->user(1);$c=Messaging::start($this->users[2]);self::assertSame($c['id'],Messaging::start($this->users[2])['id']);Messaging::send((int)$c['id'],'Mensaje sólo entre dos miembros.');

        $this->user(3);self::assertSame(404,$this->api('GET','/conversations/'.$c['id'].'/messages')->get_status());self::assertSame(404,$this->api('POST','/conversations/'.$c['id'].'/messages',['body'=>'Intrusión'])->get_status());

        $this->user(2);self::assertGreaterThan(0,Messaging::conversations()[0]['unread']);self::assertCount(1,Messaging::messages((int)$c['id'])['items']);self::assertSame(0,Messaging::conversations()[0]['unread']);Messaging::relation($this->users[1],'block',true);

        $this->user(1);self::assertSame(403,$this->api('POST','/conversations/'.$c['id'].'/messages',['body'=>'Bloqueado'])->get_status());$this->user(2);Messaging::relation($this->users[1],'block',false);Messaging::send((int)$c['id'],'Respuesta autorizada.');Store::delete('conversations',['id'=>$c['id']]);

    }

    public function testEventCapacityAndCancellation(): void

    {

        $p=$this->make('event',['meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200),'capacity'=>1,'modality'=>'Virtual']]);$this->user(1);$d=Events::register($p['id'],'accepted');self::assertSame(1,$d['attending']);Events::register($p['id'],'accepted');$this->user(2);self::assertSame(409,$this->api('POST','/events/'.$p['id'].'/register',['status'=>'accepted'])->get_status());$this->user(1);Events::register($p['id'],'cancelled');$this->user(2);self::assertSame('accepted',Events::register($p['id'],'accepted')['registered']);self::assertArrayNotHasKey('participants',Events::detail($p['id']));

        $this->user(0);self::assertCount(2,Events::detail($p['id'])['participants']);

    }

    public function testContactRequestsStayPrivateAndHaveStatuses(): void

    {

        $this->user(1);$p=$this->make('contact');self::assertSame('private',$p['status']);self::assertSame(1,Content::listing('contact',['mine'=>1])['total']);$this->user(2);self::assertSame(0,Content::listing('contact',['author'=>$this->users[1]])['total']);self::assertSame(404,$this->api('GET','/items/'.$p['id'])->get_status());$this->user(0);self::assertSame(200,$this->api('POST','/admin/contact/'.$p['id'],['status'=>'closed'])->get_status());

    }

    public function testAIProducesDraftsAndRequiresExplicitReview(): void

    {

        $p=$this->make('resource',['meta'=>['resource_type'=>'Video','chatham'=>true,'transcript'=>'Persona Prueba: La junta supervisa riesgos de IA en Compañía Privada. Los acuerdos requieren responsables y seguimiento.','identities'=>"Persona Prueba\nCompañía Privada"]]);$r=Knowledge::multimedia($p['id']);$this->posts[]=$r['resource_id'];$this->posts[]=$r['hub_id'];foreach($r['capsule_ids'] as $cid)$this->posts[]=$cid;

        $draft=get_post($r['resource_id']);self::assertSame('draft',$draft->post_status);self::assertStringNotContainsString('Persona Prueba',$draft->post_content);self::assertStringNotContainsString('Compañía Privada',$draft->post_content);

        self::assertSame(400,$this->api('POST','/items/'.$draft->ID.'/moderate',['decision'=>'approve','reason'=>'Revisión'])->get_status());wp_update_post(['ID'=>$draft->ID,'post_status'=>'publish']);self::assertSame('pending',get_post_status($draft->ID));Content::moderate($draft->ID,'approve','Fuentes y anonimización revisadas.',true);self::assertSame('publish',get_post_status($draft->ID));

    }

    public function testAssistantUsesOnlyPublishedSourcesAndAbstains(): void

    {

        $published=$this->make('resource',['title'=>'Tema QXZTR123','body'=>'QXZTR123 exige seguimiento documentado de responsabilidades.']);$private=$this->make('resource',['title'=>'Secreto PRIV987','body'=>'PRIV987 no debe mostrarse.','status'=>'draft']);$this->user(1);

        $answer=Knowledge::answer('QXZTR123');self::assertSame([$published['id']],array_column($answer['sources'],'id'));

        $answer=Knowledge::answer('PRIV987');self::assertSame([],$answer['sources']);self::assertStringContainsString('suficiente información',$answer['answer']);

        $answer=Knowledge::answer('Tema completamente inexistente ZXCV1234444');self::assertSame([],$answer['sources']);

    }

    public function testAssistantKnowsUpcomingEventsFromTheIntranet(): void

    {

        $event=$this->make('event',['title'=>'Reunión próxima ASCLA','body'=>'Encuentro interno para conversar sobre gobierno corporativo.','meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200),'capacity'=>20,'modality'=>'Virtual','location'=>'Sala virtual']]);
        $this->user(1);$answer=Knowledge::answer('¿Hay alguna reunión pronto?');
        self::assertContains($event['id'],array_column($answer['sources'],'id'));
        self::assertStringContainsString('Reunión próxima ASCLA',$answer['answer']);

    }

    public function testAssistantThreadsKeepConversationHistoryScopedToTheMember(): void

    {

        $this->user(1);$thread='chat_test_'.bin2hex(random_bytes(4));
        $response=$this->api('POST','/ask',['question'=>'Hola','thread'=>$thread]);self::assertSame(200,$response->get_status());$job=$response->get_data();
        for($attempt=0;$attempt<30&&Queue::get((int)$job['id'])['status']==='pending';$attempt++){Queue::run();}
        $items=Queue::thread($thread);self::assertCount(1,$items);self::assertSame('Hola',$items[0]['question']);self::assertSame('completed',$items[0]['status']);
        $this->user(2);self::assertSame([],Queue::thread($thread));

    }

    public function testKnowledgeFindsOldSourcesBeyondTheFirst300Posts(): void

    {

        $old=$this->make('resource',['title'=>'ARCHIVEQZX987','body'=>'ARCHIVEQZX987 documenta responsabilidades de supervisión.']);wp_update_post(['ID'=>$old['id'],'post_date'=>'2001-01-01 00:00:00','post_date_gmt'=>'2001-01-01 00:00:00']);

        for($i=0;$i<305;$i++){$this->posts[]=wp_insert_post(['post_type'=>'ascla_resource','post_title'=>'Filler '.$i,'post_content'=>'Material sin relación con la consulta específica.','post_status'=>'publish']);}

        $this->user(1);$answer=Knowledge::answer('ARCHIVEQZX987');self::assertSame([$old['id']],array_column($answer['sources'],'id'));

    }

    public function testBackgroundJobsTrackOwnershipCompletionAndFailure(): void

    {

        $this->user(1);$j=Queue::enqueue('answer',['question'=>'ZXCV1234444']);$this->user(2);self::assertSame(404,$this->api('GET','/jobs/'.$j['id'])->get_status());$this->user(1);for($attempt=0;$attempt<30&&Queue::get($j['id'])['status']==='pending';$attempt++){Queue::run();}self::assertSame('completed',Queue::get($j['id'])['status']);self::assertArrayNotHasKey('payload',Queue::get($j['id']));

        $this->user(0);$j=Queue::enqueue('multimedia',['resource_id'=>99999999]);for($attempt=0;$attempt<30&&Queue::get($j['id'])['status']==='pending';$attempt++){Queue::run();}self::assertSame('error',Queue::get($j['id'])['status']);self::assertSame('pending',Queue::retry($j['id'])['status']);Store::delete('jobs',['id'=>$j['id']]);

    }

    public function testAnonymousAndOrdinaryWPUsersCannotUseAnyPrivateEndpoint(): void

    {

        wp_set_current_user(0);foreach(['/bootstrap','/profiles','/content/hub','/conversations','/notifications','/admin','/settings'] as $route)self::assertSame(401,$this->api('GET',$route)->get_status());

        $this->user(1);self::assertSame(403,$this->api('GET','/settings')->get_status());self::assertSame(403,$this->api('GET','/admin')->get_status());

        update_user_meta($this->users[1],'_ascla_suspended',true);self::assertSame(403,$this->api('GET','/bootstrap')->get_status());

    }

    public function testSettingsNeverReturnSecretsAndValidateWeights(): void

    {

        $r=Settings::save(['ai_key'=>'test-key-never-real','matching_weights'=>['interests'=>100,'areas'=>0,'industries'=>0,'goals'=>0,'languages'=>0]]);self::assertTrue($r['has_ai_key']);self::assertArrayNotHasKey('ai_key',$r);self::assertStringNotContainsString('test-key-never-real',wp_json_encode($r));Settings::save(['clear_ai_key'=>true]);self::assertFalse(Settings::status()['has_ai_key']);

        self::assertSame(400,$this->api('POST','/settings',['matching_weights'=>array_fill_keys(['interests','areas','industries','goals','languages'],0)])->get_status());
        Settings::save(['matching_min_affinity'=>67]);self::assertSame(67,Settings::get()['matching_min_affinity']);
        Settings::save(['matching_min_affinity'=>999]);self::assertSame(100,Settings::get()['matching_min_affinity']);
        Settings::save(['matching_min_affinity'=>-10]);self::assertSame(0,Settings::get()['matching_min_affinity']);

    }

    public function testNotificationsReadOperationIsScopedToRecipient(): void

    {

        Notifications::send($this->users[1],'test','Prueba');$this->user(1);$n=Notifications::list()[0];$this->user(2);Notifications::read((int)$n['id']);self::assertNull(Store::one('notifications',(int)$n['id'])['read_at']);$this->user(1);Notifications::read((int)$n['id']);self::assertNotNull(Store::one('notifications',(int)$n['id'])['read_at']);

    }

    public function testBrowserISODateMillisecondsAndInvalidCalendarDates(): void

    {

        $meta=['start'=>'2027-03-01T15:00:00.123Z','end'=>'2027-03-01T16:00:00.456Z'];

        $event=$this->make('event',['meta'=>$meta]);self::assertSame('2027-03-01T15:00:00+00:00',$event['meta']['start']);

        $meta['start']='2027-02-30T15:00:00Z';self::assertSame(400,$this->api('POST','/content/event',['title'=>'Invalid date','body'=>'Fixture','meta'=>$meta])->get_status());

    }

    public function testNativeRecentCommentsBlockExcludesPrivateHub(): void
    {
        $post=$this->make('hub');Content::comment($post['id'],'PRIVATE_WIDGET_579XYZ');
        wp_set_current_user(0);$args=apply_filters('widget_comments_args',['status'=>'approve','post_status'=>'publish','number'=>100]);
        foreach(get_comments($args) as $comment)self::assertNotSame('PRIVATE_WIDGET_579XYZ',$comment->comment_content);
        self::assertArrayNotHasKey('ascla_hub',apply_filters('wp_sitemaps_post_types',get_post_types([], 'objects')));
        self::assertStringContainsString('NOT EXISTS',apply_filters('comment_feed_where','WHERE 1=1'));
    }
    public function testEventDateValidationAndUnsupportedContent(): void

    {

        self::assertSame(400,$this->api('POST','/content/event',['title'=>'Evento','body'=>'Texto','meta'=>['start'=>'bad','end'=>'bad']])->get_status());self::assertSame(400,$this->api('GET','/content/unknown')->get_status());self::assertSame(400,$this->api('POST','/content/resource',['title'=>'Video','body'=>'Texto','meta'=>['youtube_url'=>'https://evil.test/video']])->get_status());

    }

}

