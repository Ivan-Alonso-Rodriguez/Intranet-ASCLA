<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Events,Settings,MicroPlanning,Knowledge,GalleryEvidence};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Integrations\{Secrets,OpenAIProvider,RealAIProvider,DeepSeekProvider};

final class EncounterGalleryChangesTest extends TestCase
{
    private array $users=[],$posts=[],$files=[],$settings=[],$secrets=[];
    protected function setUp(): void
    {
        $this->settings=(array)get_option('ascla_settings',[]);
        foreach(['administrator','ascla_executive','ascla_member','ascla_member','ascla_member'] as $role){
            $login='encounter_gallery_'.bin2hex(random_bytes(5));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
        foreach(['ai_key','openai_key','deepseek_key'] as $key){$this->secrets[$key]=Secrets::get($key);Secrets::set($key,'synthetic-gallery-test-key');}
        Settings::save(['ai_provider'=>'mock','profile_completion_extra'=>[]]);
    }
    protected function tearDown(): void
    {
        foreach($this->posts as $id){Store::delete('registrations',['event_id'=>$id]);wp_delete_post($id,true);}
        foreach($this->files as $id)Store::delete('media',['id'=>$id]);
        foreach($this->users as $id){foreach(['notifications','jobs','audit'] as $table)Store::delete($table,[$table==='audit'?'actor_id':'user_id'=>$id]);wp_delete_user($id);}
        foreach($this->secrets as $key=>$value){Secrets::remove($key);if($value!=='')Secrets::set($key,$value);}
        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function deny(callable $fn,int $code): void
    {
        try{$fn();self::fail('Expected rejection');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame($code,$e->getCode(),$e->getMessage());}
    }
    private function gallery(string $title,string $status='publish'): int
    {
        $image=imagecreatetruecolor(30,20);imagefill($image,0,0,imagecolorallocate($image,20,80,150));ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
        $file=Store::insert('media',['user_id'=>$this->users[0],'post_id'=>-1,'name'=>'fotografia.png','mime'=>'image/png','bytes'=>$bytes,'created_at'=>current_time('mysql',true)]);$this->files[]=$file;
        $id=Content::save('gallery',['title'=>$title,'body'=>'Fotografías del encuentro sobre gobierno corporativo.','status'=>$status,'meta'=>['media_ids'=>[$file],'chatham'=>false]])['id'];$this->posts[]=$id;return $id;
    }
    public function testGroupControlsValidatePermissionsAndPreserveUnrelatedSettings(): void
    {
        wp_set_current_user($this->users[1]);$before=Settings::get();
        $result=MicroPlanning::saveGroupSize(['micro_min'=>3,'micro_capacity'=>8,'ai_provider'=>'openai','micro_limit'=>1]);
        self::assertSame(['micro_min'=>3,'micro_capacity'=>8],$result);
        self::assertSame($before['ai_provider'],Settings::get()['ai_provider']);self::assertSame($before['micro_limit'],Settings::get()['micro_limit']);
        $this->deny(fn()=>MicroPlanning::saveGroupSize(['micro_min'=>9,'micro_capacity'=>8]),400);
        self::assertSame(3,Settings::get()['micro_min']);
        wp_set_current_user($this->users[2]);$this->deny(fn()=>MicroPlanning::saveGroupSize(['micro_min'=>2,'micro_capacity'=>4]),403);
    }
    public function testInvitationsUseEventCapacityAndUnlimitedEventsKeepTheirMeaning(): void
    {
        $id=Content::save('event',['title'=>'Cupos de invitaciones','body'=>'Un encuentro de prueba.','status'=>'publish','meta'=>['start'=>gmdate('c',time()+DAY_IN_SECONDS),'end'=>gmdate('c',time()+DAY_IN_SECONDS+3600),'modality'=>'Virtual','url'=>'https://example.invalid/meeting','capacity'=>2]])['id'];$this->posts[]=$id;
        $members=array_slice($this->users,2);
        $this->deny(fn()=>Events::invite($id,$members),400);
        self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
        self::assertSame(2,Events::invite($id,array_slice($members,0,2))['sent']);
        self::assertSame(2,Events::detail($id)['remaining']);
        $meta=get_post_meta($id,'_ascla',true);$meta['capacity']=0;update_post_meta($id,'_ascla',$meta);
        self::assertSame(1,Events::invite($id,$members)['sent']);
    }
    public function testGalleryEvidenceRespectsPublicationAndFileBindingsAndInvalidatesOldAnswers(): void
    {
        $id=$this->gallery('Galería Horizonte');$private=$this->gallery('Galería Reservada','draft');
        wp_set_current_user($this->users[2]);
        $context=GalleryEvidence::context('Analiza la galería Horizonte',[]);
        self::assertSame([$id],array_column($context['sources'],'id'));
        $images=GalleryEvidence::images($context['sources']);self::assertCount(1,$images);self::assertSame($id,$images[0]['source_id']);
        self::assertSame([],GalleryEvidence::context('Galería Reservada',[])['sources']);
        $result=Knowledge::answer('Describe la galería Horizonte');
        self::assertStringContainsString('modo demo',$result['answer']);self::assertSame([$id],array_column($result['sources'],'id'));
        self::assertArrayHasKey($id,$result['grounding']['gallery_digests']);
        $file=$context['sources'][0]['files'][0]['id'];Store::update('media',['post_id'=>$private],['id'=>$file]);
        self::assertSame([],GalleryEvidence::images($context['sources']));
        self::assertSame([],Knowledge::storedAnswer($result)['sources']);
    }
    public function testVisionProvidersReceiveImagesAsMultimodalPartsWithoutPersistingBinaryEvidence(): void
    {
        $id=$this->gallery('Galería Prisma');$captured=[];
        $http=static function($pre,$args,$url)use(&$captured,$id){
            $captured[$url]=json_decode($args['body'],true);
            $answer=wp_json_encode(['answer'=>'La foto muestra una superficie azul.','source_ids'=>[$id]]);
            $body=str_contains($url,'googleapis')?['candidates'=>[['content'=>['parts'=>[['text'=>$answer]]]]]]:(str_contains($url,'openai.com')?['output_text'=>$answer]:['choices'=>[['message'=>['content'=>$answer],'finish_reason'=>'stop']]]);
            return ['headers'=>[],'response'=>['code'=>200],'body'=>wp_json_encode($body)];
        };
        add_filter('pre_http_request',$http,10,3);
        try{
            foreach(['gemini','openai','deepseek'] as $provider){
                Settings::save(['ai_provider'=>$provider,'ai_model'=>'gemini-2.5-flash','deepseek_model'=>'deepseek-flash']);
                $result=Knowledge::answer('Analiza las fotos de la galería Prisma');
                self::assertStringContainsString('superficie azul',$result['answer']);
                self::assertStringNotContainsString('base64',wp_json_encode($result));
            }
        }finally{remove_filter('pre_http_request',$http,10);}
        self::assertCount(3,$captured);
        $open=$captured['https://api.openai.com/v1/responses'];
        self::assertSame('input_image',$open['input'][0]['content'][2]['type']);self::assertFalse($open['store']);
        $gemini=current(array_filter($captured,static fn($body)=>isset($body['contents'])));
        self::assertSame('image/jpeg',$gemini['contents'][0]['parts'][2]['inline_data']['mime_type']);
        $deep=$captured['https://api.deepseek.com/chat/completions'];
        self::assertSame('image_url',$deep['messages'][1]['content'][2]['type']);
        self::assertStringNotContainsString('gallery_images',$open['input'][0]['content'][0]['text']);
    }
    public function testOlderGalleryIsRankedBeforeTheLimitAndAmbiguousRequestsAskForAChoice(): void
    {
        $old=$this->gallery('Galería Órbita');
        wp_update_post(['ID'=>$old,'post_date'=>'2000-01-01 00:00:00','post_date_gmt'=>'2000-01-01 00:00:00']);
        for($i=0;$i<81;$i++){$this->posts[]=wp_insert_post(['post_type'=>'ascla_gallery','post_status'=>'publish','post_title'=>'Otra colección '.$i,'post_content'=>'Contenido de otra colección.','post_author'=>$this->users[0]]);}
        wp_set_current_user($this->users[2]);
        self::assertSame([$old],array_column(GalleryEvidence::context('Analiza la galería Órbita',[])['sources'],'id'));
        wp_set_current_user($this->users[0]);$other=$this->gallery('Galería Órbita alternativa');
        wp_set_current_user($this->users[2]);$context=GalleryEvidence::context('Galerías Órbita',[]);
        self::assertSame('choose',$context['request']);
        $ids=array_column($context['sources'],'id');self::assertContains($old,$ids);self::assertContains($other,$ids);
        $follow=GalleryEvidence::context('La primera galería', [['source_ids'=>$ids]]);
        self::assertSame([$ids[0]],array_column($follow['sources'],'id'));
    }
}
