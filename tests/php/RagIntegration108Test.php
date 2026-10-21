<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{Knowledge,Settings};

/** Exercise retrieval through each real transport; HTTP is intercepted before any network call. */
final class RagIntegration108Test extends TestCase
{
    private array $users=[],$posts=[],$environment=[],$contexts=[];
    private mixed $settings;
    private string $marker,$reserved;
    private int $public,$protected,$draft,$unreviewed;
    private $http;

    protected function setUp(): void
    {
        $this->settings=get_option('ascla_settings',null);
        foreach (['ASCLA_AI_KEY','ASCLA_OPENAI_KEY','ASCLA_DEEPSEEK_KEY'] as $key) {
            $this->environment[$key]=getenv($key);putenv($key.'=local-rag-fixture-never-sent');
        }
        foreach (['ascla_member','ascla_executive'] as $role) {
            $login='rag108_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[1]);
        $this->marker='continuidadz'.bin2hex(random_bytes(7));$this->reserved='Identidadreser'.bin2hex(random_bytes(7));
        $this->public=$this->source('publish',$this->marker.' · Guía de continuidad','Michael Porter y Balanced Scorecard son referencias de la revisión con ISO 37000.');
        $this->protected=$this->source('publish',$this->marker.' · Acuerdos','El control sigue COSO ERM y la Ley General de Sociedades. '.$this->reserved.' compartió los acuerdos.',['chatham'=>true,'identities'=>$this->reserved]);
        $this->draft=$this->source('draft',$this->marker.' · Borrador ajeno','BORRADOR_NO_AUTORIZADO');
        $this->unreviewed=$this->source('publish',$this->marker.' · Propuesta sin revisar','GENERADO_NO_REVISADO',['generated'=>true,'reviewed'=>false]);
        wp_set_current_user($this->users[0]);
    }

    protected function tearDown(): void
    {
        if ($this->http) remove_filter('pre_http_request',$this->http,10);
        foreach ($this->posts as $id) wp_delete_post($id,true);
        foreach ($this->users as $id) wp_delete_user($id);
        if ($this->settings===null) delete_option('ascla_settings');else update_option('ascla_settings',$this->settings,false);
        foreach ($this->environment as $key=>$value) putenv($value===false?$key:$key.'='.$value);
        wp_set_current_user(0);
    }

    private function source(string $status,string $title,string $body,array $meta=[]): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_resource','post_status'=>$status,'post_author'=>$this->users[1],'post_title'=>$title,'post_content'=>$body]);
        update_post_meta($id,'_ascla',$meta+['chatham'=>false]);$this->posts[]=$id;return $id;
    }

    public static function providers(): array { return [['gemini'],['openai'],['deepseek']]; }

    private function transport(string $provider): void
    {
        $settings=Settings::get();$settings['ai_provider']=$provider;$settings['ai_mode']='real';
        $settings['ai_model']='gemini-2.5-flash';$settings['openai_model']='fixture-model';$settings['deepseek_model']='deepseek-chat';
        update_option('ascla_settings',$settings,false);
        $this->http=function($pre,$args,$url) use($provider) {
            $body=json_decode($args['body'],true);
            if ($provider==='gemini') {
                self::assertStringStartsWith('https://generativelanguage.googleapis.com/',$url);
                $input=json_decode($body['contents'][0]['parts'][0]['text'],true);
            } elseif ($provider==='openai') {
                self::assertSame('https://api.openai.com/v1/responses',$url);$input=json_decode($body['input'],true);
            } else {
                self::assertSame('https://api.deepseek.com/chat/completions',$url);$input=json_decode($body['messages'][1]['content'],true);
            }
            self::assertSame('answer',$input['task']);$context=$input['context'];$this->contexts[]=$context;
            $ids=array_column($context['sources'],'id');
            self::assertContains($this->public,$ids);self::assertContains($this->protected,$ids);
            self::assertNotContains($this->draft,$ids);self::assertNotContains($this->unreviewed,$ids);
            foreach ([$this->reserved,'BORRADOR_NO_AUTORIZADO','GENERADO_NO_REVISADO'] as $secret) self::assertStringNotContainsString($secret,wp_json_encode($context));
            foreach (['Michael Porter','Balanced Scorecard','ISO 37000','COSO ERM','Ley General de Sociedades'] as $reference) self::assertStringContainsString($reference,wp_json_encode($context,JSON_UNESCAPED_UNICODE));
            $answer=['answer'=>'Michael Porter, Balanced Scorecard e ISO 37000 orientan la revisión. La identidad reservada explicó los acuerdos.','source_ids'=>[$this->public,999999999]];
            $response=match($provider) {
                'gemini'=>['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode($answer)]]],'finishReason'=>'STOP']]],
                'openai'=>['status'=>'completed','output_text'=>wp_json_encode($answer)],
                default=>['choices'=>[['finish_reason'=>'stop','message'=>['content'=>wp_json_encode($answer)]]]],
            };
            return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode($response)];
        };
        add_filter('pre_http_request',$this->http,10,3);
    }

    #[DataProvider('providers')]
    public function testRetrievesAuthorizedSourcesAndPassesThemThroughEachRealProvider(string $provider): void
    {
        $this->transport($provider);$result=Knowledge::answer('Explica '.$this->marker);
        self::assertCount(1,$this->contexts);self::assertSame([$this->public],array_column($result['sources'],'id'));
        self::assertSame(1,$result['grounding']['ignored_references']);self::assertFalse($result['grounding']['conversation_only']);
        self::assertContains($this->protected,$result['grounding']['context_source_ids']);
        foreach (['Michael Porter','Balanced Scorecard','ISO 37000'] as $reference) self::assertStringContainsString($reference,$result['answer']);
        self::assertStringNotContainsString('identidad reservada',$result['answer']);
        self::assertSame($result['answer'],Knowledge::storedAnswer($result)['answer']);
    }

    #[DataProvider('providers')]
    public function testFollowUpRetrievalUsesTheCurrentThreadAndAnEmptyThreadDoesNotReuseIt(string $provider): void
    {
        $this->transport($provider);
        $history=[['question'=>'Explica '.$this->marker,'answer'=>'Los acuerdos requieren seguimiento.']];
        $result=Knowledge::answer('¿Y cómo?',$history);
        self::assertCount(1,$this->contexts);self::assertContains($this->public,array_column($result['sources'],'id'));
        $history[]=['question'=>'¿Y cómo?','answer'=>$result['answer']];
        $again=Knowledge::answer('¿Y después?',$history);
        self::assertCount(2,$this->contexts);self::assertContains($this->public,array_column($again['sources'],'id'));
        $fresh=Knowledge::answer('¿Y cómo?',[]);
        self::assertCount(2,$this->contexts);self::assertSame([],$fresh['sources']);
        self::assertStringContainsString('No existe suficiente información',$fresh['answer']);
    }
}
