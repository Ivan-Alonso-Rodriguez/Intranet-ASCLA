<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Administration,Content,MicroEvents,Profiles,Settings,Turnstile,TurnstileState};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Jobs\Queue;

/** Version 1.10.3: regressions from acceptance cases 004, 008, 013 and 019. */
final class Release103RegressionTest extends TestCase
{
    private array $users=[];
    private array $posts=[];
    private array $saved=[];
    private string $password;
    private int $admin,$member,$executive,$moderator,$viewer;
    private $http;

    protected function setUp(): void
    {
        $this->saved=['settings'=>Settings::get(),'server'=>$_SERVER,'request'=>$_REQUEST,'cookies'=>$_COOKIE,'post'=>$_POST,'get'=>$_GET,'pagenow'=>$GLOBALS['pagenow']??''];
        $this->password=wp_generate_password(28);
        foreach (['administrator','ascla_member','ascla_executive','ascla_moderator','ascla_member'] as $role) {
            $login='release103_'.bin2hex(random_bytes(6));
            $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$this->password,'role'=>$role,'first_name'=>'Ana','last_name'=>'Prueba']);
            $this->users[]=$id;
            update_user_meta($id,'_ascla_profile',['first_name'=>'Ana','last_name'=>'Prueba','position'=>'Analista','company'=>'ASCLA','directory'=>true,'networking'=>true,'microevents'=>true,'hidden'=>[]]);
        }
        [$this->admin,$this->member,$this->executive,$this->moderator,$this->viewer]=$this->users;
        wp_set_current_user($this->admin);
        Settings::save(['turnstile_enabled'=>false,'micro_approval'=>true,'ai_provider'=>'mock']);
        $GLOBALS['pagenow']='index.php';
        $_SERVER['REMOTE_ADDR']='2001:db8:'.bin2hex(random_bytes(2)).'::1';
        $_REQUEST=[];$_POST=[];$_GET=[];$_COOKIE=[];
        add_filter('send_auth_cookies','__return_false');
        add_filter('pre_wp_mail','__return_true');
        $this->http=static fn()=>new WP_Error('local_test','External requests disabled');
        add_filter('pre_http_request',$this->http);
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach ($this->posts as $id) {
            Store::delete('registrations',['event_id'=>$id]);
            wp_delete_post($id,true);
        }
        foreach ($this->users as $id) {
            TurnstileState::clearState('login',get_userdata($id)->user_login);
            foreach (['jobs','notifications','relations'] as $table) { Store::delete($table,['user_id'=>$id]); }
            wp_delete_user($id);
        }
        remove_filter('send_auth_cookies','__return_false');
        remove_filter('pre_wp_mail','__return_true');
        remove_filter('pre_http_request',$this->http);
        update_option('ascla_settings',$this->saved['settings'],false);
        $_SERVER=$this->saved['server'];$_REQUEST=$this->saved['request'];$_COOKIE=$this->saved['cookies'];$_POST=$this->saved['post'];$_GET=$this->saved['get'];$GLOBALS['pagenow']=$this->saved['pagenow'];
        wp_set_current_user(0);
    }

    private function api(string $method,string $route,array $body=[]): WP_REST_Response
    {
        $request=new WP_REST_Request($method,'/ascla/v1/'.$route);
        $request->set_header('Content-Type','application/json');
        if ($method==='GET') { $request->set_query_params($body); }
        else { $request->set_body(wp_json_encode($body)); }
        return rest_do_request($request);
    }

    public static function loginSurfaces(): array { return [['native'],['frontend']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('loginSurfaces')]
    public function testCorrectPasswordCannotBypassFiveFailuresForTenMinutes(string $surface): void
    {
        wp_set_current_user(0);
        if ($surface==='native') { $GLOBALS['pagenow']='wp-login.php'; }
        else { $_REQUEST['ascla_frontend_login']='1'; }
        $user=get_userdata($this->member);
        for ($i=0;$i<5;$i++) {
            $result=wp_signon(['user_login'=>$i%2?$user->user_email:$user->user_login,'user_password'=>'wrong-password'],false);
            self::assertInstanceOf(WP_Error::class,$result);
        }
        $state=TurnstileState::state('login',$user->user_login);
        self::assertSame(5,$state['count']);
        self::assertEqualsWithDelta(time()+600,$state['lock_until'],2);
        $_SERVER['REMOTE_ADDR']='2001:db8:'.bin2hex(random_bytes(2)).'::2';
        $blocked=wp_signon(['user_login'=>strtoupper($user->user_email),'user_password'=>$this->password],false);
        self::assertInstanceOf(WP_Error::class,$blocked);
        self::assertSame('ascla_login_locked',$blocked->get_error_code());
        self::assertSame($state['lock_until'],TurnstileState::state('login',$user->user_login)['lock_until'],'Attempts during the lock do not extend it');
        $state['lock_until']=time()-1;
        TurnstileState::saveState('login',TurnstileState::stateKey('login',$user->user_login),$state);
        $accepted=wp_signon(['user_login'=>$user->user_login,'user_password'=>$this->password],false);
        self::assertInstanceOf(WP_User::class,$accepted);
        self::assertSame($this->member,$accepted->ID);
        self::assertSame(0,TurnstileState::state('login',$user->user_email)['count']);
    }

    public function testSuccessfulLoginBeforeTheThresholdClearsFailures(): void
    {
        wp_set_current_user(0);$GLOBALS['pagenow']='wp-login.php';
        $login=get_userdata($this->member)->user_login;
        for ($i=0;$i<4;$i++) { wp_authenticate($login,'wrong'); }
        self::assertSame(4,TurnstileState::state('login',$login)['count']);
        self::assertInstanceOf(WP_User::class,wp_signon(['user_login'=>$login,'user_password'=>$this->password],false));
        self::assertSame(0,TurnstileState::state('login',$login)['count']);
    }

    public static function requiredFields(): array
    {
        $cases=[];
        foreach (['first_name','last_name','position','company'] as $key) {
            foreach ([''," \t\n","\u{00A0}\u{200B}"] as $value) { $cases[]=[$key,$value]; }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredFields')]
    public function testRequiredFieldsCannotBeClearedAndNoPartialSaveOccurs(string $field,string $blank): void
    {
        wp_set_current_user($this->member);
        $before=get_user_meta($this->member,'_ascla_profile',true);
        $revision=get_option('ascla_profile_revision');
        $response=$this->api('POST','profiles/me',[$field=>$blank,'bio'=>'Should not be saved','email_notifications'=>['all'=>false]]);
        self::assertSame(400,$response->get_status());
        self::assertSame($before,get_user_meta($this->member,'_ascla_profile',true));
        self::assertSame($revision,get_option('ascla_profile_revision'));
    }

    public function testValidProfileAndPartialPreferencesPreserveRequiredFields(): void
    {
        wp_set_current_user($this->member);
        self::assertSame(200,$this->api('POST','profiles/me',['first_name'=>'María','last_name'=>'Pérez','position'=>'Directora','company'=>'Organización'])->get_status());
        self::assertSame(200,$this->api('POST','profiles/me',['networking'=>false])->get_status());
        self::assertSame('Directora',Profiles::raw($this->member)['position']);
    }

    private function request(int $author,string $state='open'): int
    {
        wp_set_current_user($author);
        $request=Content::save('contact',['title'=>'Actualizar mis datos profesionales','body'=>'Solicito cambiar mi cargo a Gerente y mi empresa a ASCLA Perú.','status'=>'publish']);
        $id=(int)$request['id'];$this->posts[]=$id;
        if ($state!=='open') { wp_set_current_user($this->admin);Administration::contactStatus($id,$state); }
        wp_set_current_user($this->admin);
        return $id;
    }

    private function editPayload(array $extra=[]): array
    {
        return $extra+['email'=>get_userdata($this->member)->user_email,'role'=>'ascla_member','first_name'=>'Ana','last_name'=>'Prueba','position'=>'Gerente','company'=>'ASCLA Perú'];
    }

    public function testProfessionalEditWithoutRequestDoesNotChangeAnyAccountData(): void
    {
        $before=get_user_meta($this->member,'_ascla_profile',true);
        $email=get_userdata($this->member)->user_email;
        $response=$this->api('POST','admin/users/'.$this->member,$this->editPayload(['email'=>'changed-'.bin2hex(random_bytes(4)).'@example.invalid','role'=>'ascla_executive']));
        self::assertSame(403,$response->get_status());
        self::assertSame($before,get_user_meta($this->member,'_ascla_profile',true));
        self::assertSame($email,get_userdata($this->member)->user_email);
        self::assertSame(['ascla_member'],get_userdata($this->member)->roles);
    }

    public function testUnchangedProfessionalFieldsNeedNoRequest(): void
    {
        $payload=$this->editPayload(['position'=>'Analista','company'=>'ASCLA','role'=>'ascla_executive']);
        self::assertSame(200,$this->api('POST','admin/users/'.$this->member,$payload)->get_status());
        self::assertSame(['ascla_executive'],get_userdata($this->member)->roles);
    }

    public function testSelectedMemberRequestAuthorizesOneChangeAndRecordsItsActor(): void
    {
        $id=$this->request($this->member,'progress');
        $detail=$this->api('GET','admin/users/'.$this->member)->get_data();
        self::assertContains($id,array_column($detail['professional_requests'],'id'));
        $payload=$this->editPayload(['professional_request_id'=>$id]);
        self::assertSame(200,$this->api('POST','admin/users/'.$this->member,$payload)->get_status());
        self::assertSame('Gerente',Profiles::raw($this->member)['position']);
        $record=get_post_meta($id,'_ascla_professional_change',true);
        self::assertSame($this->admin,$record['actor']);
        self::assertSame($this->member,$record['member']);
        self::assertSame(['position','company'],$record['fields']);
        self::assertEmpty($this->api('GET','admin/users/'.$this->member)->get_data()['professional_requests']);
        $payload['position']='Otro cargo';
        self::assertSame(409,$this->api('POST','admin/users/'.$this->member,$payload)->get_status());
        self::assertSame('Gerente',Profiles::raw($this->member)['position']);
    }

    public function testClosedForeignTrashedAndNonContactRequestsDoNotAuthorizeChanges(): void
    {
        $foreign=$this->request($this->viewer);
        $closed=$this->request($this->member,'closed');
        $trashed=$this->request($this->member);wp_trash_post($trashed);
        $forum=wp_insert_post(['post_type'=>'ascla_forum','post_status'=>'private','post_author'=>$this->member,'post_title'=>'Unrelated']);
        $this->posts[]=$forum;
        foreach ([$foreign,$closed,$trashed,$forum,PHP_INT_MAX] as $id) {
            self::assertSame(409,$this->api('POST','admin/users/'.$this->member,$this->editPayload(['professional_request_id'=>$id]))->get_status());
        }
        self::assertSame('Analista',Profiles::raw($this->member)['position']);
        self::assertEmpty($this->api('GET','admin/users/'.$this->member)->get_data()['professional_requests']);
    }

    public function testInvalidProfessionalValueDoesNotConsumeTheRequest(): void
    {
        $id=$this->request($this->member);
        self::assertSame(400,$this->api('POST','admin/users/'.$this->member,$this->editPayload(['professional_request_id'=>$id,'position'=>' ']))->get_status());
        self::assertSame('',get_post_meta($id,'_ascla_professional_change',true));
    }

    public function testDirectorySearchMatchesTheVisibleFallbackWithoutLeakingHiddenPositions(): void
    {
        $hidden=Profiles::raw($this->member);$hidden['hidden']=['position'];$hidden['position']='SecretPosition103';
        update_user_meta($this->member,'_ascla_profile',$hidden);
        $empty=Profiles::raw($this->executive);$empty['position']='';update_user_meta($this->executive,'_ascla_profile',$empty);
        wp_set_current_user($this->viewer);
        $results=Profiles::directory(['q'=>'MIEMBRO ASCLA']);
        $ids=array_column($results['items'],'id');
        self::assertContains($this->member,$ids);
        self::assertContains($this->executive,$ids);
        self::assertNotContains($this->moderator,$ids);
        self::assertSame(0,Profiles::directory(['q'=>'SecretPosition103'])['total']);
        self::assertArrayNotHasKey('position',Profiles::visible($this->member));
        self::assertSame('Miembro ASCLA',Profiles::visible($this->member)['position_label']);
        update_user_meta($this->viewer,'locale','en_US');
        self::assertSame('ASCLA member',Profiles::visible($this->member)['position_label']);
        self::assertContains($this->member,array_column(Profiles::directory(['q'=>'ASCLA member'])['items'],'id'));
        wp_set_current_user($this->admin);
        self::assertSame('SecretPosition103',Profiles::visible($this->member)['position_label']);
        self::assertNotContains($this->member,array_column(Profiles::directory(['q'=>'Miembro ASCLA'])['items'],'id'));
    }

    public function testExecutiveCreatesMicroeventsThroughRouteAndWorkerWithoutPublishing(): void
    {
        $monthKey='ascla_micro_'.wp_date('Y-m');
        $savedMonth=get_option($monthKey);$history=get_option('ascla_group_history',[]);
        $ids=$this->users;sort($ids);
        $demoKey='ascla_micro_demo-'.substr(hash('sha256',wp_json_encode($ids)),0,12).'-'.wp_date('Y-m');
        try {
            wp_set_current_user($this->executive);
            $proposals=MicroEvents::create($ids);
            $this->posts=array_merge($this->posts,$proposals['events']);
            self::assertNotEmpty($proposals['events']);
            update_option($monthKey,$proposals,false);
            $response=$this->api('POST','jobs',['kind'=>'microevents']);
            self::assertSame(200,$response->get_status());
            $jobId=(int)$response->get_data()['id'];
            $worker=new ReflectionMethod(Queue::class,'processRow');
            $worker->invoke(null,Store::one('jobs',$jobId),Store::table('jobs'),$this->executive);
            self::assertSame('completed',Queue::get($jobId)['status']);
            foreach ($proposals['events'] as $id) {
                self::assertSame($this->executive,(int)get_post($id)->post_author);
                self::assertSame('pending',get_post_status($id));
                self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));
                self::assertSame(403,$this->api('POST','items/'.$id.'/moderate',['decision'=>'approve','reviewed'=>true])->get_status());
                $meta=get_post_meta($id,'_ascla',true);
                $saved=Content::save('event',['title'=>'Propuesta revisada','body'=>'Agenda para revisar','status'=>'publish','meta'=>$meta],$id);
                self::assertSame('pending',$saved['status']);
            }
            $revoked=$this->api('POST','jobs',['kind'=>'microevents'])->get_data();
            get_userdata($this->executive)->set_role('ascla_member');
            wp_set_current_user(0); // A new worker request reloads current capabilities.
            $worker->invoke(null,Store::one('jobs',(int)$revoked['id']),Store::table('jobs'),$this->executive);
            self::assertSame('error',Store::one('jobs',(int)$revoked['id'])['status']);
            foreach ([$this->member,$this->moderator] as $uid) {
                wp_set_current_user($uid);
                self::assertSame(403,$this->api('POST','jobs',['kind'=>'microevents'])->get_status());
            }
        } finally {
            if ($savedMonth===false) { delete_option($monthKey); } else { update_option($monthKey,$savedMonth,false); }
            delete_option($demoKey);update_option('ascla_group_history',$history,false);
        }
    }
}
