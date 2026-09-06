<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Integrations\{RealAIProvider,YouTubeVideoProvider,GoogleOAuth,Secrets};
use ASCLA\Core\Services\{Settings,Content};
final class ProvidersTest extends TestCase
{
    private int $user; private $http; private array $settings; private array $posts=[]; private array $savedSecrets=[];
    protected function setUp(): void
    {
        $this->settings=Settings::get();foreach(['ai_key','google_client_secret'] as $key)$this->savedSecrets[$key]=Secrets::get($key);$this->user=wp_insert_user(['user_login'=>'test_provider_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(30),'role'=>'administrator']);wp_set_current_user($this->user);
        Settings::save(['ai_mode'=>'real','ai_model'=>'configured-test-model','ai_key'=>'fake-key-for-http-mock','google_client_id'=>'fake-client-id','google_client_secret'=>'fake-client-secret']);
    }
    protected function tearDown(): void
    {
        if($this->http)remove_filter('pre_http_request',$this->http,10);
        foreach($this->posts as $id)wp_delete_post($id,true);
        foreach(['ai_key','google_client_secret','google_calendar_'.$this->user,'google_youtube_'.$this->user] as $key)Secrets::remove($key);
        foreach($this->savedSecrets as $key=>$value){if($value!=='')Secrets::set($key,$value);}
        wp_delete_user($this->user);update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function mock(callable $handler): void
    {
        $this->http=static fn($pre,$args,$url)=>$handler($args,$url);add_filter('pre_http_request',$this->http,10,3);
    }
    private static function response(mixed $body,int $code=200): array {return ['response'=>['code'=>$code],'headers'=>[],'body'=>is_string($body)?$body:wp_json_encode($body)];}
    public function testRealAITransportUsesFixedEndpointNoPersistenceAndValidJSON(): void
    {
        $this->mock(static function($args,$url){self::assertSame('https://api.openai.com/v1/responses',$url);$body=json_decode($args['body'],true);self::assertFalse($body['store']);self::assertSame('configured-test-model',$body['model']);self::assertSame('json_object',$body['text']['format']['type']);return self::response(['output'=>[['content'=>[['type'=>'output_text','text'=>wp_json_encode(['answer'=>'Respuesta de prueba','source_ids'=>[22]])]]]]]);});
        $r=(new RealAIProvider())->generate('answer',['sources'=>[['id'=>22]]]);self::assertSame('API REAL',$r['mode']);self::assertSame([22],$r['source_ids']);
    }
    public function testRealAIPropagatesSafeQuotaError(): void
    {
        $this->mock(static fn()=>self::response('sensitive upstream body',429));$this->expectException(RuntimeException::class);$this->expectExceptionMessage('Revise configuración, cuota');(new RealAIProvider())->generate('answer',[]);
    }
    public function testRealAIRejectsMalformedResponse(): void
    {
        $this->mock(static fn()=>self::response(['output'=>[]]));$this->expectException(RuntimeException::class);$this->expectExceptionMessage('formato no válido');(new RealAIProvider())->generate('answer',[]);
    }
    public function testUnconfiguredAIReportsConfigurationFailure(): void
    {
        Secrets::remove('ai_key');$this->expectException(RuntimeException::class);$this->expectExceptionMessage('IA no configurada');(new RealAIProvider())->generate('answer',[]);
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
    public function testCalendarCreateUpdateAndCancelRequireExplicitOperations(): void
    {
        Secrets::set('google_calendar_'.$this->user,wp_json_encode(['access_token'=>'fake','expires_at'=>time()+3600]));$p=Content::save('event',['title'=>'Calendar transport test','body'=>'Private discussion','status'=>'publish','meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200)]]);$this->posts[]=$p['id'];$methods=[];
        $this->mock(static function($args,$url)use(&$methods){self::assertStringStartsWith('https://www.googleapis.com/calendar/v3/calendars/primary/events',$url);$methods[]=$args['method'];if($args['method']!=='DELETE'){self::assertSame('private',json_decode($args['body'],true)['visibility']);}return self::response(['id'=>'remote-test-event']);});
        self::assertTrue(GoogleOAuth::calendar($p['id'],'save')['ok']);self::assertTrue(GoogleOAuth::calendar($p['id'],'save')['ok']);self::assertTrue(GoogleOAuth::calendar($p['id'],'cancel')['ok']);self::assertSame(['POST','PATCH','DELETE'],$methods);self::assertSame('',get_user_meta($this->user,'_ascla_google_event_'.$p['id'],true));
    }
}
