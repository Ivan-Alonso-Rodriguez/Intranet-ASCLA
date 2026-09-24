<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Frontend\{Language,Login};

final class LoginBehaviorTest extends TestCase
{
    private array $post,$get,$cookie,$request,$server;
    private mixed $action,$page;
    protected function setUp(): void
    {
        $this->post=$_POST;$this->get=$_GET;$this->cookie=$_COOKIE;$this->request=$_REQUEST;$this->server=$_SERVER;
        $this->action=$GLOBALS['action']??null;$this->page=$GLOBALS['pagenow']??null;
        $_POST=$_GET=$_COOKIE=$_REQUEST=[];$GLOBALS['pagenow']='wp-login.php';
        $_SERVER['REQUEST_URI']='/wp-login.php';
    }
    protected function tearDown(): void
    {
        $_POST=$this->post;$_GET=$this->get;$_COOKIE=$this->cookie;$_REQUEST=$this->request;$_SERVER=$this->server;
        $GLOBALS['action']=$this->action;$GLOBALS['pagenow']=$this->page;
    }
    public function testSignedReturnCookieRejectsTamperingAndMalformedValues(): void
    {
        $target=home_url('/eventos/?item=123');
        $payload=rtrim(strtr(base64_encode($target),'+/','-_'),'=');
        $_COOKIE['ascla_login_return']=$payload.'.'.hash_hmac('sha256',$payload,wp_salt('auth'));
        self::assertSame($target,Login::requestedTarget());
        $_COOKIE['ascla_login_return']=$payload.'.'.str_repeat('0',64);
        self::assertSame('',Login::requestedTarget());
        foreach (['', 'no-dot', ['invalid']] as $invalid) {
            $_COOKIE['ascla_login_return']=$invalid;
            self::assertSame('',Login::requestedTarget());
        }
        $bad='@@@';
        $_COOKIE['ascla_login_return']=$bad.'.'.hash_hmac('sha256',$bad,wp_salt('auth'));
        self::assertSame('',Login::requestedTarget());
        $_GET['redirect_to']=$target;
        self::assertSame($target,Login::requestedTarget());
        $_POST['redirect_to']=home_url('/hub/');
        self::assertSame(home_url('/hub/'),Login::requestedTarget());
    }
    public function testFrontendRequestDetectionAndNativeAdminContext(): void
    {
        self::assertFalse(Login::isFrontendRequest());
        self::assertTrue(Login::nativeAdminContext());
        $_SERVER['REQUEST_URI']='/subsite/login/?ignored=yes';
        self::assertTrue(Login::isFrontendRequest());
        self::assertFalse(Login::nativeAdminContext());
        $_SERVER['REQUEST_URI']='/login-evil/';
        self::assertFalse(Login::isFrontendRequest());
        $_REQUEST['interim-login']=1;
        self::assertFalse(Login::nativeAdminContext());
        $_REQUEST=['ascla_frontend_login'=>1];
        self::assertTrue(Login::isFrontendRequest());
    }
    public static function actions(): array
    {
        return [
            ['lostpassword','Recupera tu acceso','Recover your access'],
            ['resetpass','Elige tu nueva contraseña','Choose a new password'],
            ['register','Acceso exclusivo para asociados','Members-only access'],
            ['custom','Tu cuenta ASCLA','Your ASCLA account'],
        ];
    }
    #[DataProvider('actions')]
    public function testWelcomePreservesExistingMessageAndUsesSelectedLanguage(string $action,string $spanish,string $english): void
    {
        $GLOBALS['action']=$action;
        foreach (['es_ES'=>$spanish,'en_US'=>$english] as $locale=>$expected) {
            $_GET['wp_lang']=$locale;
            $message=Login::welcome('<p>Existing notice</p>');
            self::assertStringContainsString($expected,$message);
            self::assertStringContainsString('Existing notice',$message);
        }
    }
    public function testTranslationIsScopedAndSelectorEscapesRedirectInput(): void
    {
        self::assertSame('original',Login::translate('original','Password','another-plugin'));
        self::assertSame('Contraseña',Login::translate('Password','Password','default'));
        $_GET['wp_lang']='en_US';
        self::assertSame('Password',Login::translate('Password','Password','default'));
        $_GET['redirect_to']='<script>alert("x")</script>';
        ob_start();Language::selector();$html=ob_get_clean();
        self::assertStringNotContainsString('<script>',$html);
        self::assertStringContainsString('&lt;script&gt;',$html);
        self::assertSame(2,substr_count($html,'<option '));
        $_GET['interim-login']=1;
        ob_start();Language::selector();self::assertSame('',ob_get_clean());
    }

    public function testBootRegistersAuthenticationPrivacyAndBrandingHooks(): void
    {
        $savedHooks=array_map(static fn($hook)=>clone $hook,$GLOBALS['wp_filter']);
        try {
            Login::boot();
            self::assertSame(0,apply_filters('pre_option_users_can_register',false));
            self::assertContains('ascla-admin-login',apply_filters('login_body_class',[]));
            self::assertSame(admin_url(),apply_filters('login_headerurl',''));
            self::assertStringContainsString('Administración',apply_filters('login_headertext',''));
            self::assertStringContainsString('Administración ASCLA',apply_filters('login_title','','Login'));
            self::assertStringContainsString('/login',apply_filters('login_site_html_link',''));
            self::assertNotEmpty(apply_filters('login_remember_me_help_text',''));
            self::assertFalse(apply_filters('login_display_language_dropdown',true));
            $query=apply_filters('wp_sitemaps_posts_query_args',['post__not_in'=>[123]],'page');
            self::assertContains(123,$query['post__not_in']);
            self::assertContains(Login::pageId(),$query['post__not_in']);
            self::assertSame([],apply_filters('wp_sitemaps_posts_query_args',[],'post'));
            $_REQUEST['ascla_frontend_login']=1;
            self::assertNotContains('ascla-admin-login',apply_filters('login_body_class',[]));
            self::assertSame(Login::url(),apply_filters('login_headerurl',''));
            self::assertStringContainsString('Comunidad',apply_filters('login_headertext',''));
            $_GET['wp_lang']='en_US';
            self::assertSame('Remember Me',Login::translate('Remember Me','Remember Me','default'));
            self::assertStringContainsString('en-US',apply_filters('language_attributes','lang="es-ES"'));
            ob_start();do_action('lostpassword_form');$form=ob_get_clean();
            self::assertStringContainsString('name="_ascla_locale"',$form);
            self::assertStringContainsString('value="en_US"',$form);
        } finally {
            $GLOBALS['wp_filter']=$savedHooks;
        }
    }
}
