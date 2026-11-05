<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Profiles,ProfileRequirements,Connections,ConnectionPolicy,Messaging,ConversationVisibility};
use ASCLA\Core\Repositories\Store;

final class AnalysisComplianceTest extends TestCase
{
    private array $users=[],$chats=[];

    protected function setUp(): void
    {
        $catalog=Profiles::catalogs();
        foreach (range(1,3) as $n) {
            $id=wp_insert_user(['user_login'=>'analysis_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>'ascla_member']);
            $this->users[]=$id;wp_set_current_user($id);
            Profiles::save(['first_name'=>'Prueba','last_name'=>'Análisis '.$n,'position'=>'Analista','company'=>'Organización',
                'city'=>'Lima','bio'=>'Descripción profesional','interests'=>[(int)$catalog['interest'][0]['id']],
                'industries'=>[(int)$catalog['industry'][0]['id']],'goals'=>[(int)$catalog['goal'][0]['id']]]);
        }
        wp_set_current_user($this->users[0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->chats as $id) {
            Store::delete('messages',['conversation_id'=>$id]);Store::delete('participants',['conversation_id'=>$id]);Store::delete('conversations',['id'=>$id]);
        }
        foreach ($this->users as $id) {
            foreach (['relations','notifications'] as $table) { Store::delete($table,['user_id'=>$id]); }
            Store::delete('relations',['target_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }

    private function api(string $method,string $path,array $body=[]): WP_REST_Response
    {
        $request=new WP_REST_Request($method,'/ascla/v1/'.$path);
        $request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($body));
        return rest_do_request($request);
    }

    private function chat(): int
    {
        [$a,$b]=$this->users;
        $request=Connections::request($b);wp_set_current_user($b);Connections::respond($request['request_id'],'accept');
        wp_set_current_user($a);$id=(int)Messaging::start($b)['id'];$this->chats[]=$id;
        return $id;
    }

    public function testIncompleteProfilesRemainDiscoverableButRequiredFieldsStillControlCompletion(): void
    {
        [$a,$b]=$this->users;
        self::assertSame(100,Profiles::completion($b)['percent']);
        self::assertSame([],ProfileRequirements::missing(Profiles::raw($b)));
        foreach (['bio','city','industries','interests','goals'] as $key) {
            $original=Profiles::raw($b);$incomplete=$original;$incomplete[$key]=is_array($incomplete[$key])?[]:'';
            update_user_meta($b,'_ascla_profile',$incomplete);
            self::assertContains($b,array_column(Profiles::directory()['items'],'id'),$key);
            self::assertNotEmpty(ProfileRequirements::missing(Profiles::raw($b)),$key);
            update_user_meta($b,'_ascla_profile',$original);
        }
        self::assertContains($b,array_column(Profiles::directory(['industries'=>Profiles::raw($b)['industries']])['items'],'id'));
        $profile=Profiles::raw($b);$profile['city']='';$profile['country']='Perú';
        self::assertSame(100,ProfileRequirements::completion($profile)['percent']);
        $profile['bio']="\u{200B} ";self::assertContains('Descripción profesional',ProfileRequirements::missing($profile));
    }

    public function testRejectedRequestHasDirectionalCooldownThatExpires(): void
    {
        [$a,$b]=$this->users;
        $request=Connections::request($b);wp_set_current_user($b);Connections::respond($request['request_id'],'reject');
        self::assertTrue(Connections::between($b,$a)['can_request']);
        wp_set_current_user($a);
        self::assertFalse(Connections::between($a,$b)['can_request']);
        self::assertSame(409,$this->api('POST','relations',['target'=>$b,'kind'=>'connect','active'=>true])->get_status());
        Store::update('relations',['created_at'=>gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS-1)],['user_id'=>$a,'target_id'=>$b,'kind'=>'connection_cooldown']);
        self::assertTrue(Connections::between($a,$b)['can_request']);
        self::assertSame('outgoing_pending',Connections::request($b)['state']);
    }

    public function testDisconnectKeepsHistoryReadOnlyAndCooldownIsReciprocal(): void
    {
        [$a,$b,$outsider]=$this->users;$id=$this->chat();Messaging::send($id,'Historial permanente');
        Connections::remove($b);
        foreach ([$a,$b] as $user) {
            wp_set_current_user($user);$other=$user===$a?$b:$a;
            self::assertCount(1,Messaging::messages($id)['items']);
            self::assertFalse(Messaging::conversation($id)['can_message']);
            self::assertFalse(Connections::between($user,$other)['can_request']);
            self::assertSame(403,$this->api('POST','conversations/'.$id.'/messages',['body'=>'No permitido'])->get_status());
        }
        wp_set_current_user($outsider);self::assertSame(404,$this->api('GET','conversations/'.$id.'/messages')->get_status());
    }

    public function testBlockingHidesBothSidesAndUnblockDoesNotRestoreConsent(): void
    {
        [$a,$b]=$this->users;$id=$this->chat();Messaging::send($id,'Conservar sin exponer');
        Messaging::relation($b,'block',true);
        self::assertCount(1,Connections::listing()['blocked']);
        foreach ([$a,$b] as $user) {
            wp_set_current_user($user);self::assertSame([],Messaging::conversations());
            self::assertSame(404,$this->api('GET','conversations/'.$id.'/messages')->get_status());
            self::assertNotContains($user===$a?$b:$a,array_column(Profiles::directory()['items'],'id'));
        }
        wp_set_current_user($a);Messaging::relation($b,'block',false);
        self::assertSame([],Messaging::conversations());self::assertFalse(Connections::areConnected($a,$b));
        self::assertSame(1,Store::count('messages','conversation_id=%d',[$id]));
        self::assertCount(0,Connections::listing()['blocked']);
    }

    public function testMessagesAreImmutableAndReadOnlyWhenExplicitlyAcknowledged(): void
    {
        [$a,$b,$outsider]=$this->users;$id=$this->chat();$message=Messaging::send($id,'Mensaje inmutable');
        self::assertSame(403,$this->api('DELETE','conversations/'.$id.'/messages/'.$message['id'])->get_status());
        self::assertNotNull(Store::one('messages',(int)$message['id']));
        wp_set_current_user($b);
        self::assertFalse(Messaging::messages($id)['items'][0]['can_delete']);
        self::assertSame(1,Messaging::conversation($id)['unread'],'Polling must not mark read');
        self::assertSame(200,$this->api('POST','conversations/'.$id.'/read',['last'=>$message['id']])->get_status());
        self::assertSame(0,Messaging::conversation($id)['unread']);
        self::assertSame(404,$this->api('POST','conversations/'.$id.'/read',['last'=>PHP_INT_MAX])->get_status());
        wp_set_current_user($outsider);self::assertSame(404,$this->api('POST','conversations/'.$id.'/read',['last'=>$message['id']])->get_status());
    }

    public function testHidingIsPersonalAndNewIncomingMessageRestoresConversation(): void
    {
        [$a,$b]=$this->users;$id=$this->chat();Messaging::send($id,'Primero');
        self::assertSame(200,$this->api('POST','conversations/'.$id.'/hide')->get_status());
        self::assertSame([],Messaging::conversations());
        wp_set_current_user($b);self::assertCount(1,Messaging::conversations());
        Messaging::send($id,'Nueva respuesta');
        wp_set_current_user($a);self::assertCount(1,Messaging::conversations());self::assertCount(2,Messaging::messages($id)['items']);
    }

    public function testEveryRequiredFieldRejectsBlankWithoutSavingOtherPreferences(): void
    {
        $before=get_user_meta($this->users[0],'_ascla_profile',true);
        $email=ASCLA\Core\Services\Notifications::emailPreferences($this->users[0]);
        foreach (['first_name','last_name','position','company','city','bio','industries','interests','goals'] as $key) {
            $blank=in_array($key,['industries','interests','goals'],true)?[]:" \u{200B}";
            $reply=$this->api('POST','profiles/me',[$key=>$blank,'email_notifications'=>['all'=>false]]);
            self::assertSame(400,$reply->get_status(),$key);
            self::assertSame($before,get_user_meta($this->users[0],'_ascla_profile',true));
            self::assertSame($email,ASCLA\Core\Services\Notifications::emailPreferences($this->users[0]));
        }
    }

    public function testIncompleteLegacyProfileMustBeCompletedBeforeAnySave(): void
    {
        $id=$this->users[0];$profile=Profiles::raw($id);unset($profile['bio']);
        update_user_meta($id,'_ascla_profile',$profile);
        self::assertSame(400,$this->api('POST','profiles/me',['networking'=>false])->get_status());
        self::assertSame($profile,get_user_meta($id,'_ascla_profile',true));
        self::assertSame(200,$this->api('POST','profiles/me',['bio'=>'Biografía completa'])->get_status());
    }
}
