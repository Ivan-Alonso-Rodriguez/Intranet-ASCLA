<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Domain\Transcript;
use ASCLA\Core\Services\{Content,Knowledge,Settings,VideoTranscripts};
use ASCLA\Core\Integrations\{AIProviderInterface,MockAIProvider};
use ASCLA\Core\Repositories\Store;

final class TranscriptContextTest extends TestCase
{
    private int $user;
    private array $settings=[],$posts=[],$environment=[],$httpContexts=[],$unexpectedRequests=[];
    private string $expectedText='';

    protected function setUp(): void
    {
        $this->settings=Settings::get();
        foreach (['ASCLA_AI_KEY','ASCLA_OPENAI_KEY','ASCLA_DEEPSEEK_KEY'] as $name) {
            $this->environment[$name]=getenv($name);putenv($name.'=fixture-transcript-context-only');
        }
        $this->user=wp_insert_user(['user_login'=>'transcript_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>'administrator']);
        wp_set_current_user($this->user);Settings::save(['ai_provider'=>'mock','youtube_mode'=>'mock','ai_model'=>'gemini-2.5-flash']);
        add_filter('pre_http_request',[$this,'http'],10,3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request',[$this,'http'],10);
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach (['jobs','notifications'] as $table) { Store::delete($table,['user_id'=>$this->user]); }
        Store::delete('audit',['actor_id'=>$this->user]);wp_delete_user($this->user);
        update_option('ascla_settings',$this->settings,false);
        foreach ($this->environment as $name=>$value) { putenv($value===false?$name:$name.'='.$value); }
        wp_set_current_user(0);
        self::assertSame([],$this->unexpectedRequests,'Timed captions must not request external video metadata.');
    }

    private function resource(string $source): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_resource','post_status'=>'publish','post_author'=>$this->user,'post_title'=>'Prueba de contexto completo','post_content'=>'Material sintético.']);
        $this->posts[]=$id;
        update_post_meta($id,'_ascla',wp_slash(['resource_type'=>'Video','video_id'=>'ASCLATEXT01','youtube_url'=>'https://youtu.be/ASCLATEXT01','chatham'=>false,'transcript'=>$source,'duration_seconds'=>9999]));
        return $id;
    }

    public function http(mixed $pre,array $args,string $url): mixed
    {
        $body=json_decode($args['body']??'{}',true);
        if ($url==='https://api.openai.com/v1/responses') { $input=json_decode($body['input'],true); }
        elseif (str_starts_with($url,'https://generativelanguage.googleapis.com/')) { $input=json_decode($body['contents'][0]['parts'][0]['text'],true); }
        elseif ($url==='https://api.deepseek.com/chat/completions') { $input=json_decode($body['messages'][1]['content'],true); }
        else { $this->unexpectedRequests[]=$url;return new WP_Error('fixture_unexpected_request','Unexpected external request'); }
        self::assertSame('multimedia',$input['task']);self::assertSame($this->expectedText,$input['context']['transcript']);
        self::assertSame(7201,$input['context']['duration_seconds']);
        self::assertStringNotContainsString('-->',$args['body']);self::assertStringContainsString('CIERRE_COMPLETO',$args['body']);
        $this->httpContexts[]=$input['context'];
        $result=wp_json_encode((new MockAIProvider())->generate('multimedia',$input['context']));
        $response=match(true) {
            str_contains($url,'openai.com')=>['status'=>'completed','output_text'=>$result],
            str_contains($url,'deepseek.com')=>['choices'=>[['finish_reason'=>'stop','message'=>['content'=>$result]]]],
            default=>['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>$result]]]]]],
        };
        return ['response'=>['code'=>200],'body'=>wp_json_encode($response),'headers'=>[],'cookies'=>[]];
    }

    public function testSubtitleCleanupKeepsSpeechNumbersAndFinalWords(): void
    {
        $source="\xEF\xBB\xBF1\r\n00:00:06,160 --> 00:00:10,040\r\nun\r\n\r\n2\r\n00:00:08,000 --> 00:00:13,120\r\nun soporte crítico de las juntas\r\n\r\n3\r\n00:00:10,040 --> 00:00:15,400\r\ndirectivas, de los directorios, ¿no? Eh,\r\n\r\n4\r\n00:00:13,120 --> 00:00:17,760\r\nLa sesión de 2026 empieza a las 10:30 y cuesta 25 %.\r\n\r\n5\r\n02:00:00,000 --> 02:00:00,250\r\nCIERRE_COMPLETO";
        self::assertSame('un un soporte crítico de las juntas directivas, de los directorios, ¿no? Eh, La sesión de 2026 empieza a las 10:30 y cuesta 25 %. CIERRE_COMPLETO',Transcript::plainText($source));
        self::assertSame(7201,Transcript::duration($source));
    }

    public function testWebVttAndBracketMarkersAreCleanedWithoutLosingSpokenText(): void
    {
        $vtt="WEBVTT\nKind: captions\nLanguage: es\n\nNOTE This is cue metadata\nNot speech\n\nintro\n00:01.000 --> 00:05.000 align:start position:0%\nPrimera <00:01.500>idea completa.\n\nclosing\n01:00:00.000 --> 01:00:03.400\nÚltimas palabras.";
        self::assertSame('Primera idea completa. Últimas palabras.',Transcript::plainText($vtt));
        self::assertSame(3604,Transcript::duration($vtt));
        self::assertSame('La primera idea. El cierre completo.',Transcript::plainText("[00:00] La primera idea.\n[02:10] El cierre completo."));
        self::assertSame(130,Transcript::duration("[00:00] La primera idea.\n[02:10] El cierre completo."));
        self::assertSame('Importe 2026 y hora 10:30, C:\\archivo <código>.',Transcript::plainText('Importe 2026 y hora 10:30, C:\\archivo <código>.'));
    }

    public function testDurationUsesTheGreatestValidEndAndHasSafeUntimedFallback(): void
    {
        $source="00:00:20,000 --> 00:01:00,010\nÚltimo segmento.\n\n00:00:01,000 --> 00:00:03,000\nPrimer segmento.\n\n00:99:00,000 --> 00:99:10,000\nTiempo inválido.";
        self::assertSame(61,Transcript::duration($source));
        $meta=VideoTranscripts::withDuration(['transcript'=>$source,'duration_seconds'=>9999]);
        self::assertSame(61,$meta['duration_seconds']);self::assertSame(VideoTranscripts::DURATION_MODE,$meta['video_metadata_mode']);
        $meta['transcript']='Texto sin tiempos.';$meta=VideoTranscripts::withDuration($meta);
        self::assertArrayNotHasKey('duration_seconds',$meta);
        self::assertSame(123,VideoTranscripts::withDuration(['transcript'=>'Texto sin tiempos.','duration_seconds'=>123,'video_metadata_mode'=>'Datos manuales'])['duration_seconds']);
    }

    public function testSavingObtainingAndRefreshingMetadataPreferTranscriptTimesWithoutHttp(): void
    {
        $source="00:00:00,000 --> 00:01:00,500\nEl directorio supervisa los riesgos y las responsabilidades de gobierno corporativo.";
        $id=$this->resource($source);VideoTranscripts::obtain($id);
        self::assertSame(61,get_post_meta($id,'_ascla',true)['duration_seconds']);
        self::assertSame(61,Knowledge::videoMetadata($id)['duration_seconds']);
        self::assertSame(61,Knowledge::browserVideoDuration($id,9999)['duration_seconds']);
        $source=str_replace('00:01:00,500','00:02:10,250',$source);
        $saved=Content::save('resource',['title'=>'Duración actualizada','body'=>'Material sintético.','meta'=>['transcript'=>$source,'duration_seconds'=>8888]],$id);
        self::assertSame(131,$saved['meta']['duration_seconds']);
        self::assertSame($source,get_post_meta($id,'_ascla',true)['transcript']);
        $saved=Content::save('resource',['title'=>'Sin tiempos','body'=>'Material sintético.','meta'=>['transcript'=>'Ahora la fuente no tiene tiempos y mantiene toda su información.','youtube_url'=>'']],$id);
        self::assertArrayNotHasKey('duration_seconds',$saved['meta']);
    }

    public static function providers(): array { return [['openai'],['gemini'],['deepseek']]; }

    #[DataProvider('providers')]
    public function testEveryAiTransportReceivesOnlyCompleteSpeechWhileSourceStaysTimed(string $provider): void
    {
        $source="00:00:00,000 --> 00:00:20,000\nEl directorio revisa los riesgos. Se conserva una cifra de 2026 y una hora de 10:30.\n\n02:00:00,000 --> 02:00:00,500\nCIERRE_COMPLETO con recomendaciones para la junta directiva.";
        $this->expectedText='El directorio revisa los riesgos. Se conserva una cifra de 2026 y una hora de 10:30. CIERRE_COMPLETO con recomendaciones para la junta directiva.';
        $id=$this->resource($source);Settings::save(['ai_provider'=>$provider]);Knowledge::multimedia($id);
        self::assertCount(1,$this->httpContexts);$meta=get_post_meta($id,'_ascla',true);
        self::assertSame($source,$meta['transcript']);self::assertSame(7201,$meta['duration_seconds']);
        self::assertNotEmpty($meta['summary']);self::assertNotEmpty($meta['technical_note']);
    }

    public function testLocalExcerptsRetainVerifiedTimesAfterAiContextCleanup(): void
    {
        $source='';
        for ($i=0;$i<240;$i++) { $source.=gmdate('H:i:s',$i).",000 --> ".gmdate('H:i:s',$i+1).",000\nEl directorio supervisa los riesgos y las responsabilidades.\n\n"; }
        $id=$this->resource($source);Knowledge::multimedia($id,new MockAIProvider());$meta=get_post_meta($id,'_ascla',true);
        self::assertSame(240,$meta['duration_seconds']);self::assertCount(1,$meta['moments']);
        self::assertSame(0,$meta['moments'][0]['start']);self::assertSame(60,$meta['moments'][0]['end']);
        self::assertStringContainsString('&t=0s',$meta['moments'][0]['youtube_url']);self::assertSame($source,$meta['transcript']);
    }

    public function testTimingOnlySourceNeverReachesAi(): void
    {
        $source="1\n00:00:00,000 --> 00:00:01,000\n\n2\n00:00:01,000 --> 00:00:02,000\n";
        $ai=new class implements AIProviderInterface {
            public function mode(): string { return 'must-not-run'; }
            public function generate(string $task,array $context): array { TestCase::fail('Timing metadata is not usable speech.'); }
        };
        $this->expectException(\ASCLA\Core\Rest\ApiException::class);
        $this->expectExceptionMessage('insuficiente');Knowledge::multimedia($this->resource($source),$ai);
    }
}
