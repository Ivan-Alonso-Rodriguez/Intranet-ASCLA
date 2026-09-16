<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Integrations\Secrets;
use ASCLA\Core\Services\{Settings,Turnstile};

final class TurnstileTest extends TestCase
{
    private array $settings=[];
    private string $secret='';
    private $http=null;
    private int $admin=0;
    private string $pagenow='';

    protected function setUp(): void
    {
        $this->pagenow=(string)($GLOBALS['pagenow']??'');
        $GLOBALS['pagenow']='wp-login.php';
        $this->settings=Settings::get();
        $this->secret=Secrets::get('turnstile_secret');
        $this->admin=(int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0];
        wp_set_current_user($this->admin);
        Settings::save([
            'turnstile_enabled'=>true,
            'turnstile_site_key'=>'1x00000000000000000000AA',
            'turnstile_secret'=>'1x0000000000000000000000000000000AA',
            'turnstile_login'=>true,
            'turnstile_recovery'=>true,
            'turnstile_public'=>true,
        ]);
        wp_set_current_user(0);
        $_SERVER['REMOTE_ADDR']='203.0.113.'.random_int(10,200);
        $_SERVER['HTTP_USER_AGENT']='ASCLA PHPUnit Browser';
        unset($_COOKIE['ascla_turnstile_trust'],$_COOKIE['ascla_turnstile_login_hint'],$_COOKIE['ascla_turnstile_recovery_hint']);
        unset($_POST['cf-turnstile-response'],$_POST['log'],$_POST['user_login']);
    }

    protected function tearDown(): void
    {
        if($this->http)remove_filter('pre_http_request',$this->http,10);
        wp_set_current_user($this->admin);
        update_option('ascla_settings',$this->settings,false);
        Secrets::remove('turnstile_secret');
        if($this->secret!=='')Secrets::set('turnstile_secret',$this->secret);
        wp_set_current_user(0);
        $GLOBALS['pagenow']=$this->pagenow;
        unset($_COOKIE['ascla_turnstile_trust'],$_COOKIE['ascla_turnstile_login_hint'],$_COOKIE['ascla_turnstile_recovery_hint']);
    }

    private function mock(callable $handler): void
    {
        $this->http=static fn($pre,$args,$url)=>$handler($args,$url);
        add_filter('pre_http_request',$this->http,10,3);
    }

    public function testLoginBecomesChallengedAfterThreeCredentialFailuresAndLocksAtFive(): void
    {
        $id='person-'.bin2hex(random_bytes(3));
        self::assertFalse(Turnstile::loginChallengeRequired($id));
        for($i=0;$i<3;$i++)Turnstile::loginFailed($id,new WP_Error('incorrect_password','bad'));
        self::assertTrue(Turnstile::loginChallengeRequired($id));
        for($i=3;$i<5;$i++)Turnstile::loginFailed($id,new WP_Error('incorrect_password','bad'));
        $result=Turnstile::authenticate(null,$id,'wrong-password');
        self::assertInstanceOf(WP_Error::class,$result);
        self::assertSame('ascla_login_locked',$result->get_error_code());
    }


    public function testIpBurstIsTemporarilyBlockedAcrossDifferentUsernames(): void
    {
        for($i=0;$i<20;$i++){
            Turnstile::loginFailed('burst-'.$i,new WP_Error('incorrect_password','bad'));
        }
        $result=Turnstile::authenticate(null,'another-user','wrong-password');
        self::assertInstanceOf(WP_Error::class,$result);
        self::assertSame('ascla_login_locked',$result->get_error_code());
    }

    public function testServerValidationUsesSiteverifyAndCreatesTwentyFourHourTrust(): void
    {
        $_POST['cf-turnstile-response']='test-token';
        $this->mock(static function($args,$url){
            self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify',$url);
            self::assertSame('1x0000000000000000000000000000000AA',$args['body']['secret']);
            self::assertSame('test-token',$args['body']['response']);
            self::assertNotEmpty($args['body']['remoteip']);
            return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['success'=>true,'action'=>'ascla_login','hostname'=>'localhost'])];
        });
        self::assertTrue(Turnstile::verifyRequest('login','ascla_login'));
        self::assertTrue(Turnstile::trusted());
    }

    public function testPublicAccountRegistrationIsDisabled(): void
    {
        self::assertFalse((bool)get_option('users_can_register'));
        self::assertArrayNotHasKey('turnstile_register', Settings::get());
    }

    public function testAuthenticatedUsersNeverReceiveTurnstile(): void
    {
        wp_set_current_user($this->admin);
        self::assertFalse(Turnstile::protects('login'));
        self::assertFalse(Turnstile::protects('recovery'));
    }
}
