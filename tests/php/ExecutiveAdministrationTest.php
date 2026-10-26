<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Access,Administration,Bootstrap,Content,Settings};
use ASCLA\Core\Frontend\{App,Login};

final class ExecutiveAdministrationTest extends TestCase
{
    private array $users=[],$posts=[];
    private int $admin,$executive,$member;
    protected function setUp(): void
    {
        foreach(['administrator','ascla_executive','ascla_member'] as $role) {
            $login='execqa_'.bin2hex(random_bytes(5));
            $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);
            self::assertIsInt($id);$this->users[]=$id;
        }
        [$this->admin,$this->executive,$this->member]=$this->users;wp_set_current_user($this->executive);
    }
    protected function tearDown(): void
    {
        foreach($this->posts as $id) { wp_delete_post($id,true); }
        foreach($this->users as $id) { if(get_userdata($id)) { wp_delete_user($id); } }
        wp_set_current_user(0);
    }
    private function api(string $method,string $route,array $data=[],bool $native=false): WP_REST_Response
    {
        $request=new WP_REST_Request($method,($native?'/wp/v2/':'/ascla/v1/').$route);
        if($method==='GET') { $request->set_query_params($data); }
        else { $request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($data)); }
        return rest_do_request($request);
    }
    public function testExecutiveHasAdministrativeBootstrapHomeAndViewsWithoutConfiguration(): void
    {
        $executive=Bootstrap::get();wp_set_current_user($this->admin);$admin=Bootstrap::get();wp_set_current_user($this->executive);
        foreach(['admin','admin_area','moderator','executive','can_create'] as $key) { self::assertSame($admin[$key],$executive[$key]); }
        self::assertTrue($executive['admin']);self::assertFalse($executive['configure']);self::assertTrue($admin['configure']);
        self::assertFalse($executive['me']['community_participant']);self::assertFalse(Access::networkingMember());
        self::assertSame(App::adminUrl(),App::homeUrl());
        self::assertSame(App::adminUrl(),Login::redirect(admin_url(),'',get_userdata($this->executive)));
        self::assertSame(App::adminUrl(),Login::redirect(admin_url(),admin_url(),get_userdata($this->executive)));
        foreach(['admin?section=resumen','admin?section=logs','admin/users','admin/allies','admin/interest-imports','admin/contacts','admin/deliveries','media'] as $route) {
            [$path,$query]=array_pad(explode('?',$route,2),2,'');parse_str($query,$params);
            self::assertSame(200,$this->api('GET',$path,$params)->get_status(),$route);
        }
    }
    public function testConfigurationAndNativeWordPressCapabilitiesCannotBeEscalated(): void
    {
        $before=get_option('ascla_settings');
        foreach([['GET','settings'],['POST','settings'],['POST','demo'],['POST','ai/test'],['POST','mail/test']] as [$method,$route]) {
            self::assertSame(403,$this->api($method,$route,['demo'=>false])->get_status(),$route);
        }
        self::assertSame($before,get_option('ascla_settings'));
        foreach(['ascla_configure','manage_options','create_users','edit_users','delete_users','promote_users','install_plugins'] as $cap) { self::assertFalse(current_user_can($cap),$cap); }
        // This assertion exercises authorization. Random passwords can fail the
        // character/sequence policy before the permission callback is reached.
        $password='R7!mZ2@pV8#kT6%wQ4&nH9*eJ5+sL3';
        self::assertSame('',ASCLA\Core\Services\CredentialPolicy::error($password,(object)['user_login'=>'exec_native_denied','user_email'=>'exec_native_denied@example.invalid']));
        self::assertSame(403,$this->api('POST','users',['username'=>'exec_native_denied','email'=>'exec_native_denied@example.invalid','password'=>$password,'roles'=>['administrator']],true)->get_status());
        self::assertSame(403,$this->api('POST','users/'.$this->member,['roles'=>['administrator']],true)->get_status());
        get_userdata($this->executive)->add_cap('ascla_configure');self::assertFalse(current_user_can('ascla_configure'));
        self::assertSame(400,$this->api('POST','admin/users/'.$this->member,['email'=>get_userdata($this->member)->user_email,'role'=>'administrator','first_name'=>'No elevar'])->get_status());
        self::assertSame(['ascla_member'],get_userdata($this->member)->roles);
        self::assertSame(403,$this->api('POST','admin/users/'.$this->admin,[])->get_status());
        self::assertSame(400,$this->api('DELETE','admin/users/'.$this->executive)->get_status());
    }
    public function testExecutiveCanCreateEditSuspendAndDeleteCommunityAccounts(): void
    {
        $login='managed_'.bin2hex(random_bytes(5));
        $created=$this->api('POST','admin/users',['login'=>$login,'email'=>$login.'@example.invalid','first_name'=>'Persona','last_name'=>'Prueba','role'=>'ascla_member','member_type'=>'independent','send_invite'=>false]);
        self::assertSame(200,$created->get_status());$id=$created->get_data()['id'];$this->users[]=$id;
        $account=Administration::user($id);self::assertTrue(Administration::permissions($id)['can_edit']);self::assertTrue(Administration::permissions($id)['can_delete']);
        $input=array_intersect_key($account,array_flip(['email','role','first_name','last_name','position','company','member_type','membership_status','membership_until']));$input['first_name']='Actualizada';
        self::assertSame(200,$this->api('POST','admin/users/'.$id,$input)->get_status());self::assertSame('Actualizada',get_userdata($id)->first_name);
        self::assertSame(200,$this->api('POST','admin/member/'.$id,['suspended'=>true])->get_status());self::assertTrue((bool)get_user_meta($id,'_ascla_suspended',true));
        self::assertSame(200,$this->api('POST','admin/member/'.$id,['suspended'=>false])->get_status());
        self::assertSame(200,$this->api('DELETE','admin/users/'.$id)->get_status());self::assertFalse(get_userdata($id));
    }
    public function testExecutiveCanManageAlliesWithTheSameLifecycle(): void
    {
        $ally=Content::save('ally',['title'=>'Alianza ejecutiva','body'=>'Descripción institucional','status'=>'publish']);$id=$ally['id'];$this->posts[]=$id;
        foreach(['inactive','active'] as $state) { self::assertSame(200,$this->api('POST','admin/allies/'.$id.'/status',['state'=>$state])->get_status()); }
        self::assertSame(200,$this->api('GET','admin/allies/'.$id.'/history')->get_status());
        self::assertSame(200,$this->api('DELETE','items/'.$id)->get_status());self::assertSame('trash',get_post_status($id));
    }
}
