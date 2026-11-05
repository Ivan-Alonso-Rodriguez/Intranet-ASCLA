<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Frontend\{App,Login};
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Services\{Access,Connections,ConversationRequests,Matching,Profiles};
use ASCLA\Core\Repositories\Store;

final class AdministratorRoleTest extends TestCase
{
    private array $users=[];
    private string $prefix;
    protected function setUp(): void
    {
        $this->prefix='Rol '.bin2hex(random_bytes(5));
        foreach (['administrator','ascla_member','ascla_moderator','ascla_executive'] as $role) {
            $id=wp_insert_user(['user_login'=>'admin_role_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>$role,'display_name'=>'Prueba '.$role]);
            self::assertIsInt($id);$this->users[$role]=$id;
            wp_set_current_user($id);Profiles::save(ascla_test_profile(['first_name'=>$this->prefix,'directory'=>true,'networking'=>true,'microevents'=>true]));
        }
        wp_set_current_user($this->users['ascla_member']);
    }
    protected function tearDown(): void
    {
        foreach ($this->users as $id) {
            foreach (['relations'=>'user_id','notifications'=>'user_id','audit'=>'actor_id'] as $table=>$column) { Store::delete($table,[$column=>$id]); }
            Store::delete('relations',['target_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function api(string $method,string $path,array $params=[]): WP_REST_Response
    {
        $request=new WP_REST_Request($method,'/ascla/v1/'.$path);
        foreach ($params as $key=>$value) { $request->set_param($key,$value); }
        return rest_do_request($request);
    }
    public function testAdministratorIsExcludedEvenWithLegacyOptInPreferences(): void
    {
        $admin=$this->users['administrator'];
        $raw=(array)get_user_meta($admin,'_ascla_profile',true);
        update_user_meta($admin,'_ascla_profile',array_replace($raw,['directory'=>true,'networking'=>true,'microevents'=>true]));
        $profile=Profiles::raw($admin);
        foreach (['community_participant','directory','networking','microevents'] as $key) { self::assertFalse($profile[$key]); }
        self::assertFalse(Access::networkingMember($admin));
        self::assertFalse(Matching::eligibility($admin)['eligible']);
        $directory=$this->api('GET','profiles',['q'=>$this->prefix])->get_data();
        self::assertNotContains($admin,array_column($directory['items'],'id'));
        self::assertContains($this->users['ascla_member'],array_column($directory['items'],'id'));
        self::assertSame(404,$this->api('GET','profiles/'.$admin)->get_status());
        self::assertSame(400,$this->api('GET','matching/'.$admin,['explain'=>0])->get_status());
    }
    public function testNeitherSideCanCreateConnectionOrConversationRequestsWithAdministrator(): void
    {
        $admin=$this->users['administrator'];$member=$this->users['ascla_member'];
        foreach ([$member=>$admin,$admin=>$member] as $sender=>$target) {
            wp_set_current_user($sender);$expected=$sender===$admin?403:400;
            self::assertSame($expected,$this->api('POST','relations',['target'=>$target,'kind'=>'connect','active'=>true])->get_status());
            self::assertSame($expected,$this->api('POST','conversation-requests',['target'=>$target,'body'=>'Presentación de prueba'])->get_status());
        }
    }
    public function testLegacyRelationsDoNotGrantNetworkingPermissionsToAdministrator(): void
    {
        $admin=$this->users['administrator'];$member=$this->users['ascla_member'];
        foreach (['connected','conversation_allowed'] as $kind) { Store::insert('relations',['user_id'=>$member,'target_id'=>$admin,'kind'=>$kind,'created_at'=>current_time('mysql',true)]); }
        foreach ([[$member,$admin],[$admin,$member]] as [$left,$right]) {
            wp_set_current_user($left);
            foreach (['can_request','can_message','can_read_messages'] as $key) { self::assertFalse(Connections::between($left,$right)[$key]); }
            self::assertFalse(ConversationRequests::between($left,$right)['can_message']);
            self::assertFalse(ConversationRequests::isAuthorized($left,$right));
            self::assertEmpty(Connections::listing()['connected']);
            self::assertEmpty(ConversationRequests::listing()['allowed']);
            self::assertSame(403,$this->api('POST','conversations',['target'=>$right])->get_status());
        }
    }
    public function testMembersAndEditorialRolesKeepTheirNetworking(): void
    {
        foreach (['ascla_member','ascla_moderator'] as $role) {
            $id=$this->users[$role];self::assertTrue(Access::networkingMember($id));self::assertTrue(Profiles::raw($id)['directory']);
            self::assertSame(Catalog::url('intranet'),App::homeUrl($id));
        }
        $member=$this->users['ascla_member'];$moderator=$this->users['ascla_moderator'];
        $request=Connections::request($moderator);wp_set_current_user($moderator);Connections::respond($request['request_id'],'accept');
        self::assertTrue(Connections::areConnected($member,$moderator));
    }
    public function testAdministratorStartsInAsclaAdministrationAndCanExplicitlyOpenWordPress(): void
    {
        $admin=get_userdata($this->users['administrator']);$home=App::adminUrl();
        self::assertSame($home,App::homeUrl((int)$admin->ID));
        self::assertSame($home,Login::redirect(admin_url(),'',$admin));
        self::assertSame($home,Login::redirect(Catalog::url('intranet'),Catalog::url('intranet'),$admin));
        self::assertSame(admin_url(),Login::redirect(admin_url(),admin_url(),$admin));
        $executive=get_userdata($this->users['ascla_executive']);
        self::assertSame($home,App::homeUrl((int)$executive->ID));self::assertSame($home,Login::redirect(admin_url(),admin_url(),$executive));
        $member=get_userdata($this->users['ascla_member']);
        self::assertSame(Catalog::url('intranet'),Login::redirect(Catalog::url('intranet'),'',$member));
    }
}
