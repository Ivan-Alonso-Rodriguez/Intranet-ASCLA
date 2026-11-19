<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Membership,Access,Administration,Audit};
use ASCLA\Core\Repositories\Store;

final class MembershipTest extends TestCase
{
    private array $users=[];
    protected function setUp(): void
    {
        foreach(['administrator','ascla_member','ascla_member','ascla_member'] as $role){
            $name='membership_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach($this->users as $id){
            wp_clear_scheduled_hook('ascla_membership_expired',[$id]);
            foreach(['relations','registrations'] as $table)Store::delete($table,['user_id'=>$id]);
            Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function deny(callable $fn,int $code=400): void
    {
        try{$fn();self::fail('Expected rejection');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame($code,$e->getCode());}
    }
    public function testExpiryRejectsAccessWithoutCronAndReactivationPreservesHistory(): void
    {
        $id=$this->users[1];$profile=['first_name'=>'Conservar','company'=>'Organización'];update_user_meta($id,'_ascla_profile',$profile);
        $pending=Store::insert('relations',['user_id'=>$id,'target_id'=>$this->users[2],'kind'=>'connect','created_at'=>current_time('mysql',true)]);
        $rsvp=Store::insert('registrations',['user_id'=>$id,'event_id'=>991001,'status'=>'accepted','created_at'=>current_time('mysql',true)]);
        $manager=WP_Session_Tokens::get_instance($id);$token=$manager->create(time()+DAY_IN_SECONDS);
        update_user_meta($id,'_ascla_membership_until',time());
        self::assertSame('expired',Membership::status($id));self::assertFalse(Access::member($id));self::assertFalse($manager->verify($token));
        $login=Membership::authenticate(get_userdata($id));self::assertInstanceOf(WP_Error::class,$login);
        self::assertSame('ascla_membership_inactive',$login->get_error_code());
        Membership::apply($id,Membership::validate(['membership_status'=>'active','membership_until'=>''],$id));
        self::assertTrue(Access::member($id));self::assertFalse($manager->verify($token));
        self::assertSame($profile,get_user_meta($id,'_ascla_profile',true));self::assertNotNull(Store::one('relations',$pending));self::assertNotNull(Store::one('registrations',$rsvp));
        self::assertInstanceOf(WP_User::class,Membership::authenticate(get_userdata($id)));
    }
    public function testDateIncludesEntireLocalDayAndSchedulesRevocation(): void
    {
        $id=$this->users[1];$day=wp_date('Y-m-d',time()+3*DAY_IN_SECONDS);
        $value=Membership::validate(['membership_status'=>'active','membership_until'=>$day],$id);Membership::apply($id,$value);
        $expected=(new DateTimeImmutable($day.' 00:00:00',wp_timezone()))->modify('+1 day')->getTimestamp();
        self::assertSame($expected,$value['until']);self::assertSame($expected,wp_next_scheduled('ascla_membership_expired',[$id]));
        self::assertSame($day,Membership::view($id)['membership_until']);
        $manager=WP_Session_Tokens::get_instance($id);$token=$manager->create(time()+DAY_IN_SECONDS);
        Membership::expire($id);self::assertTrue($manager->verify($token));
        Membership::apply($id,Membership::validate(['membership_status'=>'suspended'],$id));self::assertFalse($manager->verify($token));
    }
    public function testInvalidMembershipCannotPartiallyChangeAccount(): void
    {
        $id=$this->users[1];$before=get_userdata($id);
        $input=['email'=>'changed@example.invalid','role'=>'ascla_executive','first_name'=>'Changed','membership_status'=>'active','membership_until'=>'2026-02-30'];
        $this->deny(fn()=>Administration::updateUser($id,$input));
        self::assertSame($before->user_email,get_userdata($id)->user_email);self::assertSame($before->roles,get_userdata($id)->roles);
        foreach([['membership_status'=>'bogus'],['membership_status'=>'expired'],['membership_until'=>'2000-01-01'],['membership_until'=>'2026-02-30']] as $bad)$this->deny(fn()=>Membership::validate($bad));
        update_user_meta($id,'_ascla_membership_until',time()-1);
        $this->deny(fn()=>Membership::validate(['membership_status'=>'active'],$id));
        wp_set_current_user($this->users[2]);$this->deny(fn()=>Membership::apply($id,['status'=>'suspended','until'=>0]),403);
    }
    public function testStateFiltersDistinguishLegacyActiveSuspendedAndExpiredAccounts(): void
    {
        update_user_meta($this->users[2],'_ascla_membership_until',time()-1);
        update_user_meta($this->users[3],'_ascla_suspended',true);
        foreach(['active'=>1,'expired'=>2,'suspended'=>3] as $state=>$index){
            $ids=array_map('intval',get_users(['include'=>array_slice($this->users,1),'fields'=>'ID','meta_query'=>Membership::filter($state)]));
            self::assertSame([$this->users[$index]],$ids);
        }
    }
    public function testCreateSuspendedAndAdministrativeAuditContainsOnlyAllowedDiff(): void
    {
        $login='membership_'.bin2hex(random_bytes(6));
        $created=Administration::createUser(['login'=>$login,'email'=>$login.'@example.invalid','role'=>'ascla_member','first_name'=>'Nueva','membership_status'=>'suspended','send_invite'=>false]);
        $id=$created['id'];$this->users[]=$id;self::assertSame('suspended',$created['membership_status']);self::assertFalse(Access::member($id));
        $roles=array_fill(0,40,'custom_role');
        Audit::changes('audit_membership_test',$id,['roles'=>$roles,'password'=>'NEVER_STORE_THIS'],['roles'=>['ascla_member'],'password'=>'SECRET_VALUE']);
        $rows=Store::rows('audit','object_id=%d AND action=%s',[$id,'audit_membership_test'],'ORDER BY id DESC');$row=$rows[0];
        self::assertGreaterThan(255,strlen($row['detail']));$diff=json_decode($row['detail'],true,512,JSON_THROW_ON_ERROR);
        self::assertSame(['roles'=>['before'=>$roles,'after'=>['ascla_member']]],$diff);self::assertSame($this->users[0],(int)$row['actor_id']);
        ASCLA\Core\Database\AuditMigration::run();self::assertSame($row,Store::one('audit',(int)$row['id']));
    }
}
