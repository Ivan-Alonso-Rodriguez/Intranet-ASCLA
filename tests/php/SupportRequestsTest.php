<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,SupportRequests,Administration,Notifications};
use ASCLA\Core\Repositories\Store;

final class SupportRequestsTest extends TestCase
{
    private array $users=[],$posts=[];
    protected function setUp(): void
    {
        foreach (['administrator','administrator','ascla_executive','ascla_moderator','ascla_member','ascla_member'] as $role) {
            $name='support_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
    }
    protected function tearDown(): void
    {
        foreach($this->posts as $id)wp_delete_post($id,true);
        foreach($this->users as $id){Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);}
        wp_set_current_user(0);
    }
    private function request(): int
    {
        wp_set_current_user($this->users[4]);
        $post=Content::save('contact',['title'=>'Asunto confidencial '.bin2hex(random_bytes(4)),'body'=>'Detalle reservado del asociado']);
        $id=(int)$post['id'];$this->posts[]=$id;wp_set_current_user($this->users[0]);SupportRequests::assign($id,$this->users[0]);return $id;
    }
    private function api(string $method,string $path,array $body=[]): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$path);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));return rest_do_request($r);
    }
    private function deny(callable $fn,int $code): void
    {
        try{$fn();self::fail('Expected rejection');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame($code,$e->getCode());}
    }
    public function testOnlyCreatorAndAssignedStaffReadContentAndSearchCannotRevealOtherCases(): void
    {
        $id=$this->request();$title=get_the_title($id);
        foreach([1,2,3,5] as $i){wp_set_current_user($this->users[$i]);self::assertSame(404,$this->api('GET','items/'.$id)->get_status());}
        wp_set_current_user($this->users[1]);
        $list=SupportRequests::listing([]);$row=array_column($list['items'],null,'id')[$id];
        self::assertTrue($row['redacted']);self::assertArrayNotHasKey('body',$row);self::assertArrayNotHasKey('title',$row);self::assertArrayNotHasKey('request_history',$row['meta']);
        self::assertSame(0,SupportRequests::listing(['q'=>$title])['total']);
        wp_set_current_user($this->users[3]);self::assertSame(403,$this->api('GET','admin/contacts')->get_status());
        wp_set_current_user($this->users[4]);self::assertSame(200,$this->api('GET','items/'.$id)->get_status());
        self::assertSame(403,$this->api('POST','content/contact/'.$id,['title'=>'Overwrite','body'=>'Changed'])->get_status());
        wp_set_current_user($this->users[5]);self::assertSame(0,Content::listing('contact',['author'=>$this->users[4]])['total']);
    }
    public function testReassignmentImmediatelyRevokesPreviousStaffAndValidatesRole(): void
    {
        $id=$this->request();$this->deny(fn()=>SupportRequests::assign($id,$this->users[3]),400);
        SupportRequests::assign($id,$this->users[2]);
        self::assertSame(404,$this->api('GET','items/'.$id)->get_status());
        $this->deny(fn()=>SupportRequests::change($id,'progress'),403);
        wp_set_current_user($this->users[2]);self::assertSame(200,$this->api('GET','items/'.$id)->get_status());
        self::assertSame('progress',SupportRequests::change($id,'progress')['meta']['request_status']);
        self::assertSame(403,$this->api('POST','admin/contact/'.$id.'/assign',['assignee'=>$this->users[0]])->get_status());
        update_user_meta($this->users[2],'_ascla_suspended',true);
        self::assertFalse(SupportRequests::canHandle($id));
    }
    public function testOrderedResolutionRequiresResponseAndArchivePreservesMemberHistory(): void
    {
        $id=$this->request();$this->deny(fn()=>SupportRequests::change($id,'closed'),409);
        SupportRequests::change($id,'progress');
        $this->deny(fn()=>SupportRequests::change($id,'resolved'),400);
        SupportRequests::change($id,'resolved','La incidencia fue corregida.');
        $this->deny(fn()=>SupportRequests::archive($id),409);
        SupportRequests::change($id,'closed');SupportRequests::archive($id);
        self::assertSame('private',get_post_status($id));self::assertNotContains($id,array_column(SupportRequests::listing([])['items'],'id'));
        self::assertContains($id,array_column(SupportRequests::listing(['archived'=>1])['items'],'id'));
        $this->deny(fn()=>SupportRequests::change($id,'progress'),409);
        wp_set_current_user($this->users[4]);$post=$this->api('GET','items/'.$id);self::assertSame(200,$post->get_status());
        self::assertStringContainsString('La incidencia fue corregida.',wp_json_encode($post->get_data()));
        self::assertContains($id,array_column(Content::listing('contact',['mine'=>1])['items'],'id'));
    }
    public function testHistoryDoesNotDiscardOldResponses(): void
    {
        $id=$this->request();SupportRequests::change($id,'progress');
        for($n=0;$n<25;$n++)SupportRequests::change($id,'progress','Respuesta '.$n);
        $history=get_post_meta($id,'_ascla',true)['request_history'];$replies=array_column($history,'response');
        self::assertContains('Respuesta 0',$replies);self::assertContains('Respuesta 24',$replies);self::assertGreaterThanOrEqual(26,count($history));
    }
    public function testLegacyResolvedStateAndHistoryAreMigratedWithoutClosingOrDiscardingContent(): void
    {
        wp_set_current_user($this->users[4]);$id=wp_insert_post(['post_type'=>'ascla_contact','post_status'=>'private','post_title'=>'Legacy','post_content'=>'Conservar','post_author'=>$this->users[4]]);$this->posts[]=$id;
        update_post_meta($id,'_ascla',['request_status'=>'closed','request_history'=>[['from'=>'progress','to'=>'closed','actor'=>'Legado','at'=>gmdate('c')]]]);
        SupportRequests::initialize($id);$meta=get_post_meta($id,'_ascla',true);
        self::assertSame('resolved',$meta['request_status']);self::assertSame('resolved',$meta['request_history'][0]['to']);self::assertSame('Conservar',get_post($id)->post_content);
        SupportRequests::initialize($id);self::assertSame($meta,get_post_meta($id,'_ascla',true));
    }
}
