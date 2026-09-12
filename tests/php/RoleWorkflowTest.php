<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content, Access};

/**
 * Fija el contrato de "quién puede hacer qué" descrito en el diagrama de roles
 * (Admin / Ejecutivo / Moderador / Asociado). Estas pruebas existen porque la
 * versión 1.7.0 sólo permitía a Admin gestionar Eventos/Galería/Centro de
 * Conocimiento; 1.7.1 añade el rol Ejecutivo (ascla_manage_content) para esas
 * tres secciones sin darle el resto de permisos de Admin.
 */
final class RoleWorkflowTest extends TestCase
{
    private array $users = [];
    private array $posts = [];
    private int $admin, $executive, $moderator, $member;

    protected function setUp(): void
    {
        foreach (['administrator', 'ascla_executive', 'ascla_moderator', 'ascla_member'] as $role) {
            $login = 'role_' . bin2hex(random_bytes(6));
            $this->users[] = wp_insert_user(['user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password(32), 'role' => $role]);
        }
        [$this->admin, $this->executive, $this->moderator, $this->member] = $this->users;
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach ($this->posts as $id) { wp_delete_post($id, true); }
        foreach ($this->users as $id) { wp_delete_user($id); }
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

    public function testExecutiveDoesNotGainForumOrHubPublishingPowers(): void
    {
        // El Ejecutivo no crea foros nuevos (eso sigue siendo de Moderador/Admin).
        wp_set_current_user($this->executive);
        $forum = $this->api('POST', 'content/forum', ['title' => 'Foro ' . bin2hex(random_bytes(4)), 'body' => 'Cuerpo', 'status' => 'publish', 'meta' => []]);
        self::assertSame(403, $forum->get_status(), 'Crear un foro nuevo sigue siendo de Moderador/Admin, no de Ejecutivo');

        // Tampoco gana un atajo de publicación directa en Hub que no le corresponde (eso depende de
        // moderation_required, no de ser contentManager); su Hub debe comportarse igual que el de un Asociado.
        $hub = $this->api('POST', 'content/hub', ['title' => 'Idea ' . bin2hex(random_bytes(4)), 'body' => 'Cuerpo', 'status' => 'publish', 'meta' => []]);
        self::assertSame(200, $hub->get_status());
        $hubData = $hub->get_data();
        $this->posts[] = $hubData['id'];
        self::assertSame('pending', $hubData['status'], 'Ejecutivo no debe tener publicación directa en Hub: ese no es su lane');
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

    public function testExecutiveCannotModerateForumThreadsOrComments(): void
    {
        wp_set_current_user($this->member);
        $topic = Content::save('hub', ['title' => 'Hilo', 'body' => 'Cuerpo', 'status' => 'draft', 'meta' => []]);
        $this->posts[] = $topic['id'];

        wp_set_current_user($this->executive);
        $response = $this->api('POST', 'items/' . $topic['id'] . '/moderate', ['decision' => 'approve', 'reason' => 'ok', 'reviewed' => true]);
        self::assertSame(403, $response->get_status(), 'Auditar hilos/comentarios es tarea del Moderador, no del Ejecutivo');
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
        self::assertSame(200, $this->api('POST', 'content/event', $this->eventPayload())->get_status());
        self::assertSame(200, $this->api('GET', 'admin/users')->get_status());
        $data = $this->api('POST', 'content/event', $this->eventPayload())->get_data();
        $this->posts[] = $data['id'];
    }
}
