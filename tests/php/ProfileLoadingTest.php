<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{Profiles,Matching,Settings,Messaging};
use ASCLA\Core\Integrations\Secrets;
use ASCLA\Core\Repositories\{Store,NetworkingCache};

final class ProfileLoadingTest extends TestCase
{
    private array $users=[],$settings=[];
    private string $secret='';
    private int $calls=0;
    private $http;
    private $response;

    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->secret=Secrets::get('ai_key');
        foreach(['administrator','ascla_member'] as $i=>$role){
            $id=wp_insert_user(['user_login'=>'profile_loading_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>$role]);
            self::assertIsInt($id);$this->users[]=$id;wp_set_current_user($id);
            Profiles::save(['first_name'=>'Prueba','last_name'=>'Perfil '.$i,'position'=>'Directora','bio'=>'Biografía original','experience'=>'Experiencia original','networking'=>true,'directory'=>true,'hidden'=>[]]);
        }
        wp_set_current_user($this->users[0]);
        Settings::save(['ai_provider'=>'gemini','ai_model'=>'gemini-cache-'.bin2hex(random_bytes(5)),'ai_key'=>'fake-local-test-key']);
        $this->response=['explanation'=>'Explicación válida del proveedor.','text'=>'Hola, conversemos sobre nuestros intereses.','conversation_proposal'=>'¿Qué experiencia quieres compartir?'];
        $this->http=function($pre,$args,$url){
            if(!str_starts_with($url,'https://generativelanguage.googleapis.com/')){return $pre;}
            $this->calls++;
            self::assertStringNotContainsString('profileFingerprint',$args['body']);
            if($this->response instanceof WP_Error){return $this->response;}
            if(isset($this->response['response'])){return $this->response;}
            return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode($this->response)]]],'finishReason'=>'STOP']]])];
        };
        add_filter('pre_http_request',$this->http,10,3);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http,10);
        foreach($this->users as $id){foreach(['notifications','jobs','relations'] as $table){Store::delete($table,['user_id'=>$id]);}wp_delete_user($id);}
        Secrets::remove('ai_key');if($this->secret!==''){Secrets::set('ai_key',$this->secret);}
        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function match(): array {return Matching::between(...$this->users);}
    private function edit(array $input,int $index=1): void
    {
        wp_set_current_user($this->users[$index]);Profiles::save($input);wp_set_current_user($this->users[0]);
    }
    public function testRestCanLoadProfileAndDeterministicAffinityWithoutCallingAI(): void
    {
        $profile=rest_do_request(new WP_REST_Request('GET','/ascla/v1/profiles/'.$this->users[1]));
        self::assertSame(200,$profile->get_status());self::assertSame('Biografía original',$profile->get_data()['bio']);
        $request=new WP_REST_Request('GET','/ascla/v1/matching/'.$this->users[1]);$request->set_param('explain','0');
        $plain=rest_do_request($request);self::assertSame(200,$plain->get_status());self::assertArrayNotHasKey('mode',$plain->get_data());self::assertSame(0,$this->calls);
        $full=rest_do_request(new WP_REST_Request('GET','/ascla/v1/matching/'.$this->users[1]));
        self::assertSame(200,$full->get_status());self::assertSame($plain->get_data()['score'],$full->get_data()['score']);self::assertSame(1,$this->calls);
    }
    public function testValidPairProsePersistsAcrossVisitsAndTransientEviction(): void
    {
        $first=$this->match();self::assertFalse($first['fallback']);
        $entries=get_user_meta($this->users[0],'_ascla_network_ai',true);
        $key='matching:'.$this->users[1];self::assertCount(1,$entries);
        delete_transient($entries[$key]['hash']);
        $entries[$key]['updated_at']=time()-90*DAY_IN_SECONDS;update_user_meta($this->users[0],'_ascla_network_ai',$entries);
        wp_cache_delete($this->users[0],'user_meta');
        self::assertSame($first,$this->match());self::assertSame(1,$this->calls);
        $intro=Matching::intro($this->users[1]);self::assertFalse($intro['sent']);self::assertSame($intro,Matching::intro($this->users[1]));self::assertSame(2,$this->calls);
    }
    public function testOnlyRelevantProfileChangesAndProviderChangesRegenerateProse(): void
    {
        $this->match();
        $this->edit(['birth_date'=>'1980-02-03','email_notifications'=>['events'=>false]]);
        $this->match();self::assertSame(1,$this->calls,'Birthday and email choices do not affect matching prose.');
        foreach([['bio'=>'Otra biografía'],['experience'=>'Otra experiencia'],['position'=>'Consultora'],['hidden'=>['position']],['interests'=>[Profiles::catalogs()['interest'][0]['id']]]] as $input){
            $before=$this->calls;$this->edit($input);$this->match();self::assertSame($before+1,$this->calls);
        }
        $before=$this->calls;Settings::save(['ai_model'=>'gemini-another-test-model']);$this->match();self::assertSame($before+1,$this->calls);
        $before=$this->calls;$this->edit(['bio'=>'Cambio del visitante'],0);$this->match();self::assertSame($before+1,$this->calls);
    }
    public function testCachedProseNeverBypassesNetworkingPrivacyOrBlocks(): void
    {
        $this->match();
        foreach([['networking'=>false],['directory'=>false]] as $input){
            $this->edit($input);
            $response=rest_do_request(new WP_REST_Request('GET','/ascla/v1/matching/'.$this->users[1]));self::assertSame(400,$response->get_status());self::assertSame(1,$this->calls);
            $this->edit(['networking'=>true,'directory'=>true]);
        }
        Store::insert('relations',['user_id'=>$this->users[0],'target_id'=>$this->users[1],'kind'=>'block','created_at'=>current_time('mysql',true)]);
        $response=rest_do_request(new WP_REST_Request('GET','/ascla/v1/matching/'.$this->users[1]));self::assertSame(400,$response->get_status());self::assertSame(1,$this->calls);
    }
    public static function failures(): array
    {
        return [
            'timeout'=>['timeout'],
            'quota'=>[['response'=>['code'=>429],'headers'=>[],'body'=>'{"error":{"message":"Quota exhausted"}}']],
            'invalid JSON'=>[['response'=>['code'=>200],'headers'=>[],'body'=>'broken-json']],
            'wrong shape'=>[['explanation'=>['bad'],'conversation_proposal'=>17]],
            'empty prose'=>[['explanation'=>' ','conversation_proposal'=>' ']],
        ];
    }
    #[DataProvider('failures')]
    public function testProviderFailureUsesShortFallbackAndKeepsProfileAvailable(mixed $response): void
    {
        $this->response=$response==='timeout'?new WP_Error('http_request_failed','Timeout'):$response;
        $first=$this->match();self::assertTrue($first['fallback']);self::assertNotEmpty($first['explanation']);
        self::assertSame($first,$this->match());self::assertSame(1,$this->calls);
        self::assertEmpty(get_user_meta($this->users[0],'_ascla_network_ai',true));
        self::assertSame('Biografía original',Profiles::visible($this->users[1])['bio']);
    }
    public function testLocalRateLimitFallsBackWithoutCallingProvider(): void
    {
        $key='ascla_'.hash('sha256','rate:'.$this->users[0].':network-ai:'.intdiv(time(),300));
        set_transient($key,20,300);
        try{self::assertTrue($this->match()['fallback']);self::assertSame(0,$this->calls);}finally{delete_transient($key);}
    }
    public function testCacheIsBoundedAndKeepsTasksAndTargetsSeparate(): void
    {
        foreach(range(1,25) as $target){NetworkingCache::put($this->users[0],$target,'matching','hash-'.$target,['explanation'=>'result-'.$target]);}
        self::assertCount(20,get_user_meta($this->users[0],'_ascla_network_ai',true));
        self::assertNull(NetworkingCache::get($this->users[0],1,'matching','hash-1'));
        self::assertNull(NetworkingCache::get($this->users[0],25,'intro','hash-25'));
        self::assertNull(NetworkingCache::get($this->users[0],25,'matching','changed'));
        self::assertSame(['explanation'=>'result-25'],NetworkingCache::get($this->users[0],25,'matching','hash-25'));
    }
    public function testConcurrentMissDoesNotCallProviderOrPersistBusyFallback(): void
    {
        global $wpdb;
        $this->match();$entries=get_user_meta($this->users[0],'_ascla_network_ai',true);$hash=$entries['matching:'.$this->users[1]]['hash'];
        delete_user_meta($this->users[0],'_ascla_network_ai');
        $name='ascla_'.substr(hash('sha256',$wpdb->prefix.$hash),0,56);
        $other=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
        self::assertSame('1',$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)',$name)));
        try{self::assertTrue($this->match()['fallback']);self::assertSame(1,$this->calls);self::assertEmpty(get_user_meta($this->users[0],'_ascla_network_ai',true));}
        finally{$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)',$name));$other->close();}
        self::assertFalse($this->match()['fallback']);self::assertSame(2,$this->calls);
    }
}