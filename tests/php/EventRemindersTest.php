<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,EventReminders,Events};
use ASCLA\Core\Repositories\Store;
final class EventRemindersTest extends TestCase
{
    private array $users=[];private int $event=0;
    protected function setUp(): void
    {
        foreach(['administrator','ascla_executive','ascla_member','ascla_member'] as $role)$this->users[]=wp_insert_user(['user_login'=>'reminder_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>$role]);
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        if($this->event){Store::delete('registrations',['event_id'=>$this->event]);wp_delete_post($this->event,true);}
        foreach($this->users as $id){Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);}wp_set_current_user(0);
    }
    public function testOrdinaryEventSchedulesReplacesAndSendsOnlyConfirmedOnce(): void
    {
        $p=Content::save('event',['title'=>'Recordatorio configurable','body'=>'Reunión','status'=>'publish','meta'=>['start'=>gmdate('c',time()+3*DAY_IN_SECONDS),'end'=>gmdate('c',time()+4*DAY_IN_SECONDS),'reminder_hours'=>[48,2]]]);$this->event=$p['id'];
        self::assertEqualsWithDelta(time()+DAY_IN_SECONDS,wp_next_scheduled('ascla_event_reminder',[$p['id'],48]),2);
        Content::save('event',['title'=>$p['title'],'body'=>$p['body'],'status'=>'publish','meta'=>['reminder_hours'=>[24,1]]],$p['id']);
        self::assertFalse(wp_next_scheduled('ascla_event_reminder',[$p['id'],48]));self::assertIsInt(wp_next_scheduled('ascla_event_reminder',[$p['id'],24]));
        wp_set_current_user($this->users[2]);Events::register($p['id'],'accepted');wp_set_current_user($this->users[3]);Events::register($p['id'],'declined');
        $meta=get_post_meta($p['id'],'_ascla',true);$meta['start']=gmdate('c',time()+3500);update_post_meta($p['id'],'_ascla',$meta);
        EventReminders::send($p['id'],1);EventReminders::send($p['id'],1);
        self::assertSame(1,Store::count('notifications',"user_id=%d AND kind='event_reminder'",[$this->users[2]]));self::assertSame(0,Store::count('notifications',"user_id=%d AND kind='event_reminder'",[$this->users[3]]));
    }
    public function testExecutiveCannotChangeReminderPolicyAndInvalidDateIsRejected(): void
    {
        wp_set_current_user($this->users[1]);
        try{Content::save('event',['title'=>'Evento','body'=>'Contenido','status'=>'draft','meta'=>['start'=>gmdate('c',time()+3*DAY_IN_SECONDS),'end'=>gmdate('c',time()+4*DAY_IN_SECONDS),'reminder_hours'=>[9]]]);self::fail('Executive changed policy');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(403,$e->getCode());}
        try{ASCLA\Core\Services\ContentMeta::eventDate('2027-02-30T10:00:00Z');self::fail('Invalid calendar date accepted');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(400,$e->getCode());}
    }
}
