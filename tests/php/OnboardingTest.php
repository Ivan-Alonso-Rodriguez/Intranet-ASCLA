<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Onboarding;
use ASCLA\Core\Rest\ApiException;
use ASCLA\Core\Repositories\RepositoryException;

final class OnboardingTest extends TestCase
{
    private array $users=[];
    private mixed $rollout;

    protected function setUp(): void
    {
        $this->rollout=get_option('ascla_onboarding_rollout',null);
        delete_option('ascla_onboarding_rollout');
        Onboarding::initialize();
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $id) { wp_delete_user($id); }
        delete_option('ascla_onboarding_rollout');
        if ($this->rollout!==null) { add_option('ascla_onboarding_rollout',$this->rollout,'',false); }
        wp_set_current_user(0);
    }

    private function user(string $role='ascla_member'): int
    {
        $id=wp_insert_user(['user_login'=>'onboarding_'.bin2hex(random_bytes(6)),
            'user_pass'=>wp_generate_password(32),'role'=>$role]);
        self::assertIsInt($id);
        $this->users[]=$id;wp_set_current_user($id);
        return $id;
    }

    private function change(array $state,string $action='move',string $step='search',string $module='hub'): array
    {
        return Onboarding::update($module,['action'=>$action,'step'=>$step,'run'=>$state['run'],'revision'=>$state['revision']]);
    }

    private function denied(callable $operation,int $status): void
    {
        try { $operation();self::fail('The request should be denied.'); }
        catch (ApiException $exception) { self::assertSame($status,$exception->getCode()); }
    }

    public function testExistingAccountsNeverReceiveAutomaticToursEvenAfterRoleOrDateChanges(): void
    {
        $old=$this->user();
        delete_option('ascla_onboarding_rollout');
        Onboarding::initialize();
        wp_update_user(['ID'=>$old,'user_registered'=>'2099-01-01 00:00:00']);
        (new WP_User($old))->set_role('ascla_executive');
        Onboarding::registered($old);
        self::assertFalse(Onboarding::get()['eligible']);
        self::assertFalse(Onboarding::update('hub',['action'=>'start','automatic'=>true,'restart'=>true])['started']);
        self::assertFalse(metadata_exists('user',$old,'_ascla_onboarding_hub'));
        self::assertTrue(Onboarding::update('hub',['action'=>'start','automatic'=>false])['started']);
    }

    public function testOnlyNewRegisteredAccountsJoinTheCohortAndDatesDoNotMatter(): void
    {
        $user=$this->user();
        wp_update_user(['ID'=>$user,'user_registered'=>'2000-01-01 00:00:00']);
        self::assertTrue(Onboarding::get()['eligible']);
        delete_user_meta($user,'_ascla_onboarding_cohort');
        self::assertFalse(Onboarding::get()['eligible']);
        update_user_meta($user,'_ascla_onboarding_cohort','unrelated-cohort');
        self::assertFalse(Onboarding::get()['eligible']);
    }

    public function testFirstVisitIsClaimedOnceAndModulesKeepIndependentProgress(): void
    {
        $user=$this->user();
        $first=Onboarding::update('hub',['action'=>'start','automatic'=>true]);
        self::assertTrue($first['started']);
        self::assertSame('overview',$first['progress']['step']);
        self::assertFalse(Onboarding::update('hub',['action'=>'start','automatic'=>true])['started']);
        $move=$this->change($first['progress']);
        wp_cache_delete($user,'user_meta');
        self::assertSame('search',Onboarding::get()['progress']['hub']['step']);
        self::assertTrue(Onboarding::update('eventos',['action'=>'start','automatic'=>true])['started']);
        $this->change($move['progress'],'skip');
        self::assertFalse(Onboarding::update('hub',['action'=>'start','automatic'=>true,'restart'=>true])['started']);
        self::assertSame('started',Onboarding::get()['progress']['eventos']['status']);
    }

    public function testManualResumeAndRepeatWorkForCompletedAndInterruptedTours(): void
    {
        $this->user();
        $first=Onboarding::update('hub',['action'=>'start','automatic'=>true]);
        $this->change($first['progress']);
        $resume=Onboarding::update('hub',['action'=>'start']);
        self::assertSame('search',$resume['progress']['step']);
        self::assertNotSame($first['progress']['run'],$resume['progress']['run']);
        $this->change($resume['progress'],'complete');
        self::assertFalse(Onboarding::update('hub',['action'=>'start','automatic'=>true])['started']);
        $repeat=Onboarding::update('hub',['action'=>'start','restart'=>true]);
        self::assertSame('overview',$repeat['progress']['step']);
        self::assertSame('started',$repeat['progress']['status']);
    }

    public function testStaleDevicesCannotOverwriteMoreRecentProgress(): void
    {
        $this->user();
        $first=Onboarding::update('hub',['action'=>'start']);
        $second=Onboarding::update('hub',['action'=>'start']);
        $this->denied(fn()=>$this->change($first['progress']),409);
        $moved=$this->change($second['progress']);
        $this->denied(fn()=>$this->change($second['progress'],'skip'),409);
        self::assertSame($moved['progress'],Onboarding::get()['progress']['hub']);
    }

    public function testStateAndMetadataBelongOnlyToTheCurrentUser(): void
    {
        $a=$this->user();
        $started=Onboarding::update('hub',['action'=>'start']);
        $b=$this->user();
        self::assertSame([],Onboarding::get()['progress']['hub']);
        $this->denied(fn()=>$this->change($started['progress']),409);
        self::assertFalse(metadata_exists('user',$b,'_ascla_onboarding_hub'));
        wp_set_current_user($a);
        self::assertSame($started['progress'],Onboarding::get()['progress']['hub']);
    }

    public function testInvalidInputsCannotCreateArbitraryMetadataOrSkipPermissions(): void
    {
        $user=$this->user();
        $this->denied(fn()=>Onboarding::update('admin-configuracion',['action'=>'start']),404);
        $this->denied(fn()=>Onboarding::update('unknown',['action'=>'start']),404);
        $this->denied(fn()=>Onboarding::update('hub',['action'=>'delete']),400);
        $first=Onboarding::update('hub',['action'=>'start']);
        $this->denied(fn()=>$this->change($first['progress'],'move','<script>'),400);
        self::assertSame($first['progress'],get_user_meta($user,'_ascla_onboarding_hub',true));
    }

    public function testRoleCatalogAndStepsMatchActualPrivileges(): void
    {
        $user=$this->user();
        $member=Onboarding::get()['tours'];
        self::assertArrayNotHasKey('admin-resumen',$member);
        self::assertContains('create',array_column($member['hub']['steps'],'id'));
        self::assertNotContains('create',array_column($member['eventos']['steps'],'id'));
        (new WP_User($user))->set_role('ascla_executive');wp_set_current_user(0);wp_set_current_user($user);
        $executive=Onboarding::get()['tours'];
        self::assertArrayHasKey('admin-estadisticas',$executive);
        self::assertArrayNotHasKey('admin-configuracion',$executive);
        self::assertContains('create',array_column($executive['eventos']['steps'],'id'));
        (new WP_User($user))->set_role('ascla_moderator');wp_set_current_user(0);wp_set_current_user($user);
        $moderator=Onboarding::get()['tours'];
        self::assertArrayHasKey('admin-moderacion',$moderator);
        self::assertArrayNotHasKey('admin-usuarios',$moderator);
        (new WP_User($user))->set_role('administrator');wp_set_current_user(0);wp_set_current_user($user);
        $admin=Onboarding::get()['tours'];
        self::assertArrayHasKey('admin-configuracion',$admin);
        self::assertArrayNotHasKey('mensajeria',$admin);
        self::assertArrayNotHasKey('intranet',$admin);
    }

    public function testFailedDatabaseWritesDoNotClaimTheTour(): void
    {
        $user=$this->user();
        $fail=static fn($value,$id,$key)=>$key==='_ascla_onboarding_hub'?false:$value;
        add_filter('update_user_metadata',$fail,10,3);
        try {
            try { Onboarding::update('hub',['action'=>'start','automatic'=>true]);self::fail('A failed write must throw.'); }
            catch (RepositoryException) { self::assertFalse(metadata_exists('user',$user,'_ascla_onboarding_hub')); }
        } finally { remove_filter('update_user_metadata',$fail,10); }
        self::assertTrue(Onboarding::update('hub',['action'=>'start','automatic'=>true])['started']);
    }

    public function testNewModulesCanRegisterWithoutChangesToTheEngine(): void
    {
        $this->user();
        $extend=static function(array $tours): array { $tours['future']=$tours['hub'];return $tours; };
        add_filter('ascla_onboarding_tours',$extend);
        try {
            self::assertArrayHasKey('future',Onboarding::get()['tours']);
            self::assertTrue(Onboarding::update('future',['action'=>'start','automatic'=>true])['started']);
            self::assertFalse(Onboarding::update('future',['action'=>'start','automatic'=>true])['started']);
        } finally { remove_filter('ascla_onboarding_tours',$extend); }
    }

    public function testAnonymousRequestsCannotReadOrWriteOnboarding(): void
    {
        wp_set_current_user(0);
        foreach (['GET','POST'] as $method) {
            $request=new WP_REST_Request($method,'/ascla/v1/onboarding'.($method==='POST'?'/hub':''));
            $request->set_header('Content-Type','application/json');$request->set_body('{"action":"start"}');
            self::assertSame(401,rest_do_request($request)->get_status());
        }
    }

    public function testSuspendedMembersCannotReadOrWriteGuides(): void
    {
        $user=$this->user();
        update_user_meta($user,'_ascla_suspended',true);
        $this->denied(fn()=>Onboarding::get(),403);
        $this->denied(fn()=>Onboarding::update('hub',['action'=>'start']),403);
    }

    public function testGuidesSupportEnglishWithoutChangingTheProgressIdentifiers(): void
    {
        $user=$this->user();
        update_user_meta($user,'locale','en_US');
        $guide=Onboarding::get()['tours']['hub'];
        self::assertSame('Share with your community',$guide['steps'][0]['title']);
        self::assertSame('overview',$guide['steps'][0]['id']);
        $guide=Onboarding::get()['tours'];
        foreach ($guide as $module=>$definition) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9-]{0,63}$/',$module);
            self::assertSame(count($definition['steps']),count(array_unique(array_column($definition['steps'],'id'))));
        }
    }
}
