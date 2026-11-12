<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Settings,Profiles};
use ASCLA\Core\Repositories\Store;

final class CommentThreadsTest extends TestCase
{
    private array $users=[],$posts=[],$settings=[];
    protected function setUp(): void
    {
        $this->settings=Settings::get();
        foreach (['administrator','ascla_member','ascla_member','ascla_member'] as $role) {
            $this->users[]=wp_insert_user(['user_login'=>'thread_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>$role,'display_name'=>'Nombre público']);
        }
        wp_set_current_user($this->users[0]);Settings::save(['moderate_comments'=>false]);
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) wp_delete_post($id,true);
        foreach ($this->users as $id) {
            foreach (['notifications','jobs','relations'] as $table) Store::delete($table,['user_id'=>$id]);
            wp_delete_user($id);
        }
        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function post(): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_hub','post_status'=>'publish','post_title'=>'Conversación','post_author'=>$this->users[0]]);$this->posts[]=$id;return $id;
    }
    private function add(int $post,int $author,int $parent=0,string $status='1'): int
    {
        return (int)wp_insert_comment(['comment_post_ID'=>$post,'user_id'=>$author,'comment_parent'=>$parent,'comment_content'=>'Aporte','comment_approved'=>$status,'comment_date'=>'2026-10-24 10:00:00','comment_date_gmt'=>'2026-10-24 15:00:00']);
    }
    public function testSameTimestampHasStableIdOrderAndOnlyOwnPendingRepliesAreReturned(): void
    {
        $post=$this->post();$root=$this->add($post,$this->users[1]);$second=$this->add($post,$this->users[2],$root);
        $third=$this->add($post,$this->users[1],$second);$own=$this->add($post,$this->users[3],$root,'0');
        $this->add($post,$this->users[2],$root,'0');$this->add($post,$this->users[1],$root,'trash');
        wp_set_current_user($this->users[3]);
        foreach (range(1,3) as $_) self::assertSame([$root,$second,$third,$own],array_column(Content::comments($post),'id'));
    }
    public function testReplyKeepsActualRecipientAndNotifiesThemOnceWithoutFlatteningStoredParents(): void
    {
        $post=$this->post();$root=$this->add($post,$this->users[1]);$reply=$this->add($post,$this->users[2],$root);
        wp_set_current_user($this->users[3]);$result=Content::comment($post,'Gracias por la experiencia.',$reply);
        self::assertSame($reply,(int)get_comment($result['id'])->comment_parent);
        self::assertSame('Gracias por la experiencia.',get_comment($result['id'])->comment_content);
        foreach (range(1,2) as $_) Content::notifyComment($result['id']);
        self::assertSame(1,Store::count('notifications',"user_id=%d AND kind='comment_reply'",[$this->users[2]]));
        self::assertSame(0,Store::count('notifications',"user_id=%d AND kind='comment_reply'",[$this->users[1]]));
        self::assertSame(1,Store::count('notifications',"user_id=%d AND kind='comment'",[$this->users[0]]));
    }
    public function testPrivateNamesStayAliasedWithoutExposingLoginOrEmail(): void
    {
        $post=$this->post();$root=$this->add($post,$this->users[1]);
        update_user_meta($this->users[1],'_ascla_profile',['hidden'=>['first_name']]);
        wp_set_current_user($this->users[3]);$comment=Content::comments($post)[0];
        self::assertSame($root,$comment['id']);self::assertSame(Profiles::publicName($this->users[1]),$comment['author']);
        self::assertStringNotContainsString(get_userdata($this->users[1])->user_login,wp_json_encode($comment));
        self::assertArrayNotHasKey('user_login',$comment);self::assertArrayNotHasKey('email',$comment);
    }
}
