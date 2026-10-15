<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\Settings;

/** Actual REST enqueue and queue worker, with Gemini HTTP intercepted before any network call. */
final class AssistantReset108Test extends TestCase
{
    private array $users=[],$contexts=[];
    private mixed $settings,$key;
    private string $oldThread,$newThread,$marker;
    private $http;

    protected function setUp(): void
    {
        $this->settings=get_option('ascla_settings',null);$this->key=getenv('ASCLA_AI_KEY');
        putenv('ASCLA_AI_KEY=local-fixture-key-never-sent');
        $settings=Settings::get();$settings['ai_provider']='gemini';$settings['ai_mode']='real';$settings['ai_model']='gemini-2.5-flash';update_option('ascla_settings',$settings,false);
        foreach (range(1,2) as $i) {
            $login='reset108_'.bin2hex(random_bytes(6));$id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
            $this->users[]=$id;update_user_meta($id,'_ascla_email_notifications',['support'=>false,'connections'=>false,'messages'=>false,'events'=>false]);
        }
        wp_set_current_user($this->users[0]);$this->oldThread='chat_old_'.bin2hex(random_bytes(6));$this->newThread='chat_new_'.bin2hex(random_bytes(6));$this->marker='HISTORIAL_RESERVADO_'.bin2hex(random_bytes(8));
        $this->http=function($pre,$args,$url) {
            self::assertStringStartsWith('https://generativelanguage.googleapis.com/v1beta/models/gemini-', $url);
            $body=json_decode($args['body'],true);self::assertCount(1,$body['contents']);
            $input=json_decode($body['contents'][0]['parts'][0]['text'],true);self::assertSame('answer',$input['task']);$this->contexts[]=$input['context'];
            return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>wp_json_encode(['answer'=>'Hola. Puedo ayudarte con ASCLA.','source_ids'=>[]])]]],'finishReason'=>'STOP']]])];
        };
        add_filter('pre_http_request',$this->http,10,3);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http,10);
        foreach ($this->users as $user) {
            foreach (['audit','deliveries'] as $table) { Store::delete($table,['actor_id'=>$user]); }
            foreach (['jobs','notifications'] as $table) { Store::delete($table,['user_id'=>$user]); }
            wp_delete_user($user);
        }
        if ($this->settings===null) { delete_option('ascla_settings'); } else { update_option('ascla_settings',$this->settings,false); }
        putenv($this->key===false?'ASCLA_AI_KEY':'ASCLA_AI_KEY='.$this->key);wp_set_current_user(0);
    }
    private function seed(int $user,string $thread,string $question,string $answer): int
    {
        return Store::insert('jobs',['kind'=>'answer','user_id'=>$user,'status'=>'completed','payload'=>wp_json_encode(['thread'=>$thread,'question'=>$question]),'result'=>wp_json_encode(['answer'=>$answer,'sources'=>[],'grounding'=>['conversation_only'=>true]]),'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    private function enqueue(string $thread): int
    {
        $r=new WP_REST_Request('POST','/ascla/v1/ask');$r->set_param('question','Hola, ¿puedes ayudarme?');$r->set_param('thread',$thread);
        $response=rest_do_request($r);self::assertSame(200,$response->get_status());return (int)$response->get_data()['id'];
    }
    private function process(int $id): void
    {
        // Run this owned job through the actual worker without processing unrelated local jobs.
        (new ReflectionMethod(Queue::class,'processRow'))->invoke(null,Store::one('jobs',$id),Store::table('jobs'),get_current_user_id());
        self::assertSame('completed',Queue::get($id)['status']);
    }
    public function testFreshThreadSendsNoPreviousConversationToGeminiAndKeepsHistory(): void
    {
        $this->seed($this->users[0],$this->oldThread,'Hola '.$this->marker,'Respuesta '.$this->marker);
        $this->process($this->enqueue($this->newThread));
        self::assertCount(1,$this->contexts);self::assertSame([],$this->contexts[0]['history']);self::assertStringNotContainsString($this->marker,wp_json_encode($this->contexts[0]));
        self::assertCount(1,Queue::thread($this->oldThread));self::assertCount(1,Queue::thread($this->newThread));self::assertCount(2,Queue::answers());
    }
    public function testContinuingAnExplicitThreadUsesOnlyItsOwnCompletedTurns(): void
    {
        $this->seed($this->users[0],$this->oldThread,'Hola primero','Primera respuesta');
        $this->seed($this->users[1],$this->oldThread,'Hola otro usuario '.$this->marker,'Otro usuario '.$this->marker);
        $this->seed($this->users[0],$this->newThread,'Hola otro hilo '.$this->marker,'Otro hilo '.$this->marker);
        $this->seed($this->users[0],$this->oldThread,'Hola segundo','Segunda respuesta');
        $this->process($this->enqueue($this->oldThread));
        self::assertSame([['question'=>'Hola primero','answer'=>'Primera respuesta'],['question'=>'Hola segundo','answer'=>'Segunda respuesta']],$this->contexts[0]['history']);
        self::assertStringNotContainsString($this->marker,wp_json_encode($this->contexts[0]));
    }
    public function testAnOldJobCompletedAfterResetDoesNotBecomeTheNewThreadsContext(): void
    {
        $old=$this->enqueue($this->oldThread);$fresh=$this->enqueue($this->newThread);
        $this->process($old);$this->process($fresh);
        self::assertCount(2,$this->contexts);self::assertSame([],$this->contexts[1]['history']);
        self::assertSame($this->oldThread,Queue::get($old)['thread']);self::assertSame($this->newThread,Queue::get($fresh)['thread']);
    }
}
