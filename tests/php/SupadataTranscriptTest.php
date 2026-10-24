<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Integrations\{SupadataVideoProvider,Secrets,IntegrationException,AIProviderInterface,MockAIProvider};
use ASCLA\Core\Services\{Content,Settings,Knowledge,VideoTranscripts};
use ASCLA\Core\Jobs\{Queue,DeferredJob};
use ASCLA\Core\Repositories\Store;

final class SupadataTranscriptTest extends TestCase
{
    private array $saved=[],$users=[],$posts=[],$requests=[],$replies=[],$before=[],$jobs=[];
    private string $key;
    protected function setUp(): void
    {
        global $wpdb;
        foreach (['ASCLA_SUPADATA_KEY','ASCLA_EXTERNAL_SECRETS_ONLY'] as $name) { $this->saved[$name]=getenv($name); }
        $this->saved['settings']=get_option('ascla_settings');$this->saved['secret']=get_option('ascla_secret_supadata_key',null);
        $this->before=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%ascla_sd_%'");
        $this->jobs=Store::rows('jobs','1=1',[],'ORDER BY id');
        foreach ($this->jobs as $row) { if ($row['status']==='pending') { Store::update('jobs',['locked_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS)],['id'=>$row['id']]); } }
        $this->key='fixture-'.bin2hex(random_bytes(16));putenv('ASCLA_SUPADATA_KEY='.$this->key);putenv('ASCLA_EXTERNAL_SECRETS_ONLY=0');
        foreach (['administrator','ascla_member','ascla_executive'] as $role) { $this->users[]=wp_insert_user(['user_login'=>'sd_'.bin2hex(random_bytes(7)),'user_pass'=>wp_generate_password(30),'role'=>$role]); }
        wp_set_current_user($this->users[0]);Settings::save(['youtube_mode'=>'supadata','supadata_generate'=>false,'supadata_language'=>'es','ai_provider'=>'mock']);
        add_filter('pre_http_request',[$this,'http'],10,3);
    }
    protected function tearDown(): void
    {
        global $wpdb;remove_filter('pre_http_request',[$this,'http'],10);delete_transient('ascla_yt_public_'.hash('sha256','ASCLASDTEST'));
        foreach (Store::rows('jobs','1=1',[],'ORDER BY id') as $row) { if (!in_array($row['id'],array_column($this->jobs,'id'),true)) { Store::delete('jobs',['id'=>$row['id']]); } }
        foreach ($this->jobs as $row) { Store::update('jobs',['locked_at'=>$row['locked_at']],['id'=>$row['id']]); }
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->users as $id) { foreach (['jobs','notifications'] as $table) { Store::delete($table,['user_id'=>$id]); } Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id); }
        $keys=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%ascla_sd_%'");
        foreach (array_diff($keys,$this->before) as $name) { delete_option($name); }
        update_option('ascla_settings',$this->saved['settings'],false);
        if ($this->saved['secret']===null) { delete_option('ascla_secret_supadata_key'); } else { update_option('ascla_secret_supadata_key',$this->saved['secret'],false); }
        foreach (['ASCLA_SUPADATA_KEY','ASCLA_EXTERNAL_SECRETS_ONLY'] as $name) { putenv($this->saved[$name]===false?$name:$name.'='.$this->saved[$name]); }
        wp_set_current_user(0);
    }
    public function http(mixed $pre,array $args,string $url): mixed
    {
        if (str_starts_with($url,'https://api.supadata.ai/')) {
            $this->requests[]=['url'=>$url,'args'=>$args];
            self::assertSame($this->key,$args['headers']['x-api-key']);self::assertSame(0,$args['redirection']);
            $reply=array_shift($this->replies);self::assertNotNull($reply,'Unexpected additional billable/poll request');return $reply;
        }
        if (str_contains($url,'youtube.com/')||str_contains($url,'googleapis.com/')) { return $this->reply(200,['lengthSeconds'=>'7500']); }
        return $pre;
    }
    private function reply(int $code,array $body,array $headers=[]): array { return ['response'=>['code'=>$code,'message'=>'Fixture'],'body'=>wp_json_encode($body),'headers'=>$headers,'cookies'=>[]]; }
    private function due(): void
    {
        global $wpdb;
        $names=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_ascla_sd_%'");
        foreach (array_diff($names,$this->before) as $name) {
            $key=substr($name,strlen('_transient_'));$state=get_transient($key);
            if (is_array($state)) { $state['until']=0;set_transient($key,$state,DAY_IN_SECONDS); }
            elseif (is_numeric($state)) { delete_transient($key); }
        }
        foreach ($this->users as $id) { foreach (Store::rows('jobs','user_id=%d AND status=%s',[$id,'pending']) as $row) { Store::update('jobs',['locked_at'=>null],['id'=>$row['id']]); } }
    }
    private function resource(string $video='ASCLASDTEST'): int
    {
        // Skip optional metadata network calls during source creation; the full generation path still exercises them.
        $id=wp_insert_post(['post_type'=>'ascla_resource','post_title'=>'Video de gobernanza','post_status'=>'publish','post_author'=>$this->users[0],'post_content'=>'Material de la comunidad.']);
        update_post_meta($id,'_ascla',['video_id'=>$video,'youtube_url'=>'https://www.youtube.com/watch?v='.$video,'resource_type'=>'Video','chatham'=>false,'duration_seconds'=>7500]);$this->posts[]=$id;return $id;
    }
    private function api(string $route,array $body=[],string $method='POST'): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$route);$r->set_header('Content-Type','application/json');if($body) { $r->set_body(wp_json_encode($body)); }return rest_do_request($r);
    }
    public function testFullTwoHourTranscriptSurvivesStorageEditingAndExistingAiPipeline(): void
    {
        $content=[];for($i=0;$i<1500;$i++) { $content[]=['offset'=>$i*5000,'duration'=>5000,'text'=>'La junta supervisa los riesgos y las responsabilidades. '.str_repeat('Gobierno corporativo. ',5).($i===1499?'MARCADOR_FINAL_2_HORAS C:\\archivo':'')]; }
        $this->replies[]=$this->reply(200,['content'=>$content,'lang'=>'es']);$id=$this->resource();
        $result=VideoTranscripts::obtain($id);$meta=get_post_meta($id,'_ascla',true);
        self::assertGreaterThan(200000,$result['characters']);self::assertStringContainsString('02:04:55,000',$meta['transcript']);self::assertStringContainsString('MARCADOR_FINAL_2_HORAS C:\\archivo',$meta['transcript']);
        self::assertStringContainsString('mode=native',$this->requests[0]['url']);self::assertStringContainsString('text=false',$this->requests[0]['url']);self::assertStringNotContainsString($this->key,$this->requests[0]['url']);
        $full=$meta['transcript'];$saved=Content::save('resource',['title'=>'Video actualizado','body'=>'Revisión editorial.','meta'=>['transcript'=>$full]],$id);
        self::assertSame($full,$saved['meta']['transcript']);self::assertSame('supadata',$saved['meta']['transcript_status']);
        $ai=new class(implode(' ',array_map('trim',array_column($content,'text')))) implements AIProviderInterface {
            public function __construct(private string $speech) {}
            public function mode(): string { return 'test-full-source'; }
            public function generate(string $task,array $context): array {
                TestCase::assertSame(hash('sha256',$this->speech),hash('sha256',$context['transcript']),'Every spoken segment must reach the AI, in order, without timing metadata.');
                TestCase::assertStringNotContainsString('-->',$context['transcript']);
                TestCase::assertStringContainsString('MARCADOR_FINAL_2_HORAS C:\\archivo',$context['transcript']);
                TestCase::assertSame(7500,$context['duration_seconds']);
                return (new MockAIProvider())->generate($task,$context);
            }
        };
        Knowledge::multimedia($id,$ai);self::assertSame($full,get_post_meta($id,'_ascla',true)['transcript']);self::assertCount(1,$this->requests);
    }
    public function testCacheSharedBetweenResourcesAndConfigurationDoesNotUseGoogleOAuth(): void
    {
        $this->replies[]=$this->reply(200,['content'=>'Contenido público completo de gobierno corporativo y gestión de riesgos.','lang'=>'es']);
        VideoTranscripts::obtain($this->resource());$other=$this->resource();wp_set_current_user($this->users[2]);VideoTranscripts::obtain($other);
        self::assertCount(1,$this->requests);self::assertSame('supadata',get_post_meta($other,'_ascla',true)['transcript_status']);
        self::assertSame('',Secrets::get('google_youtube_'.$this->users[2]));
    }
    public function testAsyncPollingDoesNotResubmitAndDoesNotExhaustQueueAttempts(): void
    {
        Settings::save(['supadata_generate'=>true]);$id=$this->resource();
        $this->replies[]=$this->reply(202,['jobId'=>'job-long-123']);$this->replies[]=$this->reply(200,['status'=>'active']);$this->replies[]=$this->reply(200,['status'=>'completed','content'=>[['offset'=>7200000,'duration'=>10000,'text'=>'Conclusión final después de dos horas sobre gestión de riesgos.']],'lang'=>'es']);
        $job=Queue::enqueue('transcript',['resource_id'=>$id]);Queue::run();$row=Queue::get($job['id']);self::assertSame('pending',$row['status']);self::assertSame('0',(string)$row['attempts']);self::assertSame('pending',get_post_meta($id,'_ascla',true)['transcript_status']);
        Queue::run();self::assertCount(1,$this->requests);$this->due();Queue::run();self::assertSame('pending',Queue::get($job['id'])['status']);
        $this->due();Queue::run();self::assertSame('completed',Queue::get($job['id'])['status']);
        self::assertStringContainsString('mode=auto',$this->requests[0]['url']);self::assertStringEndsWith('/transcript/job-long-123',$this->requests[1]['url']);self::assertSame($this->requests[1]['url'],$this->requests[2]['url']);self::assertStringContainsString('02:00:00,000',get_post_meta($id,'_ascla',true)['transcript']);
    }
    public function testMultimediaQueueResumesAfterTranscriptWithoutRequiringAnotherClick(): void
    {
        $id=$this->resource();$this->replies[]=$this->reply(202,['jobId'=>'job-summary']);$this->replies[]=$this->reply(200,['status'=>'completed','content'=>str_repeat('La junta directiva supervisa el gobierno corporativo y la gestión de riesgos. ',8),'lang'=>'es']);
        $job=Queue::enqueue('multimedia',['resource_id'=>$id]);Queue::run();self::assertSame('pending',Queue::get($job['id'])['status']);$this->due();Queue::run();self::assertSame('completed',Queue::get($job['id'])['status']);self::assertTrue(get_post_meta($id,'_ascla',true)['ai_enriched']);
    }
    public function testRateLimitBackoffKeepsJobIdAndHonorsRetryAfter(): void
    {
        $this->replies[]=$this->reply(202,['jobId'=>'job-rate']);$this->replies[]=$this->reply(429,['details'=>'Request rate limit on current plan was exceeded.'],['retry-after'=>'120']);$this->replies[]=$this->reply(200,['status'=>'completed','content'=>'El cierre confirma las responsabilidades de la junta directiva.']);
        $provider=new SupadataVideoProvider();try{$provider->transcript('ASCLASDTEST');}catch(DeferredJob){}$this->due();
        try { $provider->transcript('ASCLASDTEST');self::fail(); } catch (DeferredJob $e) { self::assertSame(120,$e->delay); }
        try { $provider->transcript('ASCLASDTEST');self::fail(); } catch (DeferredJob) { self::assertCount(2,$this->requests); }
        $this->due();$provider->transcript('ASCLASDTEST');self::assertCount(3,$this->requests);self::assertStringEndsWith('/job-rate',$this->requests[2]['url']);
    }
    public function testNoCaptionsDoesNotAutomaticallyGenerateAndCachesNegativeResult(): void
    {
        $this->replies[]=$this->reply(206,['error'=>'transcript-unavailable']);$provider=new SupadataVideoProvider();
        for($i=0;$i<2;$i++){try{$provider->transcript('ASCLASDTEST');self::fail();}catch(IntegrationException $e){self::assertStringContainsString('no tiene subtítulos',$e->getMessage());}}
        self::assertCount(1,$this->requests);$this->due();Settings::save(['supadata_generate'=>true]);
        $this->replies[]=$this->reply(200,['content'=>'Contenido generado de la conferencia con responsabilidad y supervisión.']);$provider->transcript('ASCLASDTEST');self::assertStringContainsString('mode=auto',$this->requests[1]['url']);
    }
    public function testErrorsAreSafeAndNeverStorePartialContent(): void
    {
        $provider=new SupadataVideoProvider();
        foreach ([401,402,403,404,400,429] as $i=>$status) {
            $this->due();$this->replies[]=$this->reply($status,['details'=>$status===429?'Plan usage limit was exceeded.':$this->key,'message'=>$this->key]);
            try { $provider->transcript('videoERR'.str_pad((string)$i,3,'0',STR_PAD_LEFT));self::fail(); } catch (IntegrationException $e) { self::assertStringNotContainsString($this->key,$e->getMessage()); }
        }
        $this->due();$this->replies[]=$this->reply(200,['content'=>[['text'=>'Segmento válido','offset'=>0,'duration'=>1000],['text'=>'Falta el tiempo']]]);
        $id=$this->resource();try{VideoTranscripts::obtain($id);self::fail();}catch(IntegrationException $e){self::assertStringContainsString('parcial',$e->getMessage());}self::assertArrayNotHasKey('transcript',get_post_meta($id,'_ascla',true));
    }
    public function testTimeoutDoesNotRetryABillableRequestAutomatically(): void
    {
        $this->replies[]=new WP_Error('http_request_failed','timeout '.$this->key);$provider=new SupadataVideoProvider();
        for($i=0;$i<2;$i++){try{$provider->transcript('ASCLASDTEST');self::fail();}catch(IntegrationException $e){self::assertStringContainsString('consumo',$e->getMessage());self::assertStringNotContainsString($this->key,$e->getMessage());}}
        self::assertCount(1,$this->requests);
    }
    public function testManualTranscriptWinsAndVideoChangeInvalidatesAutomaticSource(): void
    {
        $id=$this->resource();$meta=get_post_meta($id,'_ascla',true);$meta['transcript']='Texto manual completo de las responsabilidades de la junta.';update_post_meta($id,'_ascla',$meta);VideoTranscripts::obtain($id);self::assertCount(0,$this->requests);
        unset($meta['transcript']);update_post_meta($id,'_ascla',$meta);$this->replies[]=$this->reply(200,['content'=>'Subtítulos públicos completos sobre la gestión de riesgos.']);VideoTranscripts::obtain($id);
        $saved=Content::save('resource',['title'=>'Otro video','body'=>'Contenido del nuevo video.','meta'=>['youtube_url'=>'https://youtu.be/zyxwvutsrqp']],$id);self::assertArrayNotHasKey('transcript',$saved['meta']);
    }
    public function testRolePrivacyAndApiKeyEncryptionFromWordPress(): void
    {
        putenv('ASCLA_SUPADATA_KEY');putenv('ASCLA_EXTERNAL_SECRETS_ONLY');delete_option('ascla_secret_supadata_key');
        $response=$this->api('settings',['supadata_key'=>$this->key,'youtube_mode'=>'supadata']);self::assertSame(200,$response->get_status());self::assertTrue($response->get_data()['has_supadata_key']);self::assertSame($this->key,Secrets::get('supadata_key'));
        self::assertStringNotContainsString($this->key,get_option('ascla_secret_supadata_key'));self::assertStringNotContainsString($this->key,wp_json_encode($response->get_data()));
        $this->replies[]=$this->reply(200,['content'=>'Texto reservado a la edición para preparar notas de conocimiento.']);$id=$this->resource();VideoTranscripts::obtain($id);
        wp_set_current_user($this->users[1]);self::assertArrayNotHasKey('transcript',Content::serialize(Content::get($id))['meta']);self::assertSame(403,$this->api('jobs',['kind'=>'transcript','resource_id'=>$id])->get_status());self::assertSame(403,$this->api('settings',[], 'GET')->get_status());
        wp_set_current_user($this->users[0]);putenv('ASCLA_EXTERNAL_SECRETS_ONLY=1');self::assertSame(409,$this->api('settings',['supadata_key'=>'replacement'])->get_status());
    }
    public function testLateResponseCannotOverwriteManualEditOrDifferentVideo(): void
    {
        $id=$this->resource();$change=static function($pre,$args,$url)use($id){if(str_contains($url,'api.supadata.ai')){$meta=get_post_meta($id,'_ascla',true);$meta['transcript']='Edición manual que debe prevalecer mientras se obtiene el video.';update_post_meta($id,'_ascla',$meta);}return $pre;};add_filter('pre_http_request',$change,9,3);
        $this->replies[]=$this->reply(200,['content'=>'Subtítulos devueltos por la consulta anterior.']);try{VideoTranscripts::obtain($id);}finally{remove_filter('pre_http_request',$change,9);}self::assertStringStartsWith('Edición manual',get_post_meta($id,'_ascla',true)['transcript']);
    }
    public function testFailedAsyncJobRequiresExplicitRetryBeforeNewPaidRequest(): void
    {
        $id=$this->resource();$this->replies[]=$this->reply(202,['jobId'=>'job-failed']);$this->replies[]=$this->reply(200,['status'=>'failed','error'=>['message'=>$this->key]]);
        $job=Queue::enqueue('transcript',['resource_id'=>$id]);Queue::run();$this->due();Queue::run();$row=Queue::get($job['id']);self::assertSame('error',$row['status']);self::assertStringNotContainsString($this->key,$row['error']);self::assertArrayNotHasKey('transcript',get_post_meta($id,'_ascla',true));
        Queue::run();self::assertCount(2,$this->requests);Queue::retry($job['id']);$this->due();$this->replies[]=$this->reply(200,['content'=>'Resultado completo de una solicitud nueva iniciada explícitamente.']);Queue::run();self::assertSame('completed',Queue::get($job['id'])['status']);self::assertStringContainsString('url=',$this->requests[2]['url']);
    }
    public function testOversizedTranscriptIsRejectedInsteadOfSilentlyTruncated(): void
    {
        $this->replies[]=$this->reply(200,['content'=>str_repeat('a',VideoTranscripts::MAX_CHARACTERS+1)]);$id=$this->resource();
        try{VideoTranscripts::obtain($id);self::fail();}catch(IntegrationException $e){self::assertStringContainsString('parcial',$e->getMessage());}self::assertArrayNotHasKey('transcript',get_post_meta($id,'_ascla',true));
    }
    public function testRetriesForTemporaryOutageAreBounded(): void
    {
        $provider=new SupadataVideoProvider();
        for($i=0;$i<8;$i++){$this->due();$this->replies[]=$this->reply(503,['message'=>$this->key]);try{$provider->transcript('ASCLASDTEST');self::fail();}catch(DeferredJob $e){self::assertLessThan(7,$i);}catch(IntegrationException $e){self::assertSame(7,$i);}}
        self::assertCount(8,$this->requests);
    }
    public function testPermissionIsRecheckedWhileRemoteJobIsPending(): void
    {
        $id=$this->resource();$this->replies[]=$this->reply(202,['jobId'=>'job-permission']);$job=Queue::enqueue('transcript',['resource_id'=>$id]);Queue::run();
        (new WP_User($this->users[0]))->set_role('ascla_member');wp_set_current_user($this->users[0]);$this->due();Queue::run();self::assertSame('error',Queue::get($job['id'])['status']);self::assertCount(1,$this->requests);self::assertArrayNotHasKey('transcript',get_post_meta($id,'_ascla',true));
    }
    public function testSourceTextRemainsDataIncludingLiteralMarkupAndBackslashes(): void
    {
        $text='Explicación completa: <script>ejemplo de código</script> y C:\\archivo de la sesión.';$this->replies[]=$this->reply(200,['content'=>$text]);$id=$this->resource();VideoTranscripts::obtain($id);self::assertSame($text,get_post_meta($id,'_ascla',true)['transcript']);
        $saved=Content::save('resource',['title'=>'Revisión','body'=>'Texto del recurso.','meta'=>['transcript'=>$text]],$id);self::assertSame($text,$saved['meta']['transcript']);self::assertSame('supadata',$saved['meta']['transcript_status']);
    }
    public function testChangingPreferredLanguageKeepsTheExistingRemoteJob(): void
    {
        $this->replies[]=$this->reply(202,['jobId'=>'job-language']);$provider=new SupadataVideoProvider();try{$provider->transcript('ASCLASDTEST');}catch(DeferredJob){}
        Settings::save(['supadata_language'=>'en']);$this->due();$this->replies[]=$this->reply(200,['status'=>'completed','content'=>'El resultado completo del trabajo solicitado originalmente en español.','lang'=>'es']);$result=$provider->transcript('ASCLASDTEST');
        self::assertSame('es',$result['language']);self::assertCount(2,$this->requests);self::assertStringEndsWith('/job-language',$this->requests[1]['url']);
    }
}
