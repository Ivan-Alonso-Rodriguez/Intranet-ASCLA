<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{LearnedInterests,Content,KnowledgeRecommendations,MemberInsights,AutomaticInterests,MatchingParticipation};
use ASCLA\Core\Repositories\{Store,InterestSignals};

final class LearnedInterestsTest extends TestCase
{
    private array $users=[],$posts=[],$terms=[];
    private int $admin,$member;
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $name='learning_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$name,'user_pass'=>wp_generate_password(32),'user_email'=>$name.'@example.invalid','role'=>$role]);
        }
        [$this->admin,$this->member]=$this->users;wp_set_current_user($this->member);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach ($this->posts as $id) {wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);}
        foreach ($this->terms as $id) {wp_delete_term($id,'ascla_interest');}
        foreach ($this->users as $id) {
            foreach (['relations','registrations','attendance','notifications'] as $table) {Store::delete($table,['user_id'=>$id]);}
            Store::delete('audit',['actor_id'=>$id]);LearnedInterests::invalidate($id);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function post(array $extra=[],array $meta=[]): int
    {
        $previous=get_current_user_id();wp_set_current_user($this->admin);
        $id=wp_insert_post($extra+['post_type'=>'ascla_hub','post_status'=>'publish','post_title'=>'Aprendizaje '.bin2hex(random_bytes(4)),'post_content'=>'Contenido de prueba','post_author'=>$this->admin]);
        $this->posts[]=$id;wp_set_current_user($previous);update_post_meta($id,'_ascla',$meta+['chatham'=>false]);
        if (!$this->terms) {$term=wp_insert_term('Aprendizaje '.bin2hex(random_bytes(5)),'ascla_interest');$this->terms[]=(int)$term['term_id'];}
        wp_set_object_terms($id,[$this->terms[0]],'ascla_interest');return $id;
    }
    private function api(string $method,string $path,array $data=[]): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$path);
        if($method==='POST') {$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));}
        return rest_do_request($r);
    }
    private function matured(int $id): string
    {
        $ticket=LearnedInterests::start($id)['ticket'];
        $key='ascla_read_'.$this->member.'_'.$id;$value=get_transient($key);$value['started']=time()-16;set_transient($key,$value,600);
        return $ticket;
    }
    public function testRealActionsProducePersistedTopicsWithoutChangingDeclaredInterests(): void
    {
        $post=$this->post();$before=['interests'=>[],'first_name'=>'Prueba'];update_user_meta($this->member,'_ascla_profile',$before);
        Content::react($post,'like',true);$data=LearnedInterests::get();
        self::assertTrue($data['enabled']);self::assertSame(4,$data['topics'][0]['score']);self::assertSame(1,$data['topics'][0]['contents']);
        self::assertSame($before,get_user_meta($this->member,'_ascla_profile',true));self::assertSame($data['topics'],get_user_meta($this->member,'_ascla_learned_topics',true));
        LearnedInterests::invalidate($this->member);self::assertSame($data,LearnedInterests::get());
        Content::react($post,'like',false);self::assertSame([],LearnedInterests::get()['topics']);
    }
    public function testReadsRequireServerTimeSingleUseTicketsAndCorrectMember(): void
    {
        $post=$this->post();$start=LearnedInterests::start($post);
        self::assertSame(400,$this->api('POST','items/'.$post.'/read',['ticket'=>$start['ticket']])->get_status());
        self::assertSame(0,Store::count('relations',"user_id=%d AND kind='interest_read'",[$this->member]));
        $ticket=$this->matured($post);wp_set_current_user($this->users[2]);
        self::assertSame(400,$this->api('POST','items/'.$post.'/read',['ticket'=>$ticket])->get_status());
        wp_set_current_user($this->member);self::assertTrue(LearnedInterests::read($post,$ticket)['recorded']);
        self::assertSame(1,LearnedInterests::get()['topics'][0]['score']);
        self::assertSame(400,$this->api('POST','items/'.$post.'/read',['ticket'=>$ticket])->get_status());
        LearnedInterests::read($post,$this->matured($post));
        self::assertSame(1,Store::count('relations',"user_id=%d AND kind='interest_read'",[$this->member]));
        Content::react($post,'like',true);self::assertSame(4,LearnedInterests::get()['topics'][0]['score'],'Read and like on one content use the strongest signal, not their sum.');
    }
    public function testLearningIsAutomaticAndTheFormerGlobalControlsAreNotWritable(): void
    {
        $post=$this->post();update_user_meta($this->member,'_ascla_personalization',['enabled'=>false]);
        Content::react($post,'like',true);self::assertTrue(LearnedInterests::get()['enabled']);
        self::assertNotEmpty(LearnedInterests::start($post)['ticket']);
        self::assertSame(404,$this->api('POST','interests/learned',['enabled'=>false])->get_status());
    }
    public function testPrivateUnpublishedProtectedDerivedAndOwnContentNeverTeachesTopics(): void
    {
        $parent=$this->post([],['chatham'=>true]);
        $ids=[$parent,$this->post(['post_parent'=>$parent]),$this->post(['post_status'=>'draft']),$this->post(['post_type'=>'ascla_contact']),$this->post([],['micro'=>true]),$this->post([],['generated'=>true,'reviewed'=>false]),$this->post(['post_author'=>$this->member])];
        foreach ($ids as $id) {Store::insert('relations',['user_id'=>$this->member,'target_id'=>$id,'kind'=>'like','created_at'=>current_time('mysql',true)]);}
        self::assertSame([],LearnedInterests::get()['topics']);
        self::assertSame('',LearnedInterests::start($parent)['ticket']);
        $visible=$this->post();Content::react($visible,'like',true);self::assertNotEmpty(LearnedInterests::get()['topics']);
        update_post_meta($visible,'_ascla',['chatham'=>true]);LearnedInterests::invalidate($this->member);
        self::assertSame([],LearnedInterests::get()['topics'],'Previously learned sources are revalidated, never trusted from the persisted projection.');
    }
    public function testLearnedTopicsDriveResourceRankingAndManualTopicRemovalsTakePriority(): void
    {
        $source=$this->post();$target=$this->post(['post_type'=>'ascla_resource']);
        self::assertSame(0,KnowledgeRecommendations::score(get_post($target))['score']);
        Content::react($source,'like',true);$rank=KnowledgeRecommendations::score(get_post($target));
        self::assertGreaterThan(0,$rank['score']);self::assertContains('activity_match',$rank['reasons']);
        AutomaticInterests::manual($this->member,[$this->terms[0]],[]);self::assertSame(0,KnowledgeRecommendations::score(get_post($target))['score']);
        update_user_meta($this->member,'_ascla_profile',['interests'=>[$this->terms[0]]]);
        self::assertContains('interest_match',KnowledgeRecommendations::score(get_post($target))['reasons']);
    }
    public function testCommentRegistrationAndAttendanceUseOnlyCurrentPositiveRecords(): void
    {
        $post=$this->post();
        foreach(range(1,3) as $i) {wp_insert_comment(['comment_post_ID'=>$post,'user_id'=>$this->member,'comment_content'=>'Aporte '.$i,'comment_approved'=>1]);}
        Store::insert('registrations',['user_id'=>$this->member,'event_id'=>$post,'status'=>'accepted','created_at'=>current_time('mysql',true)]);
        Store::insert('attendance',['user_id'=>$this->member,'event_id'=>$post,'status'=>'present','recorded_by'=>$this->admin,'updated_at'=>current_time('mysql',true)]);
        self::assertSame(5,LearnedInterests::get()['topics'][0]['score']);self::assertSame(1,LearnedInterests::get()['topics'][0]['contents']);
        Store::delete('attendance',['user_id'=>$this->member]);Store::delete('registrations',['user_id'=>$this->member]);Store::delete('relations',['user_id'=>$this->member]);LearnedInterests::invalidate($this->member);
        self::assertSame(2,LearnedInterests::get()['topics'][0]['score'],'Repeated approved comments count once per content.');
    }

    public function testLegacyFollowsDoNotTeachTopicsOrMatchingAndLikesStillDo(): void
    {
        $source=$this->post();$target=$this->post(['post_type'=>'ascla_resource']);
        Store::insert('relations',['user_id'=>$this->member,'target_id'=>$source,'kind'=>'follow','created_at'=>current_time('mysql',true)]);
        self::assertSame([],InterestSignals::recent($this->member));
        self::assertSame([],LearnedInterests::get()['topics']);
        self::assertSame([],MatchingParticipation::topics($this->member));
        self::assertSame(0,KnowledgeRecommendations::score(get_post($target))['score']);
        self::assertSame(1,Store::count('relations',"user_id=%d AND kind='follow'",[$this->member]));
        Content::react($source,'like',true);
        self::assertSame(4,LearnedInterests::get()['topics'][0]['score']);
        self::assertNotEmpty(MatchingParticipation::topics($this->member));
        self::assertGreaterThan(0,KnowledgeRecommendations::score(get_post($target))['score']);
    }
    public function testRoutesEnforceSessionOwnershipTypesAndAdministrativeReadOnlyAccess(): void
    {
        $post=$this->post();Content::react($post,'like',true);
        $request=new WP_REST_Request('GET','/ascla/v1/interests/learned');$request->set_query_params(['user_id'=>$this->users[2]]);
        self::assertNotEmpty(rest_do_request($request)->get_data()['topics']);
        self::assertSame('',get_user_meta($this->users[2],'_ascla_learned_topics',true));
        self::assertSame(404,$this->api('POST','interests/learned',['enabled'=>false])->get_status());
        self::assertSame(403,$this->api('GET','admin/users/'.$this->users[2].'/insights')->get_status());
        wp_set_current_user($this->admin);self::assertNotEmpty(MemberInsights::get($this->member)['learned_interests']['topics']);
        wp_set_current_user(0);self::assertSame(401,$this->api('GET','interests/learned')->get_status());
        wp_set_current_user($this->member);update_user_meta($this->member,'_ascla_suspended',true);
        self::assertSame(403,$this->api('POST','items/'.$post.'/reading')->get_status());
    }
    public function testOldSignalsAndRetentionAreBoundedWithoutDeletingLikes(): void
    {
        $post=$this->post();$old=gmdate('Y-m-d H:i:s',time()-91*DAY_IN_SECONDS);
        Store::insert('relations',['user_id'=>$this->member,'target_id'=>$post,'kind'=>'interest_read','created_at'=>$old]);
        Store::insert('relations',['user_id'=>$this->member,'target_id'=>$post,'kind'=>'like','created_at'=>$old]);
        self::assertSame([],LearnedInterests::get()['topics']);LearnedInterests::cleanup();
        self::assertSame(0,Store::count('relations',"user_id=%d AND kind='interest_read'",[$this->member]));
        self::assertSame(1,Store::count('relations',"user_id=%d AND kind='like'",[$this->member]));
        foreach(range(1,252) as $id) {Store::insert('relations',['user_id'=>$this->member,'target_id'=>$post+$id,'kind'=>'interest_read','created_at'=>current_time('mysql',true)]);}
        InterestSignals::pruneReads($this->member);self::assertSame(250,Store::count('relations',"user_id=%d AND kind='interest_read'",[$this->member]));
    }
    public function testFailedInterestWriteDoesNotClaimSuccessOrKeepAPartialReading(): void
    {
        $post=$this->post();LearnedInterests::get();$ticket=$this->matured($post);
        $reject=static fn($result,$id,$key)=>$key==='_ascla_learned_topics'?false:$result;
        add_filter('update_user_metadata',$reject,10,3);
        try {self::assertSame(500,$this->api('POST','items/'.$post.'/read',['ticket'=>$ticket])->get_status());}
        finally {remove_filter('update_user_metadata',$reject,10);}
        self::assertSame(0,Store::count('relations',"user_id=%d AND kind='interest_read'",[$this->member]));
        self::assertSame([],LearnedInterests::get()['topics']);
    }
}
