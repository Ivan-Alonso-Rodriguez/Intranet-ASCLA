<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Administration,AccountPermissions,RoleApprovals,Profiles};
use ASCLA\Core\Repositories\Store;

final class AccountPermissionsTest extends TestCase
{
    private array $users=[];
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member','ascla_moderator'] as $role) {
            $login='permissions_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[1]);Profiles::save(ascla_test_profile());wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]);
        foreach ($this->users as $id) {
            foreach (['audit','notifications','jobs'] as $table) { Store::delete($table,[$table==='audit'?'actor_id':'user_id'=>$id]); }
            wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function api(string $method,string $route,array $body=[],bool $native=false): WP_REST_Response
    {
        $r=new WP_REST_Request($method,($native?'/wp/v2/':'/ascla/v1/').$route);
        $r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));return rest_do_request($r);
    }
    private function edit(array $extra=[]): WP_REST_Response
    {
        $u=Administration::user($this->users[1]);
        return $this->api('POST','admin/users/'.$u['id'],$extra+array_intersect_key($u,array_flip(['email','role','first_name','last_name','position','company','birth_date','phone','phone_visibility','member_type','membership_status','membership_until'])));
    }
    private function approval(array $roles): array
    {
        return ['assigned_roles'=>$roles,'board_reference'=>'Acta JD 2026-014','board_date'=>wp_date('Y-m-d'),'board_confirmed'=>true];
    }
    public function testIndividualRestrictionsAreAuditedAndDisabledAccessCanBeRestored(): void
    {
        self::assertSame(200,$this->edit(['permission_overrides'=>['ascla_write'=>false]])->get_status());
        wp_set_current_user($this->users[1]);self::assertTrue(current_user_can('ascla_access'));self::assertFalse(current_user_can('ascla_write'));
        self::assertSame(403,$this->api('POST','content/hub',['title'=>'No autorizado','body'=>'No autorizado','status'=>'publish'])->get_status());
        wp_set_current_user($this->users[0]);
        $audit=Store::rows('audit','object_id=%d AND action=%s',[$this->users[1],'account_permissions_changed']);
        $detail=json_decode($audit[0]['detail'],true);self::assertFalse($detail['capabilities']['after']['ascla_write']);self::assertSame($this->users[0],(int)$audit[0]['actor_id']);
        self::assertSame(200,$this->edit(['permission_overrides'=>['ascla_access'=>false]])->get_status());
        wp_set_current_user($this->users[1]);self::assertSame(403,$this->api('GET','bootstrap')->get_status());
        wp_set_current_user($this->users[0]);self::assertSame(200,$this->api('GET','admin/users/'.$this->users[1])->get_status());
        self::assertSame(403,$this->api('POST','users/'.$this->users[1],['first_name'=>'Sin solicitud'],true)->get_status());
        self::assertSame(200,$this->edit(['permission_overrides'=>[]])->get_status());
        wp_set_current_user($this->users[1]);self::assertTrue(current_user_can('ascla_access'));self::assertTrue(current_user_can('ascla_write'));
    }
    public function testPrivilegesCannotExceedTheRoleOrBeEditedByAModerator(): void
    {
        self::assertSame(403,$this->edit(['permission_overrides'=>['ascla_manage'=>true]])->get_status());
        wp_set_current_user($this->users[2]);self::assertSame(403,$this->api('POST','admin/users/'.$this->users[1],['permission_overrides'=>[]])->get_status());
        $member=new WP_User($this->users[1]);$member->add_cap('ascla_manage');
        self::assertFalse($member->has_cap('ascla_manage'));self::assertFalse(user_can($this->users[1],'ascla_manage'));
        global $wpdb;self::assertArrayNotHasKey('ascla_manage',(array)get_user_meta($this->users[1],$wpdb->prefix.'capabilities',true));
    }
    public function testMultipleRolesRequirePriorBoardAuthorizationAndOnlyCoverItsRecordedScope(): void
    {
        $roles=['ascla_member','ascla_moderator'];
        self::assertSame(403,$this->edit(['assigned_roles'=>$roles])->get_status());self::assertSame(['ascla_member'],AccountPermissions::roles($this->users[1]));
        self::assertSame(200,$this->edit($this->approval($roles))->get_status());
        $record=(array)get_user_meta($this->users[1],RoleApprovals::META,true);
        self::assertSame($roles,$record['roles']);self::assertSame('Acta JD 2026-014',$record['reference']);self::assertSame($this->users[0],$record['recorded_by']);self::assertNotEmpty($record['recorded_at']);
        self::assertTrue(user_can($this->users[1],'ascla_moderate'));self::assertFalse(user_can($this->users[1],'ascla_publish'));
        $audit=Store::rows('audit','object_id=%d AND action=%s',[$this->users[1],'board_role_authorization']);
        self::assertSame($record,json_decode($audit[0]['detail'],true)['role_approval']['after']);
        self::assertSame(200,$this->edit()->get_status());self::assertSame($roles,AccountPermissions::roles($this->users[1]));
        self::assertSame(403,$this->edit(['assigned_roles'=>array_merge($roles,['ascla_executive'])])->get_status());
        self::assertSame(200,$this->edit($this->approval(array_merge($roles,['ascla_executive'])))->get_status());
        self::assertTrue(user_can($this->users[1],'ascla_publish'));
        self::assertSame(200,$this->edit(['assigned_roles'=>['ascla_member']])->get_status());self::assertFalse(user_can($this->users[1],'ascla_moderate'));
    }
    public function testNativeRoleAccumulationCannotBypassAuthorization(): void
    {
        $roles=['ascla_member','ascla_moderator'];
        self::assertSame(403,$this->api('POST','users/'.$this->users[1],['roles'=>$roles],true)->get_status());
        $member=new WP_User($this->users[1]);$member->add_role('ascla_moderator');
        self::assertSame(['ascla_member'],AccountPermissions::roles($this->users[1]));self::assertFalse($member->has_cap('ascla_moderate'));
        self::assertSame(200,$this->edit($this->approval($roles))->get_status());
        self::assertSame(200,$this->api('POST','users/'.$this->users[1],['roles'=>$roles],true)->get_status());
        self::assertSame($roles,AccountPermissions::roles($this->users[1]));
    }
    public function testUnapprovedLegacyRolesDoNotSupplyWordPressAdministrativeCapabilities(): void
    {
        global $wpdb;
        $wpdb->update($wpdb->usermeta,['meta_value'=>maybe_serialize(['ascla_member'=>true,'administrator'=>true])],['user_id'=>$this->users[1],'meta_key'=>$wpdb->prefix.'capabilities']);clean_user_cache($this->users[1]);
        wp_set_current_user(0);wp_set_current_user($this->users[1]);
        foreach (['ascla_manage','ascla_moderate','manage_options','edit_users','promote_users'] as $cap) { self::assertFalse(current_user_can($cap),$cap); }
        self::assertTrue(current_user_can('ascla_access'));
        self::assertSame(403,$this->api('POST','users/'.$this->users[1],['roles'=>['administrator']],true)->get_status());
    }
    public function testInvalidAuthorizationAndPermissionPayloadsAreRejected(): void
    {
        foreach ([['permission_overrides'=>['unknown'=>false]],['permission_overrides'=>['ascla_write'=>'false']],['assigned_roles'=>'ascla_member'],['assigned_roles'=>['administrator']],['assigned_roles'=>['ascla_executive']]] as $input) { self::assertSame(400,$this->edit($input)->get_status()); }
        $approval=$this->approval(['ascla_member','ascla_moderator']);
        foreach (['2099-01-01','2026-02-30','invalid'] as $date) { self::assertSame(400,$this->edit(['board_date'=>$date]+$approval)->get_status()); }
        self::assertSame(403,$this->edit(['board_reference'=>"\u{200B} "]+$approval)->get_status());
        self::assertSame(403,$this->edit(['board_confirmed'=>false]+$approval)->get_status());
        self::assertSame(['ascla_member'],AccountPermissions::roles($this->users[1]));
    }
    public function testLateAuditFailureRollsBackRolesApprovalPermissionsAndIdentityTogether(): void
    {
        global $wpdb;$id=$this->users[1];$key=$wpdb->prefix.'capabilities';$caps=get_user_meta($id,$key,true);$email=get_userdata($id)->user_email;
        $oldApproval=get_user_meta($id,RoleApprovals::META,true);$profile=get_user_meta($id,'_ascla_profile',true);$revision=(int)get_option('ascla_profile_revision',0);$previous=$wpdb->suppress_errors(true);
        $fail=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.Store::table('audit').'`') && str_contains($sql,"'member_updated'")?str_replace(Store::table('audit'),Store::table('audit').'_missing',$sql):$sql;
        add_filter('query',$fail);
        try { self::assertSame(500,$this->edit(['email'=>'rollback-permissions@example.invalid','permission_overrides'=>['ascla_write'=>false]]+$this->approval(['ascla_member','ascla_moderator']))->get_status()); }
        finally { remove_filter('query',$fail);$wpdb->suppress_errors($previous);$wpdb->last_error=''; }
        self::assertSame($caps,get_user_meta($id,$key,true));self::assertSame($oldApproval,get_user_meta($id,RoleApprovals::META,true));
        self::assertSame($email,get_userdata($id)->user_email);self::assertSame($profile,get_user_meta($id,'_ascla_profile',true));self::assertSame($revision,(int)get_option('ascla_profile_revision',0));
        self::assertSame(0,Store::count('audit','object_id=%d AND action=%s',[$id,'board_role_authorization']));
    }
    public function testTechnicalAccountAuthorizationIsRecordedWithoutAssigningRolesAndNativeAssignmentThenWorks(): void
    {
        $id=$this->users[2];(new WP_User($id))->set_role('administrator');
        $roles=['administrator','ascla_executive'];$input=$this->approval($roles);$input['roles']=$roles;
        self::assertSame(403,$this->api('POST','users/'.$id,['roles'=>$roles],true)->get_status());
        self::assertSame(200,$this->api('POST','admin/users/'.$id.'/role-approval',$input)->get_status());
        self::assertSame(['administrator'],AccountPermissions::roles($id));
        self::assertSame($roles,get_user_meta($id,RoleApprovals::META,true)['roles']);
        $native=$this->api('POST','users/'.$id,['roles'=>$roles],true);self::assertSame(200,$native->get_status(),$native->get_data()['message']??'');
        self::assertSame($roles,AccountPermissions::roles($id));self::assertTrue(current_user_can('ascla_manage'));
    }
    public function testApprovalRegistrationRejectsUnknownSingleAndUnauthorizedRequests(): void
    {
        $id=$this->users[1];
        foreach ([['ascla_member'],['ascla_member','ascla_member'],['administrator','unknown'],'administrator'] as $roles) {
            self::assertSame(400,$this->api('POST','admin/users/'.$id.'/role-approval',['roles'=>$roles,'board_reference'=>'Acta','board_date'=>wp_date('Y-m-d'),'board_confirmed'=>true])->get_status());
        }
        wp_set_current_user($this->users[2]);self::assertSame(403,$this->api('POST','admin/users/'.$id.'/role-approval',['roles'=>['ascla_member','ascla_moderator']])->get_status());
    }
}
