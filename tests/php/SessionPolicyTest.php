<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\SessionPolicy;
use ASCLA\Core\Repositories\Store;

final class SessionPolicyTest extends TestCase
{
    private int $user;
    private array $cookies;
    private string $token;
    protected function setUp(): void
    {
        $this->cookies=$_COOKIE;
        $this->user=wp_insert_user(['user_login'=>'session_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        $this->token=WP_Session_Tokens::get_instance($this->user)->create(time()+DAY_IN_SECONDS);
        $_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($this->user,time()+DAY_IN_SECONDS,'logged_in',$this->token);
        wp_set_current_user($this->user);
        SessionPolicy::validateUser($this->user);
    }
    protected function tearDown(): void
    {
        WP_Session_Tokens::get_instance($this->user)->destroy_all();
        Store::delete('relations',['user_id'=>$this->user]);
        wp_delete_user($this->user);$_COOKIE=$this->cookies;
        SessionPolicy::validateUser(0);wp_set_current_user(0);
    }
    private function age(int $seconds): void
    {
        $manager=WP_Session_Tokens::get_instance($this->user);
        $data=$manager->get($this->token);$data['ascla_activity']=time()-$seconds;$manager->update($this->token,$data);
    }
    public function testPollingDoesNotRenewAndExplicitActivityDoes(): void
    {
        $this->age(1200);
        self::assertEqualsWithDelta(600,SessionPolicy::status()['remaining'],2);
        self::assertEqualsWithDelta(600,SessionPolicy::status()['remaining'],2);
        self::assertEqualsWithDelta(1800,SessionPolicy::status(true)['remaining'],2);
        self::assertTrue(SessionPolicy::validateToken($this->user,$this->token));
    }
    public function testThirtyMinutesExpiresEvenRememberedCookieAndCannotBeExtended(): void
    {
        $this->age(1800);
        self::assertSame(0,SessionPolicy::validateUser($this->user));
        self::assertFalse(WP_Session_Tokens::get_instance($this->user)->verify($this->token));
        $error=SessionPolicy::authenticationError(null);
        self::assertInstanceOf(WP_Error::class,$error);self::assertSame(401,$error->get_error_data()['status']);
        try { SessionPolicy::status(true);self::fail('An expired token was renewed'); }
        catch (ASCLA\Core\Rest\ApiException $e) { self::assertSame(401,$e->getCode()); }
    }
    public function testSuspendingMembershipRevokesEverySessionAndPreservesPendingRequests(): void
    {
        $manager=WP_Session_Tokens::get_instance($this->user);$second=$manager->create(time()+DAY_IN_SECONDS);
        $pending=Store::insert('relations',['user_id'=>$this->user,'target_id'=>1,'kind'=>'connect','created_at'=>current_time('mysql',true)]);
        $connected=Store::insert('relations',['user_id'=>$this->user,'target_id'=>1,'kind'=>'connected','created_at'=>current_time('mysql',true)]);
        update_user_meta($this->user,'_ascla_suspended',true);
        self::assertFalse($manager->verify($this->token));self::assertFalse($manager->verify($second));
        self::assertNotNull(Store::one('relations',$pending));self::assertNotNull(Store::one('relations',$connected));
        update_user_meta($this->user,'_ascla_suspended',false);
        self::assertFalse($manager->verify($second),'Reactivation must not restore previously revoked sessions');
    }
}
