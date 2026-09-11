<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Connections,Messaging,Profiles,Notifications};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Rest\ApiException;

final class ConnectionsTest extends TestCase
{
    private array $users=[],$conversations=[]; private int $schema;
    protected function setUp(): void
    {
        $this->schema=(int)get_option('ascla_schema');
        foreach(['Alba','Bruno','Celia'] as $name) {
            $id=wp_insert_user(['user_login'=>'conn_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>'ascla_member','display_name'=>$name]);
            $this->users[]=$id; wp_set_current_user($id); Profiles::save(['first_name'=>$name,'last_name'=>'Prueba','networking'=>true,'directory'=>true]);
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach($this->conversations as $id) { foreach(['messages','participants'] as $table) Store::delete($table,['conversation_id'=>$id]); Store::delete('conversations',['id'=>$id]); }
        foreach($this->users as $id) { foreach(['relations','notifications','media'] as $table) Store::delete($table,['user_id'=>$id]); wp_delete_user($id); }
        self::assertSame($this->schema,(int)get_option('ascla_schema')); wp_set_current_user(0);
    }
    private function api(string $method,string $path,array $params=[]): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$path); foreach($params as $k=>$v) $r->set_param($k,$v); return rest_do_request($r);
    }
    private function accept(): void
    {
        wp_set_current_user($this->users[0]); $r=Connections::request($this->users[1]);
        wp_set_current_user($this->users[1]); Connections::respond($r['request_id'],'accept'); wp_set_current_user($this->users[0]);
    }
    public function testReceiverCanAcceptAndBothDirectionsBecomeConnectedWithoutDuplicateRequests(): void
    {
        [$a,$b,$c]=$this->users;
        $sent=$this->api('POST','relations',['target'=>$b,'kind'=>'connect','active'=>true]); self::assertSame(200,$sent->get_status());
        $id=$sent->get_data()['request_id']; self::assertSame('outgoing_pending',Connections::between($a,$b)['state']);
        self::assertSame(409,$this->api('POST','relations',['target'=>$b,'kind'=>'connect','active'=>true])->get_status());
        self::assertSame(404,$this->api('POST','connections/'.$id.'/respond',['decision'=>'accept'])->get_status());
        wp_set_current_user($c); self::assertSame(404,$this->api('POST','connections/'.$id.'/respond',['decision'=>'reject'])->get_status());
        wp_set_current_user($b);
        self::assertSame('incoming_pending',Connections::profile($a)['connection']['state']); self::assertCount(1,Connections::listing()['incoming']);
        self::assertSame(409,$this->api('POST','relations',['target'=>$a,'kind'=>'connect','active'=>true])->get_status());
        self::assertSame(200,$this->api('POST','connections/'.$id.'/respond',['decision'=>'accept'])->get_status());
        self::assertTrue(Connections::areConnected($a,$b)); self::assertTrue(Connections::areConnected($b,$a));
        self::assertSame(1,Store::count('relations',"user_id=%d AND target_id=%d AND kind='connected'",[$a,$b]));
        self::assertCount(0,Connections::listing()['incoming']); self::assertSame('connected',Connections::profile($a)['connection']['state']);
        self::assertSame(404,$this->api('POST','connections/'.$id.'/respond',['decision'=>'accept'])->get_status());
        self::assertFalse(Connections::between($a,$b)['can_request']);
        foreach(Notifications::list() as $notice) self::assertStringNotContainsString('quiere conectar',$notice['title']);
    }
    public function testRejectDoesNotConnectAndAllowsNewRequest(): void
    {
        [$a,$b]=$this->users; $id=Connections::request($b)['request_id']; wp_set_current_user($b);
        self::assertSame('none',Connections::respond($id,'reject')['state']); self::assertNull(Store::one('relations',$id));
        self::assertFalse(Connections::areConnected($a,$b)); self::assertCount(0,Connections::listing()['incoming']);
        wp_set_current_user($a); self::assertGreaterThan($id,Connections::request($b)['request_id']);
    }
    public function testRN010ProtectsLegacyConversationsBeforeAcceptanceAndAllowsBothSendersAfterwards(): void
    {
        [$a,$b,$c]=$this->users;
        $pair=[$a,$b];sort($pair);$id=Store::insert('conversations',['pair_key'=>implode(':',$pair),'updated_at'=>current_time('mysql',true)]);$this->conversations[]=$id;
        foreach([$a,$b] as $user) Store::insert('participants',['conversation_id'=>$id,'user_id'=>$user,'last_read'=>0]);
        Store::insert('messages',['conversation_id'=>$id,'sender_id'=>$a,'body'=>'Legacy private body','created_at'=>current_time('mysql',true)]);
        foreach([false,true] as $pending) {
            if ($pending) Connections::request($b);
            foreach([$a,$b] as $user) {
                wp_set_current_user($user);
                self::assertSame(403,$this->api('POST','conversations',['target'=>$user===$a?$b:$a])->get_status());
                self::assertSame(403,$this->api('GET','conversations/'.$id)->get_status());
                self::assertSame(403,$this->api('GET','conversations/'.$id.'/messages',['after'=>0])->get_status());
                self::assertSame(403,$this->api('POST','conversations/'.$id.'/messages',['body'=>'Blocked'])->get_status());
                self::assertSame([],Messaging::conversations());
            }
            wp_set_current_user($a);
        }
        self::assertSame(1,Store::count('messages','conversation_id=%d',[$id]));
        wp_set_current_user($c);self::assertSame(404,$this->api('GET','conversations/'.$id.'/messages')->get_status());
        wp_set_current_user($b); Connections::respond(Connections::between($b,$a)['request_id'],'accept');
        foreach([$a,$b] as $user) {
            wp_set_current_user($user); $conversation=Messaging::start($user===$a?$b:$a); self::assertSame($id,(int)$conversation['id']);
            self::assertSame($user,(int)Messaging::send($id,'Confirmed sender')['sender_id']);self::assertCount(1,Messaging::conversations());
        }
        self::assertCount(3,Messaging::messages($id)['items']);
        wp_set_current_user($c);self::assertSame(403,$this->api('POST','conversations',['target'=>$a])->get_status());
    }
    public function testSelfInvalidIdsAndAnonymousActionsAreBlocked(): void
    {
        $me=get_current_user_id();
        foreach([$me,0,-1,PHP_INT_MAX] as $id) {
            self::assertSame(400,$this->api('POST','relations',['target'=>$id,'kind'=>'connect','active'=>true])->get_status());
            self::assertSame(400,$this->api('POST','conversations',['target'=>$id])->get_status());
        }
        wp_set_current_user(0);
        foreach([['GET','connections'],['POST','connections/1/respond'],['POST','conversations'],['GET','conversations/1/messages']] as [$method,$route]) self::assertSame(401,$this->api($method,$route,['decision'=>'accept'])->get_status());
    }
    public function testBlockingAndRevocationAreEnforcedForExistingConversation(): void
    {
        [$a,$b]=$this->users;$this->accept();$id=(int)Messaging::start($b)['id'];$this->conversations[]=$id;
        Messaging::send($id,'Before change'); wp_set_current_user($b); Messaging::relation($a,'block',true);
        self::assertFalse(Connections::between($a,$b)['can_message']);
        wp_set_current_user($a);self::assertSame(403,$this->api('POST','conversations/'.$id.'/messages',['body'=>'Blocked'])->get_status());
        wp_set_current_user($b);Messaging::relation($a,'block',false); Messaging::send($id,'After unblock');
        $connection=Connections::between($a,$b)['connection_id']; Store::delete('relations',['id'=>$connection]);
        self::assertSame(403,$this->api('GET','conversations/'.$id)->get_status());self::assertSame([],Messaging::conversations());
        self::assertSame(403,$this->api('POST','conversations/'.$id.'/messages',['body'=>'Revoked'])->get_status());
        self::assertSame(2,Store::count('messages','conversation_id=%d',[$id]));
    }
    public function testLegacyReciprocalRequestsRequireExplicitConsentAndAreNormalizedOnDecision(): void
    {
        [$a,$b]=$this->users; $first=Connections::request($b)['request_id'];
        Store::insert('relations',['user_id'=>$b,'target_id'=>$a,'kind'=>'connect','created_at'=>current_time('mysql',true)]);
        self::assertFalse(Connections::areConnected($a,$b)); wp_set_current_user($b);Connections::respond($first,'accept');
        self::assertSame(0,Store::count('relations',"kind='connect' AND (user_id=%d OR user_id=%d)",[$a,$b]));
        self::assertSame(1,Store::count('relations',"kind='connected' AND (user_id=%d OR user_id=%d)",[$a,$b]));
    }
    public function testChatPhotoUsesTheSamePrivateMediaUrlAndHonorsPrivacyAndMissingImages(): void
    {
        [$a,$b]=$this->users; $this->accept();
        wp_set_current_user($b); $photo=Store::insert('media',['user_id'=>$b,'post_id'=>0,'name'=>'test.png','mime'=>'image/png','bytes'=>'synthetic-test-bytes','created_at'=>current_time('mysql',true)]);
        Profiles::save(['photo_id'=>$photo]);wp_set_current_user($a);
        $id=(int)Messaging::start($b)['id'];$this->conversations[]=$id;$p=Profiles::visible($b);$chat=Messaging::conversation($id);
        self::assertNotEmpty($p['photo_url']);self::assertSame($p['photo_url'],$chat['other']['photo_url']);self::assertStringContainsString('member='.$b,$chat['other']['profile_url']);
        wp_set_current_user($b);Profiles::save(['hidden'=>['photo_id','first_name']]);wp_set_current_user($a);
        $chat=Messaging::conversation($id);self::assertSame('',$chat['other']['photo_url']);self::assertSame('Asociado ASCLA '.$b,$chat['other']['name']);
        wp_set_current_user($b);Profiles::save(['hidden'=>[]]);Store::delete('media',['id'=>$photo]);wp_set_current_user($a);
        self::assertSame('',Messaging::conversation($id)['other']['photo_url']);
    }
}
