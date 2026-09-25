<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Integrations\{RealAIProvider,OpenAIProvider,DeepSeekProvider,YouTubeVideoProvider,GoogleOAuth,Secrets};
use ASCLA\Core\Services\{Settings,Content};
use ASCLA\Core\Repositories\Store;
final class ProvidersTest extends TestCase
{
    private int $user; private $http; private array $settings; private array $posts=[]; private array $users=[]; private array $savedSecrets=[];
    protected function setUp(): void
    {
        $this->settings=Settings::get();foreach(['ai_key','openai_key','deepseek_key','google_client_secret'] as $key)$this->savedSecrets[$key]=Secrets::get($key);$this->user=wp_insert_user(['user_login'=>'test_provider_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(30),'role'=>'administrator']);wp_set_current_user($this->user);
        Settings::save(['ai_mode'=>'real','ai_model'=>'gemini-test-model','ai_key'=>'fake-key-for-http-mock','google_client_id'=>'fake-client-id','google_client_secret'=>'fake-client-secret']);
    }
    protected function tearDown(): void
    {
        if($this->http)remove_filter('pre_http_request',$this->http,10);
        foreach($this->posts as $id){Store::delete('registrations',['event_id'=>$id]);wp_delete_post($id,true);}
        foreach($this->users as $id)wp_delete_user($id);
        foreach(['ai_key','openai_key','deepseek_key','google_client_secret','google_calendar_'.$this->user,'google_youtube_'.$this->user] as $key)Secrets::remove($key);
        foreach($this->savedSecrets as $key=>$value){if($value!=='')Secrets::set($key,$value);}
        wp_delete_user($this->user);update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function mock(callable $handler): void
    {
        $this->http=static fn($pre,$args,$url)=>$handler($args,$url);add_filter('pre_http_request',$this->http,10,3);
    }
    private static function response(mixed $body,int $code=200): array {return ['response'=>['code'=>$code],'headers'=>[],'body'=>is_string($body)?$body:wp_json_encode($body)];}
    public function testGeminiTransportUsesHeaderKeyConfiguredModelAndStructuredJSON(): void
    {
        $this->mock(static function($args,$url){
            self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent',$url);
            self::assertSame('fake-key-for-http-mock',$args['headers']['x-goog-api-key']);self::assertArrayNotHasKey('Authorization',$args['headers']);
            self::assertStringNotContainsString('fake-key',$url);$body=json_decode($args['body'],true);
            self::assertSame('application/json',$body['generationConfig']['responseMimeType']);
            self::assertStringContainsString(RealAIProvider::SYSTEM_PROMPT,$body['systemInstruction']['parts'][0]['text']);
            self::assertSame('answer',json_decode($body['contents'][0]['parts'][0]['text'],true)['task']);
            return self::response(['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode(['answer'=>'Respuesta de prueba','source_ids'=>[22]])]]],'finishReason'=>'STOP']]]);
        });
        $r=(new RealAIProvider())->generate('answer',['sources'=>[['id'=>22]]]);self::assertSame('Google Gemini · API real',$r['mode']);self::assertSame([22],$r['source_ids']);
    }
    public function testOpenAITransportUsesResponsesEndpointBearerKeyAndConfiguredModel(): void
    {
        Settings::save(['ai_provider'=>'openai','openai_model'=>'gpt-5.6-luna','openai_key'=>'sk-test-openai-never-send']);
        $this->mock(static function($args,$url){
            self::assertSame('https://api.openai.com/v1/responses',$url);
            self::assertSame('Bearer sk-test-openai-never-send',$args['headers']['Authorization']);
            self::assertArrayNotHasKey('x-goog-api-key',$args['headers']);
            $body=json_decode($args['body'],true);self::assertSame('gpt-5.6-luna',$body['model']);
            self::assertStringNotContainsString('sk-test-openai-never-send',$args['body']);
            self::assertSame('answer',json_decode($body['input'],true)['task']);
            return self::response(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>wp_json_encode(['answer'=>'Respuesta OpenAI','source_ids'=>[22]])]]]]]);
        });
        $r=(new OpenAIProvider())->generate('answer',['sources'=>[['id'=>22]]]);self::assertSame('OpenAI · API real',$r['mode']);self::assertSame([22],$r['source_ids']);
    }
    public function testDeepSeekTransportUsesOfficialEndpointBearerKeyAndConfiguredModel(): void
    {
        Settings::save(['ai_provider'=>'deepseek','deepseek_model'=>'deepseek-flash','deepseek_key'=>'sk-test-deepseek-never-send']);
        $this->mock(static function($args,$url){
            self::assertSame('https://api.deepseek.com/chat/completions',$url);
            self::assertSame('Bearer sk-test-deepseek-never-send',$args['headers']['Authorization']);
            self::assertArrayNotHasKey('x-goog-api-key',$args['headers']);
            $body=json_decode($args['body'],true);self::assertSame('deepseek-flash',$body['model']);
            self::assertSame(['type'=>'json_object'],$body['response_format']);self::assertSame(['type'=>'disabled'],$body['thinking']);
            self::assertStringNotContainsString('sk-test-deepseek-never-send',$args['body']);
            self::assertSame('answer',json_decode($body['messages'][1]['content'],true)['task']);
            return self::response(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>wp_json_encode(['answer'=>'Respuesta DeepSeek','source_ids'=>[22]])]]]]);
        });
        $r=(new DeepSeekProvider())->generate('answer',['sources'=>[['id'=>22]]]);self::assertSame('DeepSeek · API real',$r['mode']);self::assertSame([22],$r['source_ids']);
    }
    public function testRealAIPropagatesSafeQuotaError(): void
    {
        $this->mock(static fn()=>self::response('sensitive upstream body',429));$this->expectException(RuntimeException::class);$this->expectExceptionMessage('cuota o el límite');(new RealAIProvider())->generate('answer',[]);
    }
    public function testRealAIRejectsMalformedResponse(): void
    {
        $this->mock(static fn()=>self::response(['output'=>[]]));$this->expectException(RuntimeException::class);$this->expectExceptionMessage('formato no válido');(new RealAIProvider())->generate('answer',[]);
    }
    public function testUnconfiguredAIReportsConfigurationFailure(): void
    {
        Secrets::remove('ai_key');$this->expectException(RuntimeException::class);$this->expectExceptionMessage('Configura una Gemini API Key');(new RealAIProvider())->generate('answer',[]);
    }
    public function testYouTubeUsesAuthorizedCaptionsDownload(): void
    {
        Secrets::set('google_youtube_'.$this->user,wp_json_encode(['access_token'=>'fake','expires_at'=>time()+3600]));
        $this->mock(static function($args,$url){self::assertSame('Bearer fake',$args['headers']['Authorization']);if(str_contains($url,'/captions?'))return self::response(['items'=>[['id'=>'caption-id']]]);self::assertSame('https://www.googleapis.com/youtube/v3/captions/caption-id?tfmt=srt',$url);return self::response("1\n00:00:00,000 --> 00:01:00,000\nTexto autorizado");});
        $r=(new YouTubeVideoProvider())->transcript('abcdefghijk');self::assertSame('API REAL YouTube',$r['mode']);self::assertStringContainsString('Texto autorizado',$r['text']);
    }
    public function testYouTubeDoesNotInventUnavailableCaptions(): void
    {
        Secrets::set('google_youtube_'.$this->user,wp_json_encode(['access_token'=>'fake','expires_at'=>time()+3600]));$this->mock(static fn()=>self::response(['items'=>[]]));$this->expectException(RuntimeException::class);$this->expectExceptionMessage('No se encontraron subtítulos');(new YouTubeVideoProvider())->transcript('abcdefghijk');
    }
    public function testYouTubeMetadataUsesOfficialEndpointAndDuration(): void
    {
        Secrets::set('google_youtube_'.$this->user,wp_json_encode(['access_token'=>'fake','expires_at'=>time()+3600]));
        $this->mock(static function($args,$url){self::assertStringStartsWith('https://www.googleapis.com/youtube/v3/videos?',$url);self::assertSame('Bearer fake',$args['headers']['Authorization']);return self::response(['items'=>[['snippet'=>['title'=>'Video autorizado'],'contentDetails'=>['duration'=>'PT1H2M3S']]]]);});
        $data=(new YouTubeVideoProvider())->metadata('abcdefghijk');self::assertSame(3723,$data['duration_seconds']);self::assertSame('API REAL YouTube',$data['mode']);self::assertSame('https://i.ytimg.com/vi/abcdefghijk/hqdefault.jpg',$data['thumbnail_url']);
    }
    public function testYouTubeMetadataReportsMissingVideoWithoutInventingDuration(): void
    {
        Secrets::set('google_youtube_'.$this->user,wp_json_encode(['access_token'=>'fake','expires_at'=>time()+3600]));$this->mock(static fn()=>self::response(['items'=>[]]));$this->expectException(RuntimeException::class);(new YouTubeVideoProvider())->metadata('abcdefghijk');
    }
    public function testGoogleConnectScopesAndPerUserTokenRefresh(): void
    {
        $r=GoogleOAuth::connect('calendar');parse_str(wp_parse_url($r['url'],PHP_URL_QUERY),$query);self::assertSame('https://www.googleapis.com/auth/calendar.events',$query['scope']);self::assertSame(64,strlen($query['state']));self::assertSame('code',$query['response_type']);
        $r=GoogleOAuth::connect('youtube');self::assertStringContainsString('youtube.force-ssl',$r['url']);
        Secrets::set('google_calendar_'.$this->user,wp_json_encode(['access_token'=>'expired','refresh_token'=>'refresh-test','expires_at'=>time()-10]));
        $this->mock(static function($args,$url){self::assertSame('https://oauth2.googleapis.com/token',$url);self::assertSame('refresh_token',$args['body']['grant_type']);return self::response(['access_token'=>'new-token','expires_in'=>3600]);});
        self::assertSame('new-token',GoogleOAuth::accessToken($this->user,'calendar'));self::assertSame('new-token',GoogleOAuth::accessToken($this->user,'calendar'));self::assertTrue(GoogleOAuth::disconnect('calendar')['ok']);self::assertSame('',Secrets::get('google_calendar_'.$this->user));
    }

    public function testOrganizerCalendarCreatesEventAndEmailsGoogleInvitations(): void
    {
        Secrets::set('google_calendar_'.$this->user,wp_json_encode(['access_token'=>'fake','expires_at'=>time()+3600]));
        $guestEmail='calendar_guest_'.bin2hex(random_bytes(4)).'@example.com';
        $guest=wp_insert_user(['user_login'=>'calendar_guest_'.bin2hex(random_bytes(4)),'user_email'=>$guestEmail,'user_pass'=>wp_generate_password(30),'role'=>'ascla_member']);
        self::assertIsInt($guest);$this->users[]=$guest;
        $p=Content::save('event',['title'=>'Organizer calendar invite','body'=>'Private discussion','status'=>'publish','meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200),'location'=>'Sala virtual']]);$this->posts[]=$p['id'];
        $calls=[];
        $this->mock(static function($args,$url)use(&$calls,$guestEmail){
            $calls[]=['method'=>$args['method'],'url'=>$url,'body'=>isset($args['body'])?json_decode($args['body'],true):null];
            if($args['method']==='POST'){
                self::assertStringNotContainsString('sendUpdates=all',$url);
                return self::response(['id'=>'organizer-remote']);
            }
            if($args['method']==='GET'){
                return self::response(['id'=>'organizer-remote','attendees'=>[]]);
            }
            self::assertSame('PATCH',$args['method']);
            self::assertStringContainsString('sendUpdates=all',$url);
            $body=json_decode($args['body'],true);
            self::assertSame($guestEmail,$body['attendees'][0]['email']);
            self::assertFalse($body['guestsCanSeeOtherGuests']);
            return self::response(['id'=>'organizer-remote','attendees'=>$body['attendees']]);
        });
        $created=GoogleOAuth::calendar($p['id'],'organize');self::assertTrue($created['ok']);
        Store::insert('registrations',['event_id'=>$p['id'],'user_id'=>$guest,'status'=>'invited','created_at'=>current_time('mysql',true)]);
        $invited=GoogleOAuth::inviteEvent($p['id'],[$guest]);
        self::assertTrue($invited['available']);self::assertSame(1,$invited['sent']);self::assertSame(1,$invited['attendees']);
        self::assertSame(['POST','GET','PATCH'],array_column($calls,'method'));
        self::assertSame('organizer-remote',get_post_meta($p['id'],'_ascla_google_organizer_event',true));
        self::assertSame($this->user,(int)get_post_meta($p['id'],'_ascla_google_organizer_user',true));
    }

    public function testCalendarCreateUpdateAndCancelRequireExplicitOperations(): void
    {
        Secrets::set('google_calendar_'.$this->user,wp_json_encode(['access_token'=>'fake','expires_at'=>time()+3600]));$p=Content::save('event',['title'=>'Calendar transport test','body'=>'Private discussion','status'=>'publish','meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200)]]);$this->posts[]=$p['id'];$methods=[];
        $this->mock(static function($args,$url)use(&$methods){self::assertStringStartsWith('https://www.googleapis.com/calendar/v3/calendars/primary/events',$url);$methods[]=$args['method'];if($args['method']!=='DELETE'){self::assertSame('private',json_decode($args['body'],true)['visibility']);}return self::response(['id'=>'remote-test-event']);});
        self::assertTrue(GoogleOAuth::calendar($p['id'],'save')['ok']);self::assertTrue(GoogleOAuth::calendar($p['id'],'save')['ok']);self::assertTrue(GoogleOAuth::calendar($p['id'],'cancel')['ok']);self::assertSame(['POST','PATCH','DELETE'],$methods);self::assertSame('',get_user_meta($this->user,'_ascla_google_event_'.$p['id'],true));
    }
}
