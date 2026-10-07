<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{AccountRecovery,Settings,Turnstile,TurnstileState};
use ASCLA\Core\Integrations\Secrets;
final class AccountRecoveryTest extends TestCase
{
    private array $settings,$post,$server,$cookies;private string $secret;private int $user;private $mail;
    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->post=$_POST;$this->server=$_SERVER;$this->cookies=$_COOKIE;$this->secret=Secrets::get('turnstile_secret');
        $login='recovery_'.bin2hex(random_bytes(4));$this->user=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        $this->mail=static fn()=>true;add_filter('pre_wp_mail',$this->mail);
        $_POST=[];$_COOKIE=[];$_SERVER['REMOTE_ADDR']='2001:db8:'.bin2hex(random_bytes(3)).'::2';
        Settings::save(['turnstile_enabled'=>false]);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail',$this->mail);update_option('ascla_settings',$this->settings,false);
        Secrets::remove('turnstile_secret');if($this->secret!=='')Secrets::set('turnstile_secret',$this->secret);
        $_POST=$this->post;$_SERVER=$this->server;$_COOKIE=$this->cookies;wp_delete_user($this->user);
    }
    public function testKnownUnknownAndDeliveryFailureHaveIdenticalPublicResult(): void
    {
        self::assertTrue(AccountRecovery::request(get_userdata($this->user)->user_email));
        self::assertNotSame('',get_userdata($this->user)->user_activation_key);
        self::assertTrue(AccountRecovery::request('unknown_'.bin2hex(random_bytes(5)).'@example.invalid'));
        $fail=static fn()=>false;add_filter('pre_wp_mail',$fail,20);
        try{self::assertTrue(AccountRecovery::request(get_userdata($this->user)->user_email));}finally{remove_filter('pre_wp_mail',$fail,20);}
        self::assertSame('empty_username',AccountRecovery::request('')->get_error_code());
    }
    public function testUnknownAddressesCountTowardCaptchaAndCannotBypassIt(): void
    {
        Settings::save(['turnstile_enabled'=>true,'turnstile_recovery'=>true,'turnstile_site_key'=>'1x00000000000000000000AA','turnstile_secret'=>'1x0000000000000000000000000000000AA']);
        $email='missing_'.bin2hex(random_bytes(4)).'@example.invalid';$_POST['user_login']=$email;
        self::assertTrue(AccountRecovery::request($email));self::assertTrue(AccountRecovery::request($email));
        self::assertTrue(Turnstile::recoveryChallengeRequired($email));
        $result=AccountRecovery::request($email);self::assertInstanceOf(WP_Error::class,$result);self::assertSame('ascla_turnstile_required',$result->get_error_code());
        self::assertSame(2,TurnstileState::state('recovery',$email)['count']);
        TurnstileState::clearState('recovery',$email);
    }
}
