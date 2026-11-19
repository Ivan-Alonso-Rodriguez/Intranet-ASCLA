<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Birthdays,Profiles};
use ASCLA\Core\Repositories\Store;

final class NiceToHaveBirthdayTest extends TestCase
{
    private array $users=[];
    private mixed $mailFilter;

    protected function setUp(): void
    {
        foreach (['administrator','ascla_executive','ascla_moderator','ascla_member','ascla_member'] as $role) {
            $id=wp_insert_user(['user_login'=>'nice_birth_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(32),'user_email'=>'birthday_'.bin2hex(random_bytes(4)).'@example.invalid','role'=>$role]);$this->users[]=$id;
            update_user_meta($id,'_ascla_profile',ascla_test_profile());
        }
        $this->mailFilter=static fn()=>true;add_filter('pre_wp_mail',$this->mailFilter,1);
        wp_set_current_user($this->users[3]);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail',$this->mailFilter,1);
        foreach ($this->users as $id) {
            Store::delete('notifications',['event_key'=>'birthday:'.current_datetime()->format('Y').':'.$id]);
            Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }

    public function testMemberCanSaveClearAndUpdatePrivateBirthDateAndOnlyTheirOwnGreetingAppears(): void
    {
        $date='1980-'.current_datetime()->format('m-d');
        $own=Profiles::save(['birth_date'=>$date]);self::assertSame($date,$own['birth_date']);self::assertTrue(Birthdays::info()['today']);
        wp_set_current_user($this->users[4]);
        self::assertArrayNotHasKey('birth_date',Profiles::visible($this->users[3]));self::assertFalse(Birthdays::info()['today']);
        wp_set_current_user($this->users[3]);self::assertSame('',Profiles::save(['birth_date'=>''])['birth_date']);self::assertFalse(Birthdays::info()['today']);
        self::assertSame('1980-02-29',Birthdays::normalize('1980-02-29'));
        foreach (['2025-02-29',gmdate('Y-m-d',time()+YEAR_IN_SECONDS)] as $invalid) {
            try { Birthdays::normalize($invalid);self::fail('Invalid birth date accepted'); }
            catch (ASCLA\Core\Rest\ApiException $error) { self::assertSame(400,$error->getCode()); }
        }
    }

    public function testBirthdayNoticeIsRestrictedToActiveAuthorizedStaffAndDeliveredOnce(): void
    {
        update_user_meta($this->users[3],'_ascla_profile',ascla_test_profile(['birth_date'=>'1980-'.current_datetime()->format('m-d')]));
        Birthdays::celebrate($this->users[3]);Birthdays::celebrate($this->users[3]);
        foreach ($this->users as $index=>$id) {
            self::assertSame($index<2?1:0,Store::count('notifications',"user_id=%d AND kind='birthday'",[$id]));
        }
        self::assertSame(current_datetime()->format('Y'),get_user_meta($this->users[3],'_ascla_birthday_mail_year',true));
    }
}
