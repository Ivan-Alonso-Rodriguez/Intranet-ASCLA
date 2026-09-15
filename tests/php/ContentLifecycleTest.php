<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Media,Knowledge,Profiles};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Frontend\{Language,Login};

final class ContentLifecycleTest extends TestCase
{
    private array $users=[],$posts=[],$media=[];
    protected function setUp():void
    {
        foreach(['administrator','ascla_moderator','ascla_member','ascla_member'] as $role){$login='lifecycle_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);}
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown():void
    {
        unset($_POST['_ascla_locale'],$_GET['wp_lang'],$_COOKIE['wp_lang']);
        wp_set_current_user($this->users[0]);foreach($this->posts as $id)wp_delete_post($id,true);foreach($this->media as $id)Store::delete('media',['id'=>$id]);
        foreach($this->users as $id){foreach(['notifications','jobs','relations'] as $t)Store::delete($t,['user_id'=>$id]);wp_delete_user($id);}wp_set_current_user(0);
    }
    private function post(string $type='hub',array $extra=[]):array
    {
        $p=Content::save($type,$extra+['title'=>'Lifecycle '.bin2hex(random_bytes(5)),'body'=>'Contenido de prueba','status'=>'publish','meta'=>['chatham'=>false]]);$this->posts[]=$p['id'];return $p;
    }
    private function api(string $method,string $route,array $body=[]):WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$route);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));return rest_do_request($r);
    }
    public function testEveryEditorialTypeCanBeTrashedByAnAdministrator():void
    {
        foreach(array_keys(ASCLA\Core\Domain\Catalog::TYPES) as $type){
            $meta=$type==='event'?['start'=>gmdate('c',time()+86400),'end'=>gmdate('c',time()+90000)]:[];
            $p=$this->post($type,['meta'=>$meta]);self::assertTrue($p['can_delete']);
            self::assertSame(200,$this->api('DELETE','items/'.$p['id'])->get_status());self::assertSame('trash',get_post_status($p['id']));
            self::assertSame(404,$this->api('GET','items/'.$p['id'])->get_status());self::assertSame(404,$this->api('DELETE','items/'.$p['id'])->get_status());
            self::assertNotContains($p['id'],array_column(Content::listing($type)['items'],'id'));
        }
    }
    public function testAuthorCanDeleteOwnPendingContentButAnotherMemberOrModeratorCannot():void
    {
        wp_set_current_user($this->users[2]);$p=$this->post();self::assertSame('pending',$p['status']);self::assertTrue($p['can_delete']);
        wp_set_current_user($this->users[1]);self::assertFalse(Content::serialize(Content::get($p['id']))['can_delete']);self::assertSame(403,$this->api('DELETE','items/'.$p['id'])->get_status());
        wp_set_current_user($this->users[3]);self::assertSame(404,$this->api('DELETE','items/'.$p['id'])->get_status());
        wp_set_current_user($this->users[2]);self::assertSame(200,$this->api('DELETE','items/'.$p['id'])->get_status());
        wp_set_current_user(0);self::assertSame(401,$this->api('DELETE','items/'.$p['id'])->get_status());
    }
    public function testForumRemovalKeepsOtherAuthorsTopicsInGeneralList():void
    {
        $forum=$this->post('forum');wp_set_current_user($this->users[2]);$topic=$this->post('topic',['parent'=>$forum['id']]);
        wp_set_current_user($this->users[0]);Content::remove($forum['id']);
        wp_set_current_user($this->users[2]);$p=Content::get($topic['id']);self::assertSame(0,(int)$p->post_parent);self::assertSame('publish',$p->post_status);
    }
    public function testCommentsRequireAuthorOrAdministratorAndPendingOwnCommentsAreVisible():void
    {
        $hub=$this->post();wp_set_current_user($this->users[2]);$c=Content::comment($hub['id'],'Comentario propio');
        self::assertContains($c['id'],array_column(Content::comments($hub['id']),'id'));
        wp_set_current_user($this->users[1]);self::assertSame(403,$this->api('DELETE','comments/'.$c['id'])->get_status());
        wp_set_current_user($this->users[3]);self::assertSame(403,$this->api('DELETE','comments/'.$c['id'])->get_status());
        wp_set_current_user($this->users[2]);self::assertSame(200,$this->api('DELETE','comments/'.$c['id'])->get_status());self::assertNotContains($c['id'],array_column(Content::comments($hub['id']),'id'));
    }
    public function testFileDeletionRemovesPostAndProfileReferencesButPreservesOtherFiles():void
    {
        wp_set_current_user($this->users[2]);$id=Store::insert('media',['user_id'=>$this->users[2],'post_id'=>0,'name'=>'lifecycle-private.png','mime'=>'image/png','bytes'=>'fixture','created_at'=>current_time('mysql',true)]);$this->media[]=$id;
        Profiles::save(['photo_id'=>$id]);$post=$this->post('hub',['meta'=>['media_ids'=>[$id]]]);self::assertTrue($post['media'][0]['can_delete']);
        wp_set_current_user($this->users[3]);self::assertNotContains($id,array_column(Media::listing()['items'],'id'));self::assertSame(403,$this->api('DELETE','media/'.$id)->get_status());
        wp_set_current_user($this->users[1]);self::assertSame(403,$this->api('DELETE','media/'.$id)->get_status());
        wp_set_current_user($this->users[0]);self::assertContains($id,array_column(Media::listing(['q'=>'lifecycle-private'])['items'],'id'));self::assertSame(200,$this->api('DELETE','media/'.$id)->get_status());
        self::assertNull(Store::one('media',$id));self::assertSame([],get_post_meta($post['id'],'_ascla',true)['media_ids']);self::assertSame(0,get_user_meta($this->users[2],'_ascla_profile',true)['photo_id']);self::assertSame(404,$this->api('DELETE','media/'.$id)->get_status());
        wp_set_current_user($this->users[2]);$id=Store::insert('media',['user_id'=>$this->users[2],'post_id'=>0,'name'=>'owned.pdf','mime'=>'application/pdf','bytes'=>'test','created_at'=>current_time('mysql',true)]);$this->media[]=$id;self::assertSame(200,$this->api('DELETE','media/'.$id)->get_status());
    }
    public function testCroppedImageKeepsHiddenMasterAndDeletesBothAsOneUnit():void
    {
        wp_set_current_user($this->users[2]);
        $source=Store::insert('media',['user_id'=>$this->users[2],'post_id'=>0,'original_id'=>0,'name'=>'portrait-master.webp','mime'=>'image/webp','bytes'=>'master-fixture','created_at'=>current_time('mysql',true)]);
        $visible=Store::insert('media',['user_id'=>$this->users[2],'post_id'=>0,'original_id'=>$source,'name'=>'portrait-recorte.webp','mime'=>'image/webp','bytes'=>'display-fixture','created_at'=>current_time('mysql',true)]);
        $this->media[]=$source;$this->media[]=$visible;
        $items=Media::listing(['q'=>'portrait'])['items'];
        self::assertNotContains($source,array_column($items,'id'));
        self::assertContains($visible,array_column($items,'id'));
        $entry=current(array_filter($items,static fn($item)=>(int)$item['id']===$visible));
        self::assertTrue($entry['has_master']);
        self::assertSame(strlen('master-fixture')+strlen('display-fixture'),$entry['stored_size']);
        self::assertSame(409,$this->api('DELETE','media/'.$source)->get_status());
        self::assertSame(200,$this->api('DELETE','media/'.$visible)->get_status());
        self::assertNull(Store::one('media',$visible));
        self::assertNull(Store::one('media',$source));
    }

    public function testOnlyAdminCreatesEventsAndNonDraftPublishesImmediately():void
    {
        $payload=['title'=>'Evento directo','body'=>'Descripción','meta'=>['start'=>gmdate('c',time()+86400),'end'=>gmdate('c',time()+90000)]];
        $p=$this->post('event',$payload+['status'=>'pending']);self::assertSame('publish',$p['status']);
        $draft=$this->post('event',$payload+['status'=>'draft']);self::assertSame('draft',$draft['status']);
        foreach(array_slice($this->users,1) as $id){wp_set_current_user($id);self::assertSame(403,$this->api('POST','content/event',$payload)->get_status());self::assertSame(403,$this->api('POST','content/event/'.$p['id'],$payload)->get_status());self::assertSame(403,$this->api('POST','items/'.$draft['id'].'/moderate',['decision'=>'approve','reason'=>'Publicar'])->get_status());}
        wp_set_current_user($this->users[2]);Content::comment($p['id'],'Participaré');self::assertSame('publish',get_post_status($p['id']));
    }
    public function testEventCoverAcceptsOneImageAndRejectsMultipleFilesOrPdf():void
    {
        wp_set_current_user($this->users[0]);
        $makeMedia=function(string $name,string $mime):int {
            $id=Store::insert('media',['user_id'=>$this->users[0],'post_id'=>0,'name'=>$name,'mime'=>$mime,'bytes'=>'fixture','created_at'=>current_time('mysql',true)]);
            $this->media[]=$id;
            return $id;
        };
        $imageA=$makeMedia('event-cover-a.webp','image/webp');
        $imageB=$makeMedia('event-cover-b.png','image/png');
        $pdf=$makeMedia('event-cover.pdf','application/pdf');
        $dates=['start'=>gmdate('c',time()+86400),'end'=>gmdate('c',time()+90000),'capacity'=>20,'modality'=>'Virtual'];
        $event=$this->post('event',['meta'=>$dates+['media_ids'=>[$imageA]]]);
        self::assertSame([$imageA],get_post_meta($event['id'],'_ascla',true)['media_ids']);
        self::assertSame($event['id'],(int)Store::one('media',$imageA)['post_id']);
        self::assertSame($imageA,(int)$event['media'][0]['id']);
        $payload=['title'=>'Evento portada inválida','body'=>'Descripción','status'=>'publish'];
        self::assertSame(400,$this->api('POST','content/event',$payload+['meta'=>$dates+['media_ids'=>[$imageA,$imageB]]])->get_status());
        self::assertSame(400,$this->api('POST','content/event',$payload+['meta'=>$dates+['media_ids'=>[$pdf]]])->get_status());
    }

    public function testLoginLocalePersistsPerUserRejectsMalformedInputAndDoesNotChangeSite():void
    {
        $site=get_option('WPLANG');$user=get_userdata($this->users[2]);$_POST['_ascla_locale']='en_US';Language::remember($user->user_login,$user);
        wp_set_current_user($user->ID);self::assertSame('en_US',get_user_locale());self::assertSame('en_US',Language::current());self::assertSame('Profile',Language::label('Perfil'));
        foreach(['../invalid',['en_US'],'invented',''] as $invalid){$_POST['_ascla_locale']=$invalid;Language::remember($user->user_login,$user);self::assertSame('en_US',get_user_meta($user->ID,'locale',true));}
        unset($_POST['_ascla_locale']);wp_set_current_user($this->users[3]);self::assertSame('es_ES',Language::current());self::assertSame($site,get_option('WPLANG'));
        $_GET['wp_lang']='es_ES';Language::remember($user->user_login,$user);self::assertSame('es_ES',get_user_meta($user->ID,'locale',true));
    }
    public function testFreshMultimediaDemoKeepsAdminContextAndMembersCannotSeed():void
    {
        $old=get_option('ascla_demo_showcase',false);delete_option('ascla_demo_showcase');
        try {
            $source=0;$make=function($key,$type,$title,$body,$meta)use(&$source){$post=$this->post($type,['title'=>$title,'body'=>$body,'meta'=>$meta]);$source=$post['id'];return $source;};
            ASCLA\Core\Services\DemoShowcase::seed($make,array_slice($this->users,1));
            $result=get_option('ascla_demo_showcase');$this->posts[]=$result['resource_id'];
            self::assertSame($source,$result['resource_id']);self::assertSame(0,$result['hub_id']);self::assertSame([],$result['capsule_ids']);
            self::assertSame($this->users[0],get_current_user_id());self::assertSame($this->users[0],(int)get_post($source)->post_author);self::assertTrue((bool)get_post_meta($source,'_ascla',true)['ai_enriched']);
            wp_set_current_user($this->users[2]);self::assertSame(403,$this->api('POST','demo',['password'=>wp_generate_password(30)])->get_status());
            try{ASCLA\Core\Services\Demo::seed(wp_generate_password(30));self::fail('Member seeded demo');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(403,$e->getCode());}
        } finally {if($old===false)delete_option('ascla_demo_showcase');else update_option('ascla_demo_showcase',$old,false);}
    }
    public function testQueueWakeupDoesNotWaitForTheHourlySafetyEvent():void
    {
        $hourly=wp_next_scheduled('ascla_jobs');$old=wp_next_scheduled('ascla_jobs_continue');
        wp_clear_scheduled_hook('ascla_jobs_continue');
        try{
            wp_schedule_single_event(time()+240,'ascla_jobs_continue');
            $job=ASCLA\Core\Jobs\Queue::enqueue('answer',['question'=>'fixtureausencia'.bin2hex(random_bytes(8))]);
            self::assertLessThanOrEqual(time()+2,wp_next_scheduled('ascla_jobs_continue'));self::assertSame($hourly,wp_next_scheduled('ascla_jobs'));
            Store::delete('jobs',['id'=>$job['id']]);
        }finally{wp_clear_scheduled_hook('ascla_jobs_continue');if($old)wp_schedule_single_event($old,'ascla_jobs_continue');}
    }
    public function testStoredAbstentionRemainsAnAbstention():void
    {
        $r=Knowledge::answer('lifecycleausencia'.bin2hex(random_bytes(16)));self::assertSame([],$r['sources']);self::assertSame($r['answer'],Knowledge::storedAnswer($r)['answer']);
    }
}
