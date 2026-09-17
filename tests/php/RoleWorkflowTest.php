<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content, Access, Settings};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Jobs\Queue;

/** Cumulative contract: administrator > executive > moderator > member. */
final class RoleWorkflowTest extends TestCase
{
    private array $users = [];
    private array $posts = [];
    private array $settings = [];
    private $http;
    private int $admin, $executive, $moderator, $member;

    protected function setUp(): void
    {
        $this->settings=Settings::get();
        $this->http=static fn()=>new WP_Error('local_test','External requests disabled in role tests');
        add_filter('pre_http_request',$this->http,10,3);
        foreach (['administrator', 'ascla_executive', 'ascla_moderator', 'ascla_member'] as $role) {
            $login = 'role_' . bin2hex(random_bytes(6));
            $this->users[] = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password(32), 'role' => $role]);
        }
        [$this->admin, $this->executive, $this->moderator, $this->member] = $this->users;
        wp_set_current_user($this->admin);Settings::save(['moderation_required'=>true,'ai_provider'=>'mock','youtube_mode'=>'mock']);
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach ($this->posts as $id) { wp_delete_post($id, true); }
        foreach ($this->users as $id) {
            foreach(['jobs','notifications','relations','registrations'] as $table){Store::delete($table,['user_id'=>$id]);}
            wp_delete_user($id);
        }
        update_option('ascla_settings',$this->settings,false);remove_filter('pre_http_request',$this->http,10);
        wp_set_current_user(0);
    }

    private function api(string $method, string $route, array $body = []): WP_REST_Response
    {
        $r = new WP_REST_Request($method, '/ascla/v1/' . $route);
        $r->set_header('Content-Type', 'application/json');
        $r->set_body(wp_json_encode($body));
        return rest_do_request($r);
    }

    private function eventPayload(array $extra = []): array
    {
        return $extra + [
            'title' => 'Encuentro ' . bin2hex(random_bytes(4)),
            'body' => 'Contenido de prueba',
            'status' => 'publish',
            'meta' => ['start' => gmdate('c', time() + 86400), 'end' => gmdate('c', time() + 90000)],
        ];
    }

    public function testExecutiveCanPublishEventsGalleryAndResourcesDirectly(): void
    {
        wp_set_current_user($this->executive);
        foreach (['event', 'gallery', 'resource'] as $type) {
            $payload = $type === 'event' ? $this->eventPayload() : ['title' => 'Item ' . bin2hex(random_bytes(4)), 'body' => 'Cuerpo', 'status' => 'publish', 'meta' => []];
            $response = $this->api('POST', 'content/' . $type, $payload);
            self::assertSame(200, $response->get_status(), "Ejecutivo debe poder crear/publicar $type");
            $data = $response->get_data();
            $this->posts[] = $data['id'];
            self::assertSame('publish', $data['status'], "El $type creado por el Ejecutivo debe publicarse directamente, sin pasar a revisión");
        }
    }

    public function testExecutiveCanPublishReviewedGeneratedKnowledgeDirectly(): void
    {
        wp_set_current_user($this->executive);
        $id=wp_insert_post(['post_type'=>'ascla_resource','post_title'=>'Nota IA','post_content'=>'Borrador generado','post_status'=>'draft','post_author'=>$this->executive]);
        $this->posts[]=$id;
        update_post_meta($id,'_ascla',['resource_type'=>'Nota técnica','generated'=>true,'reviewed'=>false,'summary'=>'Resumen']);
        $saved=Content::save('resource',['title'=>'Nota IA revisada','body'=>'Contenido final','status'=>'publish','meta'=>['resource_type'=>'Nota técnica']],$id);
        self::assertSame('publish',$saved['status']);
        self::assertTrue((bool)get_post_meta($id,'_ascla',true)['reviewed']);
    }

    public function testExecutiveInheritsForumCreationAndModeratorDirectHubPublication(): void
    {
        // All community roles retain the member lane: forums can be created without moderation powers.
        wp_set_current_user($this->executive);
        $forum = $this->api('POST', 'content/forum', ['title' => 'Foro ' . bin2hex(random_bytes(4)), 'body' => 'Cuerpo', 'status' => 'publish', 'meta' => []]);
        self::assertSame(200,$forum->get_status());$this->posts[]=$forum->get_data()['id'];
        self::assertSame('publish',$forum->get_data()['status']);

        // The executive inherits the moderator capability, including direct Hub publication.
        $hub = $this->api('POST', 'content/hub', ['title' => 'Idea ' . bin2hex(random_bytes(4)), 'body' => 'Cuerpo', 'status' => 'publish', 'meta' => []]);
        self::assertSame(200, $hub->get_status());
        $hubData = $hub->get_data();
        $this->posts[] = $hubData['id'];
        self::assertSame('publish', $hubData['status'], 'El Ejecutivo hereda la publicación directa del Moderador');
    }

    public function testMemberContentIsHeldForReviewWhenTypeIsEventGalleryOrResource(): void
    {
        wp_set_current_user($this->member);
        $response = $this->api('POST', 'content/event', $this->eventPayload());
        self::assertSame(403, $response->get_status(), 'Un Asociado no puede crear directamente Eventos/Galería/Conocimiento');
    }

    public function testModeratorAloneCannotManageEventsGalleryOrResources(): void
    {
        wp_set_current_user($this->moderator);
        $response = $this->api('POST', 'content/gallery', ['title' => 'Foto', 'body' => 'Cuerpo', 'status' => 'publish', 'meta' => []]);
        self::assertSame(403, $response->get_status(), 'El Moderador no gestiona Galería/Conocimiento/Eventos: eso es de Admin o Ejecutivo');
    }

    public function testExecutiveInheritsModerationOfOtherMembersThreadsAndComments(): void
    {
        wp_set_current_user($this->member);
        $topic = Content::save('hub', ['title' => 'Hilo', 'body' => 'Cuerpo', 'status' => 'draft', 'meta' => []]);
        $this->posts[] = $topic['id'];

        wp_set_current_user($this->executive);
        $response = $this->api('POST', 'items/' . $topic['id'] . '/moderate', ['decision' => 'approve', 'reason' => 'ok', 'reviewed' => true]);
        self::assertSame(200, $response->get_status(), 'El Ejecutivo hereda la moderación');
        self::assertSame('publish',get_post_status($topic['id']));
        wp_set_current_user($this->member);$comment=Content::comment($topic['id'],'Comentario para moderar');
        wp_set_current_user($this->executive);
        self::assertSame(200,$this->api('POST','admin/comments/'.$comment['id'],['decision'=>'approve'])->get_status());
        self::assertSame(200,$this->api('DELETE','comments/'.$comment['id'])->get_status());
        self::assertSame(200,$this->api('GET','admin/contacts')->get_status());
    }

    public function testExecutiveHasNoAccessToAdminOnlyManagement(): void
    {
        wp_set_current_user($this->executive);
        self::assertSame(403, $this->api('GET', 'admin/users')->get_status(), 'Gestión de usuarios es sólo de Admin');
        self::assertSame(403, $this->api('POST', 'settings', ['demo' => true])->get_status(), 'Configuración del entorno es sólo de Admin');
        self::assertSame(403, $this->api('POST', 'ai/test')->get_status(), 'Integraciones/IA es sólo de Admin');
        self::assertSame(403, $this->api('POST', 'admin/member/' . $this->member, ['suspended' => true])->get_status(), 'Suspender miembros es sólo de Admin');
    }

    public function testExecutiveCanTriggerResourceProcessingJobsButModeratorCannot(): void
    {
        wp_set_current_user($this->executive);
        $resource = Content::save('resource', ['title' => 'Nota ' . bin2hex(random_bytes(4)), 'body' => 'Cuerpo', 'status' => 'publish', 'meta' => ['youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']]);
        $this->posts[] = $resource['id'];

        $ok = $this->api('POST', 'jobs', ['kind' => 'video_metadata', 'resource_id' => $resource['id']]);
        self::assertSame(200, $ok->get_status(), 'El Ejecutivo debe poder procesar sus grabaciones/recursos (subir ponencias)');

        wp_set_current_user($this->moderator);
        $blocked = $this->api('POST', 'jobs', ['kind' => 'video_metadata', 'resource_id' => $resource['id']]);
        self::assertSame(403, $blocked->get_status(), 'El Moderador no gestiona procesamiento de recursos multimedia');
    }

    public function testAdminRetainsFullAccessAcrossAllLanes(): void
    {
        wp_set_current_user($this->admin);
        $event=$this->api('POST','content/event',$this->eventPayload());
        self::assertSame(200,$event->get_status());$this->posts[]=$event->get_data()['id'];
        self::assertSame(200, $this->api('GET', 'admin/users')->get_status());
        $data = $this->api('POST', 'content/event', $this->eventPayload())->get_data();
        $this->posts[] = $data['id'];
    }
    public function testEveryRoleCanCreateForumsButOnlyOwnerOrModeratorCanEditCommunityContent(): void
    {
        foreach($this->users as $user){
            wp_set_current_user($user);
            $boot=$this->api('GET','bootstrap')->get_data();self::assertTrue($boot['can_create']['forum']);
            $response=$this->api('POST','content/forum',['title'=>'Foro del rol','body'=>'Conversación de la comunidad','status'=>'publish']);
            self::assertSame(200,$response->get_status());$forum=$response->get_data();$this->posts[]=$forum['id'];
            self::assertSame('publish',$forum['status']);self::assertTrue($forum['can_edit']);
        }
        wp_set_current_user($this->executive);
        $peer=$this->api('GET','items/'.$forum['id']);self::assertTrue($peer->get_data()['can_edit']);
        self::assertSame(200,$this->api('POST','content/forum/'.$forum['id'],['title'=>'Revisión del ejecutivo','body'=>'Cambio autorizado','status'=>'publish'])->get_status());
        wp_set_current_user($this->member);
        self::assertFalse($this->api('GET','items/'.$this->posts[0])->get_data()['can_edit']);
        self::assertSame(403,$this->api('POST','content/forum/'.$this->posts[0],['title'=>'No permitido','body'=>'Cambio'])->get_status());
        wp_set_current_user($this->moderator);self::assertTrue($this->api('GET','items/'.$forum['id'])->get_data()['can_edit']);
        wp_set_current_user(0);self::assertSame(401,$this->api('POST','content/forum',['title'=>'Invitado','body'=>'No autorizado'])->get_status());
    }
    public function testModeratorCanApproveHideAndDeleteOtherMembersCommunityContributions(): void
    {
        foreach(['hub','forum','topic'] as $type){
            wp_set_current_user($this->member);
            $response=$this->api('POST','content/'.$type,['title'=>'Aporte '.$type,'body'=>'Contenido para moderar','status'=>'publish']);
            self::assertSame(200,$response->get_status());$id=$response->get_data()['id'];$this->posts[]=$id;
            wp_set_current_user($this->moderator);
            $item=$this->api('GET','items/'.$id)->get_data();self::assertTrue($item['can_moderate']);self::assertTrue($item['can_delete']);
            self::assertSame(200,$this->api('POST','items/'.$id.'/moderate',['decision'=>'approve','reason'=>'Cumple las normas'])->get_status());
            self::assertSame('publish',get_post_status($id));
            self::assertSame(200,$this->api('POST','items/'.$id.'/moderate',['decision'=>'hide','reason'=>'Revisión de normas'])->get_status());
            self::assertSame('ascla_hidden',get_post_status($id));
            self::assertSame(200,$this->api('DELETE','items/'.$id)->get_status());self::assertSame('trash',get_post_status($id));
        }
    }
    public function testModeratorCanDeleteCommunityCommentsWithoutManagingEditorialPublication(): void
    {
        wp_set_current_user($this->admin);$post=Content::save('event',$this->eventPayload());$this->posts[]=$post['id'];
        wp_set_current_user($this->member);$comment=Content::comment($post['id'],'Comentario de asociado');
        wp_set_current_user($this->moderator);
        $event=$this->api('GET','items/'.$post['id'])->get_data();self::assertFalse($event['can_edit']);self::assertFalse($event['can_moderate']);self::assertFalse($event['can_delete']);
        self::assertSame(403,$this->api('DELETE','items/'.$post['id'])->get_status());
        self::assertSame(403,$this->api('POST','items/'.$post['id'].'/moderate',['decision'=>'hide','reason'=>'No permitido'])->get_status());
        self::assertTrue(Content::comments($post['id'])[0]['can_delete']);
        self::assertSame(200,$this->api('DELETE','comments/'.$comment['id'])->get_status());self::assertSame('trash',wp_get_comment_status($comment['id']));
    }
    public function testExecutiveCanReviewOwnEditorialDraftButCannotReadOrEditForeignDrafts(): void
    {
        wp_set_current_user($this->executive);$own=Content::save('resource',['title'=>'Recurso propio','body'=>'Borrador','status'=>'draft']);$this->posts[]=$own['id'];
        self::assertTrue($own['can_edit']);self::assertTrue($own['can_moderate']);
        self::assertSame(200,$this->api('POST','items/'.$own['id'].'/moderate',['decision'=>'approve','reason'=>'Contenido revisado'])->get_status());
        wp_set_current_user($this->admin);$foreign=Content::save('resource',['title'=>'Borrador de administración','body'=>'Información reservada','status'=>'draft']);$this->posts[]=$foreign['id'];
        foreach([$this->executive,$this->moderator,$this->member] as $user){
            wp_set_current_user($user);self::assertSame(404,$this->api('GET','items/'.$foreign['id'])->get_status());
        }
        wp_set_current_user($this->admin);wp_update_post(['ID'=>$foreign['id'],'post_status'=>'publish']);wp_set_current_user($this->executive);
        self::assertFalse($this->api('GET','items/'.$foreign['id'])->get_data()['can_edit']);
        self::assertSame(403,$this->api('POST','content/resource/'.$foreign['id'],['title'=>'No permitido','body'=>'No permitido'])->get_status());
    }
    public function testAdminPayloadAndJobsDoNotExposeOtherRolesPrivateData(): void
    {
        wp_set_current_user($this->member);$private=Content::save('hub',['title'=>'Borrador de asociado','body'=>'Texto reservado','status'=>'draft']);$this->posts[]=$private['id'];
        $question=Queue::enqueue('answer',['question'=>'Consulta privada de asociado']);
        Store::insert('relations',['user_id'=>$this->member,'target_id'=>$private['id'],'kind'=>'report','reason'=>'other','detail'=>'Detalle reservado','created_at'=>current_time('mysql',true)]);
        wp_insert_comment(['comment_post_ID'=>$private['id'],'user_id'=>$this->member,'comment_content'=>'Comentario pendiente reservado','comment_approved'=>0]);
        wp_set_current_user($this->executive);$editorial=Content::save('resource',['title'=>'Borrador editorial','body'=>'Texto editorial','status'=>'draft']);$this->posts[]=$editorial['id'];
        $own=Queue::enqueue('video_metadata',['resource_id'=>$editorial['id']]);
        $data=$this->api('GET','admin')->get_data();self::assertContains($editorial['id'],array_column($data['pending'],'id'));self::assertContains($private['id'],array_column($data['pending'],'id'));
        self::assertNotEmpty($data['comments']);self::assertNotEmpty($data['reports']);self::assertSame([],$data['audit']);
        self::assertNotContains($question['id'],array_map('intval',array_column($data['jobs'],'id')));self::assertContains($own['id'],array_map('intval',array_column($data['jobs'],'id')));
        wp_set_current_user($this->moderator);$data=$this->api('GET','admin')->get_data();
        self::assertContains($private['id'],array_column($data['pending'],'id'));self::assertNotContains($editorial['id'],array_column($data['pending'],'id'));self::assertSame([],$data['audit']);
        foreach([$question['id'],$own['id']] as $id){self::assertSame(404,$this->api('GET','jobs/'.$id)->get_status());self::assertSame(404,$this->api('POST','jobs/'.$id.'/retry')->get_status());}
        wp_set_current_user($this->admin);$data=$this->api('GET','admin')->get_data();
        self::assertContains($private['id'],array_column($data['pending'],'id'));self::assertContains($editorial['id'],array_column($data['pending'],'id'));self::assertNotEmpty($data['audit']);
        self::assertSame(200,$this->api('GET','jobs/'.$question['id'])->get_status());
        wp_set_current_user($this->member);self::assertSame(403,$this->api('GET','admin')->get_status());
    }
    public function testEventManagementBelongsToExecutiveAndAdminWhileOtherRolesCanRegister(): void
    {
        wp_set_current_user($this->executive);$event=Content::save('event',$this->eventPayload());$this->posts[]=$event['id'];
        self::assertSame(200,$this->api('POST','events/'.$event['id'].'/invite',['users'=>[$this->member]])->get_status());
        self::assertArrayHasKey('participants',$this->api('GET','events/'.$event['id'])->get_data());
        foreach([$this->moderator,$this->member] as $user){
            wp_set_current_user($user);self::assertSame(403,$this->api('POST','events/'.$event['id'].'/invite',['users'=>[$this->admin]])->get_status());
            self::assertArrayNotHasKey('participants',$this->api('GET','events/'.$event['id'])->get_data());
            self::assertSame(200,$this->api('POST','events/'.$event['id'].'/register',['status'=>'accepted'])->get_status());
            foreach(['admin/users','settings'] as $route){self::assertSame(403,$this->api('GET',$route)->get_status());}
        }
    }

}
