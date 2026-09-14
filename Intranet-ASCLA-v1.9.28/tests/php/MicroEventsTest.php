<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{MicroEvents,Content,Settings,Profiles};
use ASCLA\Core\Repositories\Store;
final class MicroEventsTest extends TestCase
{
    public function testMonthlyProposalIsIdempotentAndInvitationsWaitForApproval(): void
    {
        $month=wp_date('Y-m');$saved=get_option('ascla_micro_'.$month);$history=get_option('ascla_group_history',[]);$settings=Settings::get();$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);delete_option('ascla_micro_'.$month);Settings::save(['micro_approval'=>true]);
        $events=[];
        try {
            $r=MicroEvents::create();$events=$r['events'];self::assertNotEmpty($events);self::assertSame($r,MicroEvents::create());
            foreach($events as $id){self::assertSame('pending',get_post_status($id));self::assertSame(0,Store::count('registrations','event_id=%d',[$id]));$meta=get_post_meta($id,'_ascla',true);self::assertGreaterThanOrEqual(4,count($meta['invitees']));self::assertLessThanOrEqual(6,count($meta['invitees']));}
            $id=$events[0];Content::moderate($id,'approve','Agenda y participantes revisados.');$meta=get_post_meta($id,'_ascla',true);self::assertTrue($meta['invited']);self::assertSame(count($meta['invitees']),Store::count('registrations','event_id=%d',[$id]));MicroEvents::invite($id);self::assertSame(count($meta['invitees']),Store::count('registrations','event_id=%d',[$id]));
            $outsider=get_users(['capability'=>'ascla_access','exclude'=>$meta['invitees'],'number'=>1])[0];if(!user_can($outsider,'ascla_moderate')){wp_set_current_user($outsider->ID);self::assertFalse(Content::canRead(get_post($id)));}
        } finally {
            foreach($events as $id){Store::delete('registrations',['event_id'=>$id]);global $wpdb;$wpdb->query($wpdb->prepare('DELETE FROM '.Store::table('notifications')." WHERE kind='microevent' AND url LIKE %s",'%item='.$id));wp_delete_post($id,true);}
            if($saved)update_option('ascla_micro_'.$month,$saved,false);else delete_option('ascla_micro_'.$month);update_option('ascla_group_history',$history,false);update_option('ascla_settings',$settings,false);wp_set_current_user(0);
        }
    }

    public function testDeletedMonthlyProposalsDoNotLeaveStaleReviewLinks(): void
    {
        $month=wp_date('Y-m');$saved=get_option('ascla_micro_'.$month);$history=get_option('ascla_group_history',[]);$settings=Settings::get();$admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);delete_option('ascla_micro_'.$month);Settings::save(['micro_approval'=>true]);
        $created=[];$regenerated=[];
        try {
            $first=MicroEvents::create();$created=$first['events'];self::assertNotEmpty($created);
            foreach($created as $id){Content::remove($id);self::assertSame('trash',get_post_status($id));}
            self::assertFalse(get_option('ascla_micro_'.$month));
            $second=MicroEvents::create();$regenerated=$second['events'];self::assertNotEmpty($regenerated);
            foreach($regenerated as $id){self::assertNotContains($id,$created);self::assertNotSame('trash',get_post_status($id));}
        } finally {
            foreach(array_merge($created,$regenerated) as $id){Store::delete('registrations',['event_id'=>$id]);wp_delete_post($id,true);}
            if($saved)update_option('ascla_micro_'.$month,$saved,false);else delete_option('ascla_micro_'.$month);update_option('ascla_group_history',$history,false);update_option('ascla_settings',$settings,false);wp_set_current_user(0);
        }
    }
}
