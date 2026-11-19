<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{CredentialPolicy,Settings,Turnstile,TurnstileState,Account};
final class CredentialPolicyTest extends TestCase
{
    private array $settings,$post,$server;
    private int $user;
    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->post=$_POST;$this->server=$_SERVER;
        $login='cred_'.bin2hex(random_bytes(4));
        $this->user=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','first_name'=>'Mariana','last_name'=>'Rivadeneyra','user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        wp_set_current_user($this->user);Settings::save(CredentialPolicy::DEFAULTS);
    }
    protected function tearDown(): void
    {
        update_option('ascla_settings',$this->settings,false);$_POST=$this->post;$_SERVER=$this->server;
        ASCLA\Core\Repositories\Store::delete('audit',['actor_id'=>$this->user]);wp_delete_user($this->user);wp_set_current_user(0);
    }
    public function testNormalPasswordsDoNotRequireEveryCharacterClass(): void
    {
        $u=get_userdata($this->user);
        foreach(['short','12345678','password123','Contraseña','AAAAAAAA','        ','   sol  '] as $weak){
            self::assertNotSame('',CredentialPolicy::error($weak,$u));
        }
        foreach(['nubeazul','NUBEAZUL','Mariana1','29475186','mi nueva frase','Segura-Abcd!792','J9!mQ7#vR2@tL8'] as $normal) {
            self::assertSame('',CredentialPolicy::error($normal,$u),$normal);
        }
        Settings::save(['password_min_length'=>20]);self::assertStringContainsString('20',CredentialPolicy::error('J9!mQ7#vR2@tL8',$u));
    }
    public function testNativeResetAndRestCannotBypassPolicy(): void
    {
        $u=get_userdata($this->user);$_POST['pass1']='weak';
        $errors=new WP_Error();do_action('validate_password_reset',$errors,$u);self::assertSame('ascla_password_policy',$errors->get_error_code());
        $profile=(object)['ID'=>$this->user,'user_pass'=>'weak'];$errors=new WP_Error();CredentialPolicy::profileValidation($errors,true,$profile);
        self::assertSame('ascla_password_policy',$errors->get_error_code());
        $r=new WP_REST_Request('POST','/wp/v2/users/'.$this->user);$r->set_param('id',$this->user);$r->set_param('password','weak');
        $result=CredentialPolicy::restValidation((object)['user_pass'=>'weak'],$r);self::assertInstanceOf(WP_Error::class,$result);self::assertSame(400,$result->get_error_data()['status']);
        $r->set_param('password','nubeazul');self::assertNotInstanceOf(WP_Error::class,CredentialPolicy::restValidation((object)['user_pass'=>'nubeazul'],$r));
        $_POST['pass1']='nubeazul';$errors=new WP_Error();do_action('validate_password_reset',$errors,$u);self::assertFalse($errors->has_errors());
        $errors=new WP_Error();CredentialPolicy::profileValidation($errors,true,(object)['ID'=>$this->user,'user_pass'=>'nubeazul']);self::assertFalse($errors->has_errors());
    }
    public function testResetExpiryAndRememberDurationUseConfiguredLimits(): void
    {
        Settings::save(['password_reset_minutes'=>30,'remember_days'=>7]);
        self::assertSame(1800,apply_filters('password_reset_expiration',DAY_IN_SECONDS));
        self::assertSame(7*DAY_IN_SECONDS,apply_filters('auth_cookie_expiration',DAY_IN_SECONDS,$this->user,true));
        self::assertSame(DAY_IN_SECONDS,apply_filters('auth_cookie_expiration',DAY_IN_SECONDS,$this->user,false));
        $key=get_password_reset_key(get_userdata($this->user));self::assertIsString($key);
        global $wpdb;
        $saved=get_userdata($this->user)->user_activation_key;
        $wpdb->update($wpdb->users,['user_activation_key'=>(time()-1801).':'.explode(':',$saved,2)[1]],['ID'=>$this->user]);clean_user_cache($this->user);
        $result=check_password_reset_key($key,get_userdata($this->user)->user_login);self::assertInstanceOf(WP_Error::class,$result);self::assertSame('expired_key',$result->get_error_code());
    }
    public function testConfiguredThresholdLocksCorrectPasswordAndInvalidSettingsAreAtomic(): void
    {
        Settings::save(['login_challenge_after'=>2,'login_lock_after'=>4,'login_lock_minutes'=>30,'turnstile_enabled'=>false]);
        $_REQUEST['ascla_frontend_login']=1;$_SERVER['REMOTE_ADDR']='2001:db8:'.bin2hex(random_bytes(2)).'::1';
        $login=get_userdata($this->user)->user_login;
        try{
            for($i=0;$i<4;$i++)Turnstile::loginFailed($login,new WP_Error('incorrect_password','wrong'));
            $result=Turnstile::authenticate(get_userdata($this->user),$login,'correct');
            self::assertInstanceOf(WP_Error::class,$result);self::assertSame('ascla_login_locked',$result->get_error_code());
            self::assertEqualsWithDelta(time()+1800,TurnstileState::state('login',$login)['lock_until'],3);
        }finally{unset($_REQUEST['ascla_frontend_login']);TurnstileState::clearState('login',$login);}
        $before=Settings::get();
        try{Settings::save(['password_min_length'=>7,'remember_days'=>90]);self::fail('Invalid configuration saved');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(400,$e->getCode());}
        self::assertSame($before,Settings::get());
    }
    public function testUpgradeReplacesLegacyDefaultOnlyOnce(): void
    {
        $version=get_option('ascla_password_policy_version',false);
        try {
            $settings=Settings::get();$settings['password_min_length']=12;update_option('ascla_settings',$settings,false);delete_option('ascla_password_policy_version');
            CredentialPolicy::upgrade();self::assertSame(8,Settings::get()['password_min_length']);
            Settings::save(['password_min_length'=>12]);CredentialPolicy::upgrade();self::assertSame(12,Settings::get()['password_min_length']);
            delete_option('ascla_password_policy_version');Settings::save(['password_min_length'=>20]);CredentialPolicy::upgrade();self::assertSame(20,Settings::get()['password_min_length']);
        } finally {
            if ($version===false) { delete_option('ascla_password_policy_version'); } else { update_option('ascla_password_policy_version',$version,false); }
        }
    }
    public function testProfilePasswordChangeAcceptsEightCharactersAndStillRequiresCurrentPassword(): void
    {
        wp_set_password('contraseña anterior',$this->user);
        $result=Account::changePassword(['current_password'=>'contraseña anterior','new_password'=>'nubeazul','confirm_password'=>'nubeazul']);
        self::assertTrue($result['ok']);self::assertTrue(wp_check_password('nubeazul',get_userdata($this->user)->user_pass,$this->user));
        try {
            Account::changePassword(['current_password'=>'incorrecta','new_password'=>'otranube','confirm_password'=>'otranube']);self::fail('Current password must be checked');
        } catch (ASCLA\Core\Rest\ApiException $error) { self::assertSame(400,$error->getCode()); }
    }
}
