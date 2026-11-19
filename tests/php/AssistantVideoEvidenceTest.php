<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Database\VideoEvidenceMigration;
use ASCLA\Core\Integrations\MockAIProvider;
use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\{ContentMeta,Knowledge,Settings,VideoEvidence};

final class AssistantVideoEvidenceTest extends TestCase
{
    private array $users=[],$posts=[];
    private mixed $settings;

    protected function setUp(): void
    {
        $this->settings=get_option('ascla_settings',[]);
        update_option('ascla_settings',array_merge(Settings::get(),['ai_provider'=>'mock']),false);
        foreach (['ascla_executive','ascla_member'] as $role) {
            $login='video_test_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->users as $id) {
            foreach (['jobs','notifications'] as $table) { Store::delete($table,['user_id'=>$id]); }
            foreach (['audit','deliveries'] as $table) { Store::delete($table,['actor_id'=>$id]); }
            wp_delete_user($id);
        }
        update_option('ascla_settings',$this->settings,false);
        wp_set_current_user(0);
    }

    private function video(string $title,array $meta,string $status='publish'): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_resource','post_author'=>$this->users[0],'post_status'=>$status,'post_title'=>$title,'post_content'=>'Grabación de la sesión.']);
        $this->posts[]=$id;
        update_post_meta($id,'_ascla',$meta+['resource_type'=>'Video','chatham'=>false]);
        return $id;
    }

    public function testOrganizationAndVisibleSectionsAreAvailableWithoutAnyPublication(): void
    {
        wp_set_current_user($this->users[1]);
        foreach (['¿Qué es ASCLA?','¿Qué contiene la intranet?'] as $question) {
            $result=Knowledge::answer($question);
            self::assertStringContainsString('Asociación de Secretarios Corporativos de América Latina',$result['answer']);
            self::assertStringContainsString('Centro de Conocimiento',$result['answer']);
            self::assertStringContainsString('Directorio',$result['answer']);
            self::assertStringNotContainsString('Administración',$result['answer']);
            self::assertSame($result['answer'],Knowledge::storedAnswer($result)['answer']);
        }
    }

    public function testGenerationCitationFollowUpAndChangedSource(): void
    {
        $keyword='protocolo'.bin2hex(random_bytes(5));
        $id=$this->video('Sesión del consejo',['transcript'=>'El consejo aprobó el '.$keyword.' para revisar las actas cada quince días. La secretaría comunica los resultados al directorio.','transcript_status'=>'manual']);
        Knowledge::multimedia($id,new MockAIProvider());
        self::assertStringContainsString($keyword,get_post_meta($id,VideoEvidence::INDEX,true));
        $own=Knowledge::answer('Resume el último video que subí');
        self::assertSame([$id],array_column($own['sources'],'id'));
        wp_set_current_user($this->users[1]);
        $result=Knowledge::answer('¿Qué dice el video sobre '.$keyword.'?');
        self::assertStringContainsString('quince días',$result['answer']);
        self::assertSame([$id],array_column($result['sources'],'id'));
        self::assertStringContainsString('item='.$id,$result['sources'][0]['url']);
        self::assertSame($result['answer'],Knowledge::storedAnswer($result)['answer']);
        $topic=Knowledge::answer('¿Qué recursos hay sobre '.$keyword.'?');
        self::assertSame([$id],array_column($topic['sources'],'id'));
        self::assertStringContainsString($keyword,$topic['answer']);
        $history=[['question'=>'¿Qué dice el video sobre '.$keyword.'?','answer'=>$result['answer'],'source_ids'=>[$id]]];
        self::assertSame([$id],array_column(Knowledge::answer('¿Y sus conclusiones?',$history)['sources'],'id'));
        self::assertSame([],Knowledge::answer('Resume el último video que subí')['sources']);
        wp_set_current_user($this->users[0]);
        $meta=ContentMeta::sanitize('resource',['transcript'=>'Se reemplazó el contenido por una nueva sesión de trabajo.'],$id);
        update_post_meta($id,'_ascla',wp_slash($meta));
        self::assertSame('',get_post_meta($id,VideoEvidence::INDEX,true));
        self::assertSame([],Knowledge::answer($keyword)['sources']);
        self::assertSame([],Knowledge::storedAnswer($result)['sources']);
    }

    public function testChatReloadAndFollowUpUsePublishedVideoEvidence(): void
    {
        $term='acuerdo'.bin2hex(random_bytes(5));
        $id=$this->video('Reunión de estrategia',['summary'=>'El '.$term.' fija treinta días para la revisión.','conclusions'=>'El consejo revisará el estado cada mes.']);
        wp_set_current_user($this->users[1]);
        $thread='video_'.bin2hex(random_bytes(8));
        foreach (['¿Qué dice el video sobre '.$term.'?', '¿Y sus conclusiones?'] as $question) {
            $request=new WP_REST_Request('POST','/ascla/v1/ask');
            $request->set_param('question',$question);
            $request->set_param('thread',$thread);
            $response=rest_do_request($request);
            self::assertSame(200,$response->get_status());
            $job=(int)$response->get_data()['id'];
            (new ReflectionMethod(Queue::class,'processRow'))->invoke(null,Store::one('jobs',$job),Store::table('jobs'),get_current_user_id());
            self::assertSame('completed',Queue::get($job)['status']);
        }
        $turns=Queue::thread($thread);
        self::assertCount(2,$turns);
        self::assertStringContainsString('treinta días',$turns[0]['result']['answer']);
        foreach ($turns as $turn) { self::assertSame([$id],array_column($turn['result']['sources'],'id')); }
        wp_update_post(['ID'=>$id,'post_status'=>'draft']);
        foreach (Queue::thread($thread) as $turn) {
            self::assertSame([],$turn['result']['sources']);
            self::assertStringNotContainsString($term,$turn['result']['answer']);
        }
    }

    public function testExistingStructuredMaterialIsBackfilledWithoutPrivateDataOrRegeneration(): void
    {
        $key='cifra'.bin2hex(random_bytes(5));
        $id=$this->video('Informe del comité',[
            'ai_enriched'=>true,'chatham'=>true,'identities'=>'Persona Confidencial',
            'summary'=>'Persona Confidencial explicó los resultados del período.',
            'infographic'=>['statistics'=>['El indicador '.$key.' alcanzó el 72 %.']],
            'moments'=>[['start'=>87,'title'=>'Revisión de indicadores','description'=>'Contraste entre períodos.']],
            'transcript'=>'textoeditorialprivado','transcript_error'=>'errorinternoprivado'
        ]);
        $this->video('Borrador',['summary'=>$key.' borrador'],'draft');
        $this->video('Sin revisar',['generated'=>true,'reviewed'=>false,'summary'=>$key.' pendiente']);
        delete_post_meta($id,VideoEvidence::INDEX);
        delete_option('ascla_video_evidence_index');
        VideoEvidenceMigration::run();
        $indexed=get_post_meta($id,VideoEvidence::INDEX,true);
        self::assertStringContainsString('1:27',$indexed);
        foreach (['Persona Confidencial','textoeditorialprivado','errorinternoprivado'] as $private) { self::assertStringNotContainsString($private,$indexed); }
        wp_set_current_user($this->users[1]);
        $result=Knowledge::answer($key);
        self::assertSame([$id],array_column($result['sources'],'id'));
        self::assertStringContainsString('72 %',$result['answer']);
        wp_update_post(['ID'=>$id,'post_status'=>'draft']);
        self::assertSame([],Knowledge::answer($key)['sources']);
        self::assertSame([],Knowledge::storedAnswer($result)['sources']);
    }
}
