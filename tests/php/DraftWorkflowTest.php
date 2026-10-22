<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\Content;

final class DraftWorkflowTest extends TestCase
{
    private array $users=[],$posts=[];
    protected function setUp(): void
    {
        foreach (['ascla_executive','ascla_member','administrator'] as $role) {
            $name='draftworkflow_'.bin2hex(random_bytes(5));
            $this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);
        }
        add_filter('pre_wp_mail','__return_true');
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) wp_delete_post($id,true);
        foreach ($this->users as $id) wp_delete_user($id);
        remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    private function request(string $type,array $input,int $id=0): WP_REST_Response
    {
        $request=new WP_REST_Request('POST','/ascla/v1/content/'.$type.($id?'/'.$id:''));
        $request->set_header('Content-Type','application/json');
        $request->set_body(wp_json_encode($input));
        return rest_get_server()->dispatch($request);
    }
    private function draft(string $type,int $actor=0): array
    {
        wp_set_current_user($this->users[$actor]);
        $response=$this->request($type,['status'=>'draft','title'=>'','body'=>'']);
        self::assertSame(200,$response->get_status(),json_encode($response->get_data()));
        $post=$response->get_data();$this->posts[]=$post['id'];return $post;
    }
    public static function sections(): array
    {
        return [['hub',0],['forum',0],['topic',0],['event',0],['gallery',0],['resource',0],['ally',2]];
    }
    #[DataProvider('sections')]
    public function testEmptyDraftsCanBeSavedReopenedAndEditedWithoutPublishing(string $type,int $actor): void
    {
        $post=$this->draft($type,$actor);
        self::assertSame('draft',$post['status']);self::assertNotSame('',$post['title']);
        self::assertSame('',$post['body']);self::assertTrue(Content::canRead(get_post($post['id'])));
        $updated=$this->request($type,['status'=>'draft','title'=>'Work in progress','body'=>''], $post['id']);
        self::assertSame(200,$updated->get_status());self::assertSame('draft',$updated->get_data()['status']);
        self::assertSame('Work in progress',get_post($post['id'])->post_title);
        self::assertContains($post['id'],array_column(Content::listing($type,['mine'=>true,'status'=>'draft'])['items'],'id'));
        wp_set_current_user($this->users[1]);
        self::assertFalse(Content::canRead(get_post($post['id'])));
        self::assertNotContains($post['id'],array_column(Content::listing($type,['status'=>'publish'])['items'],'id'));
    }
    #[DataProvider('sections')]
    public function testPublishingAnIncompleteDraftStillFails(string $type,int $actor): void
    {
        $post=$this->draft($type,$actor);
        foreach (['publish','pending'] as $status) {
            $response=$this->request($type,['status'=>$status,'title'=>'Incomplete','body'=>''],$post['id']);
            self::assertSame(400,$response->get_status());self::assertSame('draft',get_post_status($post['id']));
        }
    }
    public function testUndatedEventDraftsAppearInOwnPublicationsAndDrafts(): void
    {
        $post=$this->draft('event');
        self::assertEmpty($post['meta']['start']??null);
        foreach ([['mine'=>true],['mine'=>true,'status'=>'draft']] as $filter) {
            $list=Content::listing('event',$filter);
            self::assertSame(1,$list['total']);self::assertSame([$post['id']],array_column($list['items'],'id'));
        }
        self::assertNotContains($post['id'],array_column(Content::listing('event',['past'=>1,'status'=>'publish'])['items'],'id'));
        self::assertNotContains($post['id'],array_column(Content::listing('event',['past'=>0,'status'=>'publish'])['items'],'id'));
    }
    public function testForumDraftsAndTopicDraftsShareTheOwnDraftsView(): void
    {
        $forum=$this->draft('forum');$topic=$this->draft('topic');
        $foreign=$this->draft('forum',1);wp_set_current_user($this->users[0]);
        $list=Content::listing('topic',['mine'=>true,'status'=>'draft','include_forums'=>true]);
        self::assertEqualsCanonicalizing([$forum['id'],$topic['id']],array_column($list['items'],'id'));
        self::assertNotContains($foreign['id'],array_column($list['items'],'id'));
        self::assertSame([$topic['id']],array_column(Content::listing('topic',['mine'=>true,'status'=>'draft'])['items'],'id'));
    }
    public function testDraftsDoNotBypassPermissionsOrMetadataValidation(): void
    {
        wp_set_current_user($this->users[1]);
        foreach (['event','gallery','resource','ally'] as $type) {
            self::assertSame(403,$this->request($type,['status'=>'draft'])->get_status());
        }
        wp_set_current_user($this->users[0]);
        self::assertSame(400,$this->request('resource',['status'=>'draft','meta'=>['url'=>'javascript:alert(1)']])->get_status());
        $request=new WP_REST_Request('GET','/ascla/v1/content/topic');
        $request->set_query_params(['mine'=>1,'status'=>'draft','include_forums'=>['true']]);
        self::assertSame(400,rest_get_server()->dispatch($request)->get_status());
    }
}
