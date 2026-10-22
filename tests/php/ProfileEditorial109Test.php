<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Profiles,MemberTypes,Content,EditorialRegistry,ConversationRequests,Messaging,ContentSchedule};
use ASCLA\Core\Repositories\Store;

final class ProfileEditorial109Test extends TestCase
{
    private array $users=[],$posts=[];
    protected function setUp(): void
    {
        foreach (['administrator','ascla_executive','ascla_executive','ascla_member','ascla_member'] as $role) {
            $login='editorial109_'.bin2hex(random_bytes(5));
            $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
            $this->users[]=$id;wp_set_current_user($id);Profiles::save(ascla_test_profile(['networking'=>true,'directory'=>true]));
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]);
        foreach ($this->posts as $id) { wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]); }
        foreach ($this->users as $id) {
            foreach (['relations','notifications','jobs'] as $table) { Store::delete($table,['user_id'=>$id]); }
            Store::delete('relations',['target_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function api(string $method,string $route,array $data=[]): WP_REST_Response
    {
        $request=new WP_REST_Request($method,'/ascla/v1/'.$route);
        if ($method==='GET') { $request->set_query_params($data); }
        else { $request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($data)); }
        return rest_do_request($request);
    }
    private function create(string $type): int
    {
        $item=Content::save($type,['title'=>'Editorial '.bin2hex(random_bytes(5)),'body'=>'Conservar contenido e historial.','status'=>'publish','meta'=>['chatham'=>false]]);
        $this->posts[]=$item['id'];return $item['id'];
    }
    public function testIndividualLabelKeepsExistingStoredCodesAndLegacyLabels(): void
    {
        self::assertSame('Individual',MemberTypes::OPTIONS['independent']);
        foreach (['independent','independiente','Individual'] as $value) self::assertSame('independent',MemberTypes::selected($value));
        self::assertSame('company',MemberTypes::selected('Empresa asociada'));
    }
    public function testSocialDomainsRejectLookalikesCredentialsAndUnexpectedPortsInBothProfileAndAdminWrites(): void
    {
        foreach ([
            ['linkedin','https://example.com/in/perfil'],['linkedin','https://linkedin.com.evil.example/perfil'],
            ['linkedin','https://linkedin.com@evil.example/perfil'],['linkedin','https://evil@linkedin.com/in/perfil'],
            ['twitter','https://evil.example/?url=https://x.com'],['twitter','https://x.com.evil.example'],
            ['twitter','https://twitter.com:8443/user'],
        ] as [$field,$url]) {
            wp_set_current_user($this->users[3]);
            $response=$this->api('POST','profiles/me',[$field=>$url]);
            self::assertSame(400,$response->get_status(),$url);
            wp_set_current_user($this->users[0]);
            self::assertSame(400,$this->api('POST','admin/users/'.$this->users[3],[$field=>$url])->get_status(),$url);
        }
    }
    public function testValidSocialUrlsAndClearingFieldsAreAcceptedWhileWebsiteRemainsIndependent(): void
    {
        foreach ([
            ['linkedin','https://www.linkedin.com/in/persona'],['linkedin','https://pe.linkedin.com/company/ascla'],
            ['twitter','https://x.com/ascla'],['twitter','https://mobile.twitter.com/ascla'],
            ['website','https://example.invalid/mi-sitio'],['linkedin',''],['twitter',''],
        ] as [$field,$url]) self::assertSame($url,Profiles::details([],[$field=>$url])[$field]);
    }
    public function testGalleryAndResourceCanBeHiddenListedAndRepublishedWithoutLosingData(): void
    {
        foreach (['gallery','resource'] as $type) {
            $id=$this->create($type);$title=get_the_title($id);
            self::assertSame(200,$this->api('POST','items/'.$id.'/moderate',['decision'=>'hide','reason'=>'Retiro temporal'])->get_status());
            self::assertSame('ascla_hidden',get_post_status($id));self::assertSame($title,get_the_title($id));
            $list=$this->api('GET','admin/editorial/'.$type,['status'=>'ascla_hidden','q'=>$title]);
            self::assertSame(200,$list->get_status());self::assertContains($id,array_column($list->get_data()['items'],'id'));
            $history=EditorialRegistry::history($type,$id);self::assertSame('ascla_hidden',$history[0]['to']);self::assertSame('Retiro temporal',$history[0]['reason']);
            wp_set_current_user($this->users[3]);
            self::assertSame(404,$this->api('GET','items/'.$id)->get_status());
            self::assertSame(0,$this->api('GET','content/'.$type,['q'=>$title])->get_data()['total']);
            self::assertSame(403,$this->api('GET','admin/editorial/'.$type)->get_status());
            wp_set_current_user($this->users[0]);
            self::assertSame(200,$this->api('POST','items/'.$id.'/moderate',['decision'=>'approve','reason'=>'Retiro resuelto'])->get_status());
            self::assertSame('publish',get_post_status($id));
            self::assertCount(3,EditorialRegistry::history($type,$id));
        }
    }
    public function testEditorHiddenStatusAndSchedulingCancellationAreSupported(): void
    {
        $id=$this->create('gallery');
        Content::save('gallery',['title'=>get_the_title($id),'body'=>'Texto conservado','status'=>'scheduled','publish_at'=>gmdate('c',time()+DAY_IN_SECONDS)],$id);
        self::assertNotEmpty(get_post_meta($id,'_ascla_publish_timestamp',true));
        Content::save('gallery',['title'=>get_the_title($id),'body'=>'Texto conservado','status'=>'ascla_hidden'],$id);
        self::assertSame('ascla_hidden',get_post_status($id));
        self::assertEmpty(get_post_meta($id,'_ascla_publish_timestamp',true));
        self::assertArrayNotHasKey('publish_at',get_post_meta($id,'_ascla',true));
    }
    public function testExecutiveRegistryAndStateChangesIncludeOtherEditorsContent(): void
    {
        wp_set_current_user($this->users[1]);$id=$this->create('resource');
        wp_set_current_user($this->users[2]);
        self::assertContains($id,array_column(EditorialRegistry::listing('resource',[])['items'],'id'));
        self::assertSame(200,$this->api('POST','items/'.$id.'/moderate',['decision'=>'hide','reason'=>'Retiro administrativo'])->get_status());
        self::assertSame(200,$this->api('GET','admin/editorial/resource/'.$id.'/history')->get_status());
        wp_set_current_user($this->users[1]);
        self::assertSame(200,$this->api('POST','items/'.$id.'/moderate',['decision'=>'approve','reason'=>'Revisión del autor'])->get_status());
    }
    public function testActiveConnectionOverridesLegacyPendingConversationRequest(): void
    {
        [$a,$b]=array_slice($this->users,3);
        foreach (['conversation_request','connected'] as $kind) Store::insert('relations',['user_id'=>$a,'target_id'=>$b,'kind'=>$kind,'created_at'=>current_time('mysql',true)]);
        foreach ([$a,$b] as $id) {
            wp_set_current_user($id);$other=$id===$a?$b:$a;
            $state=ConversationRequests::between($id,$other);
            self::assertSame('connected',$state['state']);self::assertSame(0,$state['request_id']);self::assertTrue($state['can_message']);
        }
    }
}
