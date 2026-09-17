<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Domain\Roles;
use ASCLA\Core\Database\Installer;

final class CapabilityHierarchyTest extends TestCase
{
    private array $users=[];
    protected function tearDown(): void
    {
        foreach($this->users as $id){wp_delete_user($id);}
        wp_set_current_user(0);
    }
    private function user(string $role): int
    {
        $login='hierarchy_'.bin2hex(random_bytes(5));
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        self::assertIsInt($id);$this->users[]=$id;return $id;
    }
    private function api(string $route): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('GET','/ascla/v1/'.$route));
    }
    public function testCapabilitiesAndRestViewsMatchAllFourLevels(): void
    {
        $levels=[
            'ascla_member'=>[false,false,false],
            'ascla_moderator'=>[true,false,false],
            'ascla_executive'=>[true,true,false],
            'administrator'=>[true,true,true],
        ];
        foreach($levels as $role=>[$moderate,$publish,$manage]){
            wp_set_current_user($this->user($role));
            self::assertTrue(current_user_can('ascla_access'));self::assertTrue(current_user_can('ascla_write'));
            foreach(['ascla_moderate'=>$moderate,'ascla_publish'=>$publish,'ascla_manage'=>$manage,'ascla_admin_area'=>$moderate] as $cap=>$expected){
                self::assertSame($expected,current_user_can($cap),$role.' '.$cap);
            }
            if(!$manage){self::assertFalse(current_user_can('manage_options'));self::assertFalse(current_user_can('create_users'));}
            $boot=$this->api('bootstrap');self::assertSame(200,$boot->get_status());$data=$boot->get_data();
            self::assertSame($moderate,$data['moderator']);self::assertSame($publish,$data['executive']);self::assertSame($manage,$data['admin']);self::assertSame($moderate,$data['admin_area']);
            foreach(['forum','topic','hub','contact'] as $type){self::assertTrue($data['can_create'][$type]);}
            foreach(['event','gallery','resource'] as $type){self::assertSame($publish,$data['can_create'][$type]);}
            foreach(['admin'=>$moderate,'admin/contacts'=>$moderate,'admin/users'=>$manage,'settings'=>$manage] as $route=>$allowed){
                self::assertSame($allowed?200:403,$this->api($route)->get_status(),$role.' '.$route);
            }
        }
    }
    public function testUpgradeUpdatesExistingRolesEvenWhenPluginVersionIsAlready110(): void
    {
        $id=$this->user('ascla_executive');$role=get_role('ascla_executive');
        $role->remove_cap('ascla_moderate');$role->add_cap('external_custom_permission');
        get_role('ascla_member')->add_cap('ascla_manage');
        update_user_meta($id,'hierarchy_preserved','existing profile');
        $before=get_userdata($id);$before->add_cap('individual_custom_permission');
        $hash=$before->user_pass;$roles=$before->roles;
        update_option('ascla_version',ASCLA_VERSION);delete_option(Roles::OPTION);
        wp_set_current_user($id);self::assertFalse(current_user_can('ascla_moderate'));
        try{
            Installer::upgrade();
            self::assertTrue(current_user_can('ascla_moderate'),'Current session is refreshed after migration');
            self::assertFalse(get_role('ascla_member')->has_cap('ascla_manage'));
            self::assertTrue(current_user_can('external_custom_permission'));self::assertTrue(current_user_can('individual_custom_permission'));
            $after=get_userdata($id);self::assertSame($hash,$after->user_pass);self::assertSame($roles,$after->roles);
            self::assertSame('existing profile',get_user_meta($id,'hierarchy_preserved',true));
            self::assertSame(Roles::VERSION,(int)get_option(Roles::OPTION));
            $caps=$after->allcaps;Installer::upgrade();self::assertSame($caps,wp_get_current_user()->allcaps);
        }finally{$role->remove_cap('external_custom_permission');Roles::sync();}
    }
    public function testRolePromotionAndDemotionApplyWithoutChangingIdentity(): void
    {
        $id=$this->user('ascla_member');$user=get_userdata($id);$hash=$user->user_pass;
        foreach(['ascla_moderator'=>[true,false,false],'ascla_executive'=>[true,true,false],'administrator'=>[true,true,true],'ascla_member'=>[false,false,false]] as $role=>$expected){
            $user->set_role($role);wp_set_current_user(0);wp_set_current_user($id);
            self::assertSame($expected,[current_user_can('ascla_moderate'),current_user_can('ascla_publish'),current_user_can('ascla_manage')]);
            self::assertSame($hash,get_userdata($id)->user_pass);
        }
    }
    public function testSuspensionBlocksEveryRoleIncludingDirectRestRequests(): void
    {
        foreach(array_keys(Roles::capabilities()) as $role){
            $id=$this->user($role);wp_set_current_user($id);update_user_meta($id,'_ascla_suspended',1);
            foreach(['bootstrap','admin','settings'] as $route){self::assertSame(403,$this->api($route)->get_status(),$role.' '.$route);}
            self::assertFalse(ASCLA\Core\Services\Content::canCreate('forum'));
        }
        wp_set_current_user(0);self::assertSame(401,$this->api('bootstrap')->get_status());
    }
}
