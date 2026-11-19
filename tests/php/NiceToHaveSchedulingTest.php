<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Content;
use ASCLA\Core\Repositories\Store;

final class NiceToHaveSchedulingTest extends TestCase
{
    public function testScheduledContentStaysHiddenUntilTheCronPublishesItForBothAuthorizedRoles(): void
    {
        $users=[];$posts=[];
        try {
            foreach (['administrator','ascla_executive','ascla_member'] as $role) {
                $id=wp_insert_user(['user_login'=>'nice_schedule_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>$role]);$users[]=$id;
                update_user_meta($id,'_ascla_profile',ascla_test_profile());
            }
            $at=time()+4;
            foreach (array_slice($users,0,2) as $user) {
                wp_set_current_user($user);
                $item=Content::save('hub',['title'=>'Publicación diferida','body'=>'Contenido institucional programado.','status'=>'scheduled','publish_at'=>gmdate('c',$at)]);
                $id=$item['id'];$posts[]=$id;
                self::assertSame('ascla_hidden',get_post_status($id));
                self::assertNotFalse(wp_next_scheduled('ascla_publish_content',[$id,$at]));
                do_action('ascla_publish_content',$id,$at);
                self::assertSame('ascla_hidden',get_post_status($id),'Early callbacks cannot publish future content.');
                wp_set_current_user($users[2]);self::assertFalse(Content::canRead(get_post($id)));
            }
            try {
                Content::save('hub',['title'=>'Sin permiso','body'=>'Contenido','status'=>'scheduled','publish_at'=>gmdate('c',time()+3600)]);self::fail('Member scheduled institutional content');
            } catch (ASCLA\Core\Rest\ApiException $error) { self::assertSame(403,$error->getCode()); }
            sleep(max(0,$at-time()+1));
            foreach ($posts as $id) {
                do_action('ascla_publish_content',$id,$at);
                self::assertSame('publish',get_post_status($id));self::assertTrue(Content::canRead(get_post($id)));
                self::assertSame(1,Store::count('audit',"object_id=%d AND action='content_scheduled_published'",[$id]));
                do_action('ascla_publish_content',$id,$at);
                self::assertSame(1,Store::count('audit',"object_id=%d AND action='content_scheduled_published'",[$id]),'Duplicate delivery must remain idempotent.');
            }
        } finally {
            foreach ($posts as $id) { wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]); }
            foreach ($users as $id) { Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id); }
            wp_set_current_user(0);
        }
    }
}
