<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Settings,Knowledge,Administration};
use ASCLA\Core\Integrations\{RealAIProvider,Secrets};
use ASCLA\Core\Repositories\Store;

final class AdminGeminiTest extends TestCase
{
    private array $users=[],$posts=[],$terms=[],$settings=[];private mixed $secret;private $http;
    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->secret=get_option('ascla_secret_ai_key',false);
        foreach(['administrator','ascla_moderator','ascla_member'] as $role) {
            $login='gemini_admin_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);Settings::save(['ai_mode'=>'real','ai_model'=>'gemini-2.5-flash','ai_key'=>'fixture-Gemini-key-never-send','moderate_comments'=>true]);
    }
    protected function tearDown(): void
    {
        if($this->http)remove_filter('pre_http_request',$this->http,10);
        wp_set_current_user($this->users[0]);foreach($this->posts as $id)wp_delete_post($id,true);
        foreach($this->users as $id){foreach(['notifications','jobs','relations'] as $t)Store::delete($t,['user_id'=>$id]);wp_delete_user($id);}foreach($this->terms as $id)wp_delete_term($id,'ascla_interest');
        if($this->secret===false)delete_option('ascla_secret_ai_key');else update_option('ascla_secret_ai_key',$this->secret,false);
        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function api(string $method,string $path,array $params=[]): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$path);foreach($params as $k=>$v)$r->set_param($k,$v);return rest_do_request($r);
    }
    private function post(string $type,array $extra=[]): array
    {
        $p=Content::save($type,$extra+['title'=>'Fixture '.bin2hex(random_bytes(4)),'body'=>'Contenido de prueba.','status'=>'publish','meta'=>['chatham'=>false]]);$this->posts[]=$p['id'];return $p;
    }
    private function mock(callable $fn): void {$this->http=$fn;add_filter('pre_http_request',$fn,10,3);}
    private static function response(array $value): array {return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode($value)]]],'finishReason'=>'STOP']]])];}
    public function testForosPublishAndRepliesAreImmediateForAllThreeRoles(): void
    {
        $forum=$this->post('forum');
        foreach($this->users as $id) {
            wp_set_current_user($id);$topic=$this->post('topic',['status'=>'pending','parent'=>$forum['id']]);
            self::assertSame('publish',$topic['status']);self::assertSame('publish',Content::comment($topic['id'],'Respuesta sin aprobación.')['status']);
        }
    }
    public function testGalleryAndKnowledgeRequireAdministratorEvenWhenIdsAreManipulated(): void
    {
        foreach(['gallery','resource'] as $type) {
            wp_set_current_user($this->users[0]);$post=$this->post($type);self::assertSame('publish',$post['status']);
            foreach(array_slice($this->users,1) as $id) {
                wp_set_current_user($id);Content::comment($post['id'],'Comentario sin alterar la publicación.');self::assertSame('publish',get_post_status($post['id']));
                self::assertSame(403,$this->api('POST','content/'.$type,['title'=>'No permitido','body'=>'Texto','status'=>'publish'])->get_status());
                self::assertSame(403,$this->api('POST','items/'.$post['id'].'/moderate',['decision'=>'approve','reason'=>'Intento no autorizado'])->get_status());
                $native=wp_insert_post(['post_type'=>'ascla_'.$type,'post_title'=>'Native fixture','post_content'=>'Texto','post_status'=>'publish']);$this->posts[]=$native;
                self::assertSame('pending',get_post_status($native));
                if($type==='resource')self::assertSame(403,$this->api('POST','jobs',['kind'=>'multimedia','resource_id'=>$post['id']])->get_status());
            }
        }
    }
    public function testContactButtonsPersistDistinctStateHistoryAndFilters(): void
    {
        wp_set_current_user($this->users[2]);$p=$this->post('contact');
        self::assertSame(403,$this->api('POST','admin/contact/'.$p['id'],['status'=>'closed'])->get_status());
        wp_set_current_user($this->users[1]);
        foreach(['progress','closed','open'] as $state) {
            $r=$this->api('POST','admin/contact/'.$p['id'],['status'=>$state]);self::assertSame(200,$r->get_status());self::assertSame($state,$r->get_data()['meta']['request_status']);
            self::assertSame($state,get_post_meta($p['id'],'_ascla_request_status',true));
            $list=Administration::contacts(['q'=>$p['title'],'state'=>$state]);self::assertSame([$p['id']],array_column($list['items'],'id'));
        }
        Administration::contactStatus($p['id'],'open');self::assertCount(3,get_post_meta($p['id'],'_ascla',true)['request_history']);
        self::assertSame(400,$this->api('POST','admin/contact/'.$p['id'],['status'=>'invented'])->get_status());
    }
    public function testUsersAreAdminOnlyAndSuspensionCannotTargetSelfOrAdministrator(): void
    {
        $member=get_userdata($this->users[2]);$list=Administration::users(['q'=>$member->user_login]);
        self::assertCount(1,$list['items']);self::assertSame($member->user_email,$list['items'][0]['email']);self::assertTrue($list['items'][0]['can_edit']);
        Administration::suspend($member->ID,true);self::assertCount(1,Administration::users(['q'=>$member->user_login,'state'=>'suspended'])['items']);
        Administration::suspend($member->ID,false);self::assertCount(1,Administration::users(['q'=>$member->user_login,'state'=>'active'])['items']);
        self::assertSame(400,$this->api('POST','admin/member/'.$this->users[0],['suspended'=>true])->get_status());
        foreach(array_slice($this->users,1) as $id) {wp_set_current_user($id);self::assertSame(403,$this->api('GET','admin/users')->get_status());}
    }
    public function testGeminiConnectionProbeUsesStoredKeyWithoutReturningIt(): void
    {
        $this->mock(static function($pre,$args,$url){
            self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',$url);
            self::assertSame('fixture-Gemini-key-never-send',$args['headers']['x-goog-api-key']);
            $payload=json_decode($args['body'],true);self::assertSame('probe',json_decode($payload['contents'][0]['parts'][0]['text'],true)['task']);
            self::assertStringNotContainsString('fixture-Gemini-key',$args['body']);return self::response(['ok'=>true]);
        });
        $r=$this->api('POST','ai/test');self::assertSame(200,$r->get_status());self::assertTrue($r->get_data()['ok']);self::assertStringNotContainsString('fixture-Gemini-key',wp_json_encode($r->get_data()));
        wp_set_current_user($this->users[1]);self::assertSame(403,$this->api('POST','ai/test')->get_status());
        wp_set_current_user($this->users[0]);self::assertStringNotContainsString('fixture-Gemini-key',get_option('ascla_secret_ai_key'));Settings::save(['clear_ai_key'=>true]);self::assertFalse(Settings::status()['has_ai_key']);
    }
    public function testFormsGeminiDiagnosticUsesSyntheticTextAndValidCatalogIds(): void
    {
        $created=wp_insert_term('Gemini Forms '.bin2hex(random_bytes(5)),'ascla_interest');self::assertFalse(is_wp_error($created));$term=(int)$created['term_id'];$this->terms[]=$term;
        $this->mock(static function($pre,$args,$url)use($term){
            self::assertStringContainsString('generativelanguage.googleapis.com',$url);$payload=json_decode($args['body'],true);$request=json_decode($payload['contents'][0]['parts'][0]['text'],true);
            self::assertSame('form_interests',$request['task']);self::assertSame(['text','catalog'],array_keys($request['context']));self::assertStringStartsWith('Me interesan ',$request['context']['text']);self::assertContains($term,array_column($request['context']['catalog'],'id'));
            self::assertStringNotContainsString('@example.invalid',$args['body']);self::assertStringNotContainsString('fixture-Gemini-key-never-send',$args['body']);return self::response(['intereses'=>[$term],'confianza'=>'alta']);
        });
        $r=$this->api('POST','admin/interest-imports/ai-test');self::assertSame(200,$r->get_status());$data=$r->get_data();self::assertTrue($data['ok']);self::assertSame('alta',$data['confidence']);self::assertSame($term,$data['topics'][0]['id']);self::assertSame(1,$data['selected_count']);self::assertStringNotContainsString('fixture-Gemini-key',wp_json_encode($data));
        wp_set_current_user($this->users[1]);self::assertSame(403,$this->api('POST','admin/interest-imports/ai-test')->get_status());
    }
    public function testModelValidationBlocksPathInjectionAndOldOpenAIModel(): void
    {
        foreach(['gpt-4.1','../models/evil','gemini-x?key=secret','https://example.invalid/model'] as $value) {
            try{RealAIProvider::model($value);self::fail('Accepted invalid model');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(400,$e->getCode());}
        }
        self::assertSame('gemini-2.5-flash',RealAIProvider::model('models/gemini-2.5-flash'));
    }
    public function testPartialSearchRanksAndProvidesSixRelevantSourcesWithoutHalfWordThreshold(): void
    {
        $token='gobernx'.bin2hex(random_bytes(5));$ids=[];
        foreach(range(1,7) as $i)$ids[]=$this->post('resource',['title'=>$token.'anza '.$i,'body'=>'La evaluación requiere seguimiento.'])['id'];
        $unrelated=$this->post('resource',['title'=>'Contenido sin relación','body'=>'Historia del ajedrez.'])['id'];$called=false;
        $this->mock(static function($pre,$args)use(&$called,$ids,$unrelated){
            $called=true;$context=json_decode(json_decode($args['body'],true)['contents'][0]['parts'][0]['text'],true)['context'];$sources=$context['sources'];
            self::assertCount(6,$sources);self::assertNotContains($unrelated,array_column($sources,'id'));self::assertSame([],array_diff(array_column($sources,'id'),$ids));
            return self::response(['answer'=>'La supervisión se fortalece al evaluar y dar seguimiento a los acuerdos.','source_ids'=>[$sources[0]['id'],$sources[1]['id'],99999999]]);
        });
        $result=Knowledge::answer('Explícame '.$token.' planificaciónz sostenibilidadz legislaciónz procedimientosz decisionesz');
        self::assertTrue($called);self::assertSame('La supervisión se fortalece al evaluar y dar seguimiento a los acuerdos.',$result['answer']);self::assertCount(2,$result['sources']);self::assertSame(1,$result['grounding']['ignored_references']);
        self::assertSame($result['answer'],Knowledge::storedAnswer($result)['answer']);
    }
    public function testNoRelevantKnowledgeAbstainsWithoutCallingGemini(): void
    {
        $this->mock(static function(){self::fail('Gemini must not be called without evidence');});
        $result=Knowledge::answer('ausenciaz'.bin2hex(random_bytes(12)));self::assertSame([],$result['sources']);self::assertStringContainsString('No existe suficiente',$result['answer']);
    }
    public function testTransportErrorsDoNotEchoKeyOrUpstreamResponse(): void
    {
        $this->mock(static fn()=>['response'=>['code'=>403],'headers'=>[],'body'=>'fixture-Gemini-key-never-send sensitive payload']);
        $r=$this->api('POST','ai/test');self::assertSame(502,$r->get_status());self::assertStringNotContainsString('fixture-Gemini-key',wp_json_encode($r->get_data()));
    }
}
