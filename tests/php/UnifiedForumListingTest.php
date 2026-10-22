<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Content;

final class UnifiedForumListingTest extends TestCase
{
    private array $users=[],$posts=[];
    private string $prefix;
    protected function setUp(): void
    {
        $this->prefix='UnifiedForum'.bin2hex(random_bytes(5));
        foreach(['ascla_executive','ascla_member'] as $role) {
            $name='forumlist_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);
        }
    }
    protected function tearDown(): void
    {
        foreach($this->posts as $id) { wp_delete_post($id,true); }
        foreach($this->users as $id) { wp_delete_user($id); }
        wp_set_current_user(0);
    }
    private function post(string $type,string $status='publish',int $actor=0,int $parent=0,int $day=20): int
    {
        $date='2026-10-'.sprintf('%02d',$day).' 12:00:00';
        $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_status'=>$status,'post_title'=>$this->prefix.' '.$type,'post_content'=>'Contenido de prueba','post_author'=>$this->users[$actor],'post_parent'=>$parent,'post_date'=>$date,'post_date_gmt'=>$date]);
        $this->posts[]=$id;return $id;
    }
    private function listing(array $filter=[]): array { return Content::listing('topic',$filter+['include_forums'=>true,'q'=>$this->prefix,'status'=>'publish']); }
    public function testPublishedForumsAndTopicsShareTotalsAndPagesWithoutDuplicateEntries(): void
    {
        $expected=[$this->post('forum'),$this->post('topic'),$this->post('forum'),$this->post('topic')];
        $hidden=$this->post('forum','draft');$this->post('topic','draft');wp_set_current_user($this->users[1]);
        $first=$this->listing(['per_page'=>2]);$second=$this->listing(['per_page'=>2,'page'=>2]);
        self::assertSame(4,$first['total']);self::assertSame(2,$first['pages']);self::assertCount(2,$first['items']);self::assertCount(2,$second['items']);
        $ids=array_merge(array_column($first['items'],'id'),array_column($second['items'],'id'));sort($ids);sort($expected);self::assertSame($expected,$ids);self::assertNotContains($hidden,$ids);
        self::assertSame(2,Content::listing('topic',['q'=>$this->prefix,'status'=>'publish'])['total'],'Existing topic-only clients remain compatible.');
    }
    public function testDateAndParentFiltersApplyToTheSameCombinedQuery(): void
    {
        $old=$this->post('forum','publish',0,0,10);$forum=$this->post('forum');$topic=$this->post('topic','publish',0,$forum);
        wp_set_current_user($this->users[1]);$list=$this->listing(['after'=>'2026-10-15','before'=>'2026-10-22']);
        self::assertSame(2,$list['total']);self::assertNotContains($old,array_column($list['items'],'id'));
        self::assertSame([$topic],array_column($this->listing(['parent'=>$forum])['items'],'id'));
    }
    public function testOwnViewCombinesOwnForumsAndTopicsAndKeepsForeignDraftsPrivate(): void
    {
        $ownForum=$this->post('forum','publish',1);$ownTopic=$this->post('topic','publish',1);$draft=$this->post('forum','draft',1);
        $this->post('forum','draft',0);$this->post('topic','publish',0);wp_set_current_user($this->users[1]);
        $mine=$this->listing(['mine'=>true,'status'=>'']);self::assertSame(3,$mine['total']);
        self::assertEqualsCanonicalizing([$ownForum,$ownTopic,$draft],array_column($mine['items'],'id'));
        self::assertSame([$draft],array_column($this->listing(['mine'=>true,'status'=>'draft'])['items'],'id'));
        $request=new WP_REST_Request('GET','/ascla/v1/content/topic');$request->set_query_params(['include_forums'=>1,'q'=>$this->prefix,'status'=>'publish']);
        $response=rest_do_request($request);self::assertSame(200,$response->get_status());self::assertSame(3,$response->get_data()['total']);
    }
}
