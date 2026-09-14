<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Profiles,Matching,NetworkingAI,Settings,Knowledge,DemoShowcase};
use ASCLA\Core\Integrations\Secrets;
use ASCLA\Core\Domain\GroupPlanner;
use ASCLA\Core\Repositories\Store;
final class SprintNetworkingTest extends TestCase
{
    private array $users=[],$posts=[],$settings=[];private string $secret='';private $http;
    protected function setUp(): void {
        $this->settings=Settings::get();$this->secret=Secrets::get('ai_key');
        foreach(range(1,6) as $i){$id=wp_insert_user(['user_login'=>'network_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>$i===1?'administrator':'ascla_member']);$this->users[]=$id;wp_set_current_user($id);Profiles::save(['first_name'=>'NombreSecreto','last_name'=>'ApellidoPrivado','hidden'=>['first_name','position'],'position'=>'CARGO_RESERVADO','networking'=>true,'microevents'=>true,'interests'=>[Profiles::catalogs()['interest'][0]['id']]]);}
        wp_set_current_user($this->users[0]);Settings::save(['ai_mode'=>'real','ai_model'=>'gemini-transport-sprint-'.bin2hex(random_bytes(5)),'ai_key'=>'fake-key-for-http-tests']);
    }
    protected function tearDown(): void {
        if($this->http)remove_filter('pre_http_request',$this->http,10);
        foreach($this->posts as $id)wp_delete_post($id,true);
        foreach($this->users as $id){foreach(['notifications','jobs','relations'] as $t)Store::delete($t,['user_id'=>$id]);wp_delete_user($id);}
        Secrets::remove('ai_key');if($this->secret!=='')Secrets::set('ai_key',$this->secret);update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    public function testRealProviderIsActuallyCalledForExplanationIntroAndAgendaWithPrivateContextRemoved(): void {
        $tasks=[];$this->http=static function($pre,$args,$url)use(&$tasks){
            self::assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/models/gemini-',$url);$request=json_decode($args['body'],true);$input=json_decode($request['contents'][0]['parts'][0]['text'],true);$tasks[]=$input['task'];
            foreach(['NombreSecreto','ApellidoPrivado','CARGO_RESERVADO'] as $secret)self::assertStringNotContainsString($secret,$request['contents'][0]['parts'][0]['text']);
            $r=$input['task']==='microagenda'?['title'=>'Conversación sobre supervisión','theme'=>'supervisión','objective'=>'Explorar preguntas de supervisión','duration_minutes'=>30,'agenda'=>[['minutes'=>10,'topic'=>'Experiencias'],['minutes'=>20,'topic'=>'Preguntas compartidas']],'icebreaker'=>'¿Qué pregunta traes?','closing_question'=>'¿Qué sigue?']:['explanation'=>'Explicación devuelta por el proveedor.','text'=>'Mensaje devuelto por el proveedor.','conversation_proposal'=>'Pregunta propuesta por el proveedor.'];
            return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode($r)]]],'finishReason'=>'STOP']]])];
        };add_filter('pre_http_request',$this->http,10,3);
        $before=Store::count('messages','1=1',[]);$m=Matching::between($this->users[0],$this->users[1]);self::assertSame('Google Gemini · API real',$m['mode']);self::assertSame('Explicación devuelta por el proveedor.',$m['explanation']);
        $intro=Matching::intro($this->users[1]);self::assertSame('Mensaje devuelto por el proveedor.',$intro['text']);self::assertFalse($intro['sent']);self::assertSame($before,Store::count('messages','1=1',[]));
        $agenda=NetworkingAI::agenda($this->users);self::assertSame(30,$agenda['duration_minutes']);self::assertSame(['matching','intro','microagenda'],$tasks);
    }
    public function testBlockedPairsAreNeverGroupedAndNobodyIsLost(): void {
        $profiles=array_map(static fn($id)=>['id'=>$id],range(1,18));$blocked=['1:2'=>true,'3:4'=>true,'7:8'=>true];$plan=GroupPlanner::plan($profiles,[],0,$blocked);$ids=$plan['waiting'];
        foreach($plan['groups'] as $g){self::assertGreaterThanOrEqual(4,count($g));self::assertLessThanOrEqual(6,count($g));foreach($blocked as $key=>$v){[$a,$b]=array_map('intval',explode(':',$key));self::assertFalse(in_array($a,$g,true)&&in_array($b,$g,true));}$ids=array_merge($ids,$g);}
        sort($ids);self::assertSame(range(1,18),$ids);
    }
    public function testMockMultimediaEnrichesOriginalWithGroundedStructuredSectionsAndCapsules(): void {
        Settings::save(['ai_mode'=>'mock']);$id=wp_insert_post(['post_type'=>'ascla_resource','post_title'=>'Demostración sintética','post_content'=>'Fuente de prueba','post_status'=>'publish','post_author'=>$this->users[0]]);$this->posts[]=$id;
        update_post_meta($id,'_ascla',['transcript'=>DemoShowcase::transcript(),'chatham'=>true,'video_id'=>'M7lc1UVf-VE']);$r=Knowledge::multimedia($id);$meta=get_post_meta($id,'_ascla',true);
        self::assertSame($id,$r['resource_id']);self::assertSame(0,$r['hub_id']);self::assertSame([],$r['capsule_ids']);self::assertSame('publish',get_post_status($id));self::assertTrue((bool)$meta['ai_enriched']);
        self::assertNotEmpty($meta['frameworks'],'frameworks');self::assertNotEmpty($meta['norms'],'norms');self::assertNotEmpty($meta['infographic']['statistics'],'statistics');self::assertNotEmpty($meta['infographic']['timeline'],'timeline');
        $text=wp_json_encode($meta);foreach(['Juan Pérez','Compañía Boreal'] as $identity)self::assertStringNotContainsString($identity,$text);self::assertNotEmpty($meta['moments']);
        foreach($meta['moments'] as $clip){self::assertGreaterThanOrEqual(60,$clip['duration']);self::assertLessThanOrEqual(180,$clip['duration']);self::assertSame($id,$clip['source_id']);self::assertStringContainsString('&t=',$clip['youtube_url']);self::assertNotEmpty($clip['reason']);}
    }
    public function testNaturalGeminiOutputPreservesParaphrasesButRedactsIdentitiesAndRechecksSourceAccess(): void {
        $token='evidencia'.bin2hex(random_bytes(5));
        $source="En 2024 el 35 % de los participantes revisó $token. El ponente Juan Pérez de Empresa Boreal describió la evaluación.";
        $id=wp_insert_post(['post_type'=>'ascla_resource','post_title'=>$token,'post_content'=>$source,'post_status'=>'publish','post_author'=>$this->users[0]]);$this->posts[]=$id;
        update_post_meta($id,'_ascla',['transcript'=>$source,'chatham'=>true]);
        $this->http=static function($pre,$args,$url)use($id){
            self::assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/models/gemini-',$url);
            foreach(['Juan Pérez','Empresa Boreal'] as $identity)self::assertStringNotContainsString($identity,json_decode($args['body'],true)['contents'][0]['parts'][0]['text']);
            $bad='La evaluación incluyó al 35 % de participantes en 2024.';
            $result=['answer'=>$bad,'source_ids'=>[$id,99999999],'summary'=>$bad,'technical_note'=>'La directora María de Compañía Aurora explicó la evaluación del 35 %.','suggested_hub'=>$bad,'norms'=>[],'frameworks'=>[],'conclusions'=>[$bad],'infographic'=>['statistics'=>[$bad],'key_points'=>[$bad],'timeline'=>[['date'=>'2024','text'=>$bad]]]];
            return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode($result)]]],'finishReason'=>'STOP']]])];
        };add_filter('pre_http_request',$this->http,10,3);
        $answer=Knowledge::answer($token);self::assertCount(1,$answer['sources']);self::assertStringContainsString('La evaluación incluyó',$answer['answer']);
        $result=Knowledge::multimedia($id);self::assertSame($id,$result['resource_id']);self::assertSame(0,$result['hub_id']);self::assertSame([],$result['capsule_ids']);
        $meta=get_post_meta($id,'_ascla',true);$text=wp_json_encode($meta,JSON_UNESCAPED_UNICODE).' '.get_post($id)->post_content;
        foreach(['María','Aurora','Juan Pérez','Boreal'] as $unsupported)self::assertStringNotContainsString($unsupported,$text);
        self::assertStringContainsString('35 %',$text);self::assertSame('publish',get_post_status($id));self::assertTrue((bool)$meta['ai_enriched']);
        self::assertNotEmpty($meta['infographic']['statistics']);self::assertNotEmpty($meta['infographic']['timeline']);
        $saved=['answer'=>"En 2024 el 35 % de los participantes revisó $token.",'sources'=>[['id'=>$id]],'mode'=>'DEMO MODE'];
        self::assertCount(1,Knowledge::storedAnswer($saved)['sources']);
        wp_update_post(['ID'=>$id,'post_status'=>'draft']);
        self::assertSame([],Knowledge::storedAnswer($saved)['sources']);
        self::assertStringNotContainsString('35 %',Knowledge::storedAnswer($saved)['answer']);
    }
    public function testUnavailableOrInvalidAgendaProviderFallsBackWithoutBlockingTheGroup(): void {
        Settings::save(['ai_model'=>'gemini-offline-'.bin2hex(random_bytes(5))]);
        $this->http=static fn()=>new WP_Error('offline','Transport unavailable');add_filter('pre_http_request',$this->http,10,3);
        $agenda=NetworkingAI::agenda($this->users);self::assertSame('DEMO MODE',$agenda['mode']);self::assertTrue($agenda['fallback']);self::assertSame($agenda['duration_minutes'],array_sum(array_column($agenda['agenda'],'minutes')));
        remove_filter('pre_http_request',$this->http,10);Settings::save(['ai_model'=>'gemini-invalid-agenda-test']);
        $this->http=static fn()=>['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode(['duration_minutes'=>5,'agenda'=>[]])]]],'finishReason'=>'STOP']]])];add_filter('pre_http_request',$this->http,10,3);
        $agenda=NetworkingAI::agenda($this->users);self::assertSame('DEMO MODE',$agenda['mode']);self::assertTrue($agenda['fallback']);
    }

}
