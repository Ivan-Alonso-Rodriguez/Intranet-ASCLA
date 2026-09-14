<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Notifications,Content,Profiles,Messaging};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Domain\Catalog;

final class NotificationsTest extends TestCase
{
    private array $users=[];
    private array $posts=[];
    private array $conversations=[];
    protected function setUp(): void
    {
        foreach (['Elena Prueba','Diego Prueba','Otra Persona'] as $name) {
            $id=wp_insert_user(['user_login'=>'notice_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'display_name'=>$name,'role'=>'ascla_member']);
            self::assertIsInt($id); $this->users[]=$id;
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->conversations as $id) {
            Store::delete('conversations',['id'=>$id]); Store::delete('participants',['conversation_id'=>$id]); Store::delete('messages',['conversation_id'=>$id]);
        }
        foreach ($this->users as $id) {
            foreach (['notifications','jobs','relations'] as $table) { Store::delete($table,['user_id'=>$id]); }
            wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function post(string $type='hub'): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_title'=>'Decisiones de la junta','post_content'=>'Contenido de prueba','post_status'=>'publish','post_author'=>$this->users[1]]);
        $this->posts[]=$id; return $id;
    }
    private function api(string $method,string $path): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request($method,'/ascla/v1/'.$path));
    }
    public function testReactionHasAuthorSubjectAndSafeDestination(): void
    {
        $id=$this->post(); Content::react($id,'like',true); wp_set_current_user($this->users[1]);
        $notice=Notifications::list()[0];
        self::assertSame('Elena Prueba reaccionó a tu publicación',$notice['title']);
        self::assertSame('Decisiones de la junta',$notice['description']);
        self::assertStringContainsString('item='.$id,$notice['url']);
        self::assertNull(Store::one('notifications',$notice['id'])['read_at']);
        self::assertSame($notice['url'],Notifications::open($notice['id'])['url']);
        self::assertNotNull(Store::one('notifications',$notice['id'])['read_at']);
    }
    public function testOwnCommentDoesNotNotifyOwnPublication(): void
    {
        $id=wp_insert_post(['post_type'=>'ascla_hub','post_title'=>'Publicación propia','post_content'=>'Contenido propio','post_status'=>'publish','post_author'=>$this->users[0]]);
        $this->posts[]=$id;
        Content::comment($id,'Comentario de la misma persona autora.');
        self::assertSame(0,Store::count('notifications','user_id=%d AND kind=%s',[$this->users[0],'comment']));
    }

    public function testCommentAndReplyNotificationsIdentifyTheAssociate(): void
    {
        $id=$this->post();
        wp_set_current_user($this->users[0]);
        $first=Content::comment($id,'Comentario de Elena para Diego.');
        wp_set_current_user($this->users[1]);
        $notice=Notifications::list()[0];
        self::assertSame('Elena Prueba comentó en tu publicación',$notice['title']);

        Content::comment($id,'Respuesta de Diego para Elena.',$first['id']);
        wp_set_current_user($this->users[0]);
        $reply=Notifications::list()[0];
        self::assertSame('Diego Prueba respondió a tu comentario',$reply['title']);
    }

    public function testUnavailableTargetAndChathamNeverExposeOldIdentities(): void
    {
        $id=$this->post(); update_post_meta($id,'_ascla',['chatham'=>true]);
        Notifications::send($this->users[0],'mention','Old private title','',['type'=>'post','id'=>$id,'actor'=>$this->users[1]]);
        $notice=Notifications::list()[0]; self::assertStringNotContainsString('Diego',wp_json_encode($notice));
        wp_update_post(['ID'=>$id,'post_status'=>'draft']);
        $notice=Notifications::list()[0]; self::assertFalse($notice['available']);
        self::assertStringNotContainsString('Decisiones',wp_json_encode($notice));
        self::assertStringNotContainsString('Old private',wp_json_encode($notice));
        self::assertStringContainsString('notice=unavailable',$notice['url']);
        wp_delete_post($id,true); self::assertFalse(Notifications::list()[0]['available']);
    }
    public function testFeedCountsBeyondOneHundredAndReadAllIsOwned(): void
    {
        for ($i=0;$i<105;$i++) { Notifications::send($this->users[0],'welcome','Hola'); }
        Notifications::send($this->users[1],'welcome','Hola');
        $first=Notifications::feed([]); $last=Notifications::feed(['page'=>999]);
        self::assertSame(105,$first['unread_total']); self::assertCount(20,$first['items']);
        self::assertSame(6,$last['page']); self::assertCount(5,$last['items']);
        wp_set_current_user($this->users[1]);
        self::assertSame(404,$this->api('POST','notifications/'.$first['items'][0]['id'].'/open')->get_status());
        Notifications::read($first['items'][0]['id']); self::assertNull(Store::one('notifications',$first['items'][0]['id'])['read_at']);
        wp_set_current_user($this->users[0]); Notifications::readAll();
        self::assertSame(0,Notifications::summary()['unread_total']); self::assertCount(0,Notifications::feed(['filter'=>'unread'])['items']);
        self::assertSame(105,Notifications::feed([])['total']);
        wp_set_current_user($this->users[1]); self::assertSame(1,Notifications::summary()['unread_total']);
    }
    public function testLegacyLinksRebuildOnCurrentSiteAndUnknownLinksHaveFallback(): void
    {
        $id=$this->post();
        Notifications::send($this->users[0],'comment','Old title','https://old.example/hub/?item='.$id);
        self::assertStringStartsWith(home_url(),Notifications::list()[0]['url']);
        global $wp_rewrite; $permalink=get_option('permalink_structure');
        try {
            $wp_rewrite->set_permalink_structure('');
            $url=Notifications::list()[0]['url']; self::assertStringContainsString('page_id=',$url); self::assertStringContainsString('item='.$id,$url);
        } finally { $wp_rewrite->set_permalink_structure($permalink); }
        foreach (['reaction','job','welcome','unknown'] as $kind) {
            Notifications::send($this->users[0],$kind,'Aviso anterior','https://outside.example/');
            $n=Notifications::list()[0]; self::assertStringStartsWith(home_url(),$n['url']); self::assertNotEmpty($n['action_label']);
        }
        self::assertStringNotContainsString('outside.example',wp_json_encode(Notifications::list()));
    }
    public function testDirectConversationAndProfileDestinationsCheckAccess(): void
    {
        Store::insert('relations',['user_id'=>$this->users[0],'target_id'=>$this->users[1],'kind'=>'connected','created_at'=>current_time('mysql',true)]); $c=Messaging::start($this->users[1]); $this->conversations[]=(int)$c['id']; Messaging::send((int)$c['id'],'Mensaje de prueba');
        wp_set_current_user($this->users[1]); $notice=Notifications::list()[0];
        self::assertSame('Elena Prueba te envió un mensaje',$notice['title']);
        self::assertStringContainsString('conversation='.$c['id'],$notice['url']);
        self::assertSame('Mensaje de prueba',Messaging::conversation((int)$c['id'])['preview']);
        wp_set_current_user($this->users[2]); self::assertSame(404,$this->api('GET','conversations/'.$c['id'])->get_status());
        Notifications::send($this->users[2],'message','Private','',['type'=>'conversation','id'=>$c['id']]);
        self::assertFalse(Notifications::list()[0]['available']);
        Notifications::send($this->users[2],'connection','Conexión','',['type'=>'profile','id'=>$this->users[0]]);
        self::assertStringContainsString('member='.$this->users[0],Notifications::list()[0]['url']);
        wp_set_current_user($this->users[0]); Profiles::save(['directory'=>false]);
        wp_set_current_user($this->users[2]); self::assertFalse(Notifications::list()[0]['available']);
    }
    public function testSavedAnswersHistoryOwnershipAndRevokedSources(): void
    {
        $source=$this->post('resource');
        $id=Store::insert('jobs',['user_id'=>$this->users[0],'kind'=>'answer','payload'=>wp_json_encode(['question'=>'¿Cómo supervisar riesgos?']),'status'=>'completed','result'=>wp_json_encode(['answer'=>'Texto privado de la fuente','sources'=>[['id'=>$source,'title'=>'Decisiones de la junta','url'=>Catalog::url('centro-conocimiento',['item'=>$source])]],'mode'=>'DEMO MODE']),'created_at'=>current_time('mysql',true)]);
        Notifications::send($this->users[0],'job','Finalizado','',['type'=>'job','id'=>$id]);
        $n=Notifications::list()[0]; self::assertSame('¿Cómo supervisar riesgos?',$n['description']); self::assertStringContainsString('job='.$id,$n['url']);
        self::assertSame('¿Cómo supervisar riesgos?',Queue::get($id)['question']); self::assertCount(1,Queue::answers());
        wp_set_current_user($this->users[1]); self::assertCount(0,Queue::answers()); self::assertSame(404,$this->api('GET','jobs/'.$id)->get_status());
        wp_set_current_user($this->users[0]); wp_update_post(['ID'=>$source,'post_status'=>'draft']);
        $result=Queue::get($id)['result']; self::assertSame([],$result['sources']); self::assertStringNotContainsString('Texto privado',wp_json_encode($result));
    }
    public function testNotificationEndpointsRequireAuthentication(): void
    {
        wp_set_current_user(0);
        foreach (['notifications/feed','notifications/summary','answers','conversations/1'] as $path) { self::assertSame(401,$this->api('GET',$path)->get_status()); }
        foreach (['notifications/read-all','notifications/1/open'] as $path) { self::assertSame(401,$this->api('POST',$path)->get_status()); }
    }
}
