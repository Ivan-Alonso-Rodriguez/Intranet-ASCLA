<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Frontend\LoginCsrf;

final class LoginCsrfTest extends TestCase
{
    private array $cookies,$post,$server;
    private $page;
    protected function setUp(): void
    {
        $this->cookies=$_COOKIE;$this->post=$_POST;$this->server=$_SERVER;$this->page=$GLOBALS['pagenow']??null;
        wp_set_current_user(0);unset($_SERVER['HTTPS']);$_SERVER['SERVER_PORT']='80';
        $_COOKIE['ascla_login_guest']=str_repeat('a',64);
    }
    protected function tearDown(): void
    {
        $_COOKIE=$this->cookies;$_POST=$this->post;$_SERVER=$this->server;$GLOBALS['pagenow']=$this->page;wp_set_current_user(0);
    }
    public function testLoginNonceFromAnotherAnonymousBrowserCannotBeReused(): void
    {
        $nonce=wp_create_nonce('ascla_frontend_login');self::assertNotFalse(wp_verify_nonce($nonce,'ascla_frontend_login'));
        $_COOKIE['ascla_login_guest']=str_repeat('b',64);
        self::assertFalse(wp_verify_nonce($nonce,'ascla_frontend_login'));
        $_COOKIE['ascla_login_guest']=str_repeat('a',64);
        self::assertNotFalse(wp_verify_nonce($nonce,'ascla_frontend_login'));
    }
    public function testOtherWordPressGuestNonceActionsKeepTheirDefaultIdentity(): void
    {
        self::assertSame(0,LoginCsrf::guestUser(0,'another_plugin_action'));
        self::assertSame(42,LoginCsrf::guestUser(42,'another_plugin_action'));
    }
    public function testNativeLoginRejectsMissingOrForeignNonceBeforeIssuingAuthentication(): void
    {
        $GLOBALS['pagenow']='wp-login.php';$_SERVER['REQUEST_METHOD']='POST';$user=new WP_User();$user->ID=123;
        $_POST=[];self::assertWPError(LoginCsrf::nativeLogin($user,'synthetic','test-password'));
        $nonce=wp_create_nonce('ascla_native_login');$_POST['_ascla_native_login_nonce']=$nonce;
        self::assertSame($user,LoginCsrf::nativeLogin($user,'synthetic','test-password'));
        $_COOKIE['ascla_login_guest']=str_repeat('c',64);
        self::assertWPError(LoginCsrf::nativeLogin($user,'synthetic','test-password'));
    }
    public function testInternalAndRestAuthenticationAreNotTreatedAsNativeLoginForms(): void
    {
        $GLOBALS['pagenow']='index.php';$_SERVER['REQUEST_METHOD']='POST';$user=new WP_User();$user->ID=123;
        self::assertSame($user,LoginCsrf::nativeLogin($user,'synthetic','test-password'));
    }
    private static function assertWPError($value): void { self::assertInstanceOf(WP_Error::class,$value);self::assertSame('ascla_login_expired',$value->get_error_code()); }
}
