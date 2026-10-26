<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\CommentHistory;

final class CommentHistoryTest extends TestCase
{
    private array $users=[],$posts=[];
    protected function setUp(): void
    {
        foreach (['ascla_member','ascla_member','administrator'] as $role) { $this->users[]=wp_insert_user(['user_login'=>'history_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>$role]); }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->users as $id) { wp_delete_user($id); }
        wp_set_current_user(0);
    }
    private function post(string $type='topic',string $status='publish',int $parent=0): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_status'=>$status,'post_title'=>'Debate público','post_author'=>$this->users[1],'post_parent'=>$parent]);
        $this->posts[]=$id;return $id;
    }
    private function comment(int $post,int $user=0,string $status='1',string $body='Aporte propio',string $date='2026-10-10 12:00:00'): int
    {
        return wp_insert_comment(['comment_post_ID'=>$post,'user_id'=>$user?:$this->users[0],'comment_content'=>$body,'comment_approved'=>$status,'comment_type'=>'comment','comment_date'=>$date,'comment_date_gmt'=>$date]);
    }
    public function testOwnPublishedAndPendingCommentsAreSeparatedFromOtherAccountsAndSections(): void
    {
        $topic=$this->post();$own=$this->comment($topic);$pending=$this->comment($topic,0,'0');$this->comment($topic,$this->users[1]);
        $this->comment($topic,0,'trash');$this->comment($topic,0,'spam');$this->comment($this->post('hub'));$this->comment($this->post('resource'));
        $history=CommentHistory::listing(['user_id'=>$this->users[1]]);
        self::assertSame(2,$history['total']);self::assertSame([$pending,$own],array_column($history['items'],'id'));
        self::assertSame(['pending','publish'],array_column($history['items'],'status'));self::assertSame($topic,$history['items'][0]['post_id']);
        wp_set_current_user($this->users[2]);self::assertSame(0,CommentHistory::listing(['user_id'=>$this->users[0]])['total'],'Even an administrator sees their own history.');
    }
    public function testContextAndParentVisibilityAreCheckedBeforeTotalsAndPagination(): void
    {
        $visible=$this->post();$own=$this->comment($visible);
        foreach (['draft','ascla_hidden','pending','trash'] as $status) { $this->comment($this->post('topic',$status)); }
        $hiddenForum=$this->post('forum','ascla_hidden');$this->comment($this->post('topic','publish',$hiddenForum));
        $missing=wp_insert_comment(['comment_post_ID'=>999999999,'user_id'=>$this->users[0],'comment_content'=>'Private orphan','comment_approved'=>'1']);
        try { $history=CommentHistory::listing();self::assertSame(1,$history['total']);self::assertSame([$own],array_column($history['items'],'id')); }
        finally { wp_delete_comment($missing,true); }
    }
    public function testPaginationScansBeyondTheFirstTwoHundredCommentsWithoutLosingTotals(): void
    {
        $topic=$this->post();$ids=[];foreach (range(1,205) as $number) { $ids[]=$this->comment($topic); }
        $first=CommentHistory::listing();$last=CommentHistory::listing(['page'=>11]);
        self::assertSame(205,$first['total']);self::assertSame(11,$first['pages']);self::assertCount(20,$first['items']);self::assertCount(5,$last['items']);
        self::assertSame(array_reverse(array_slice($ids,0,5)),array_column($last['items'],'id'));
    }
    public function testOwnCommentTextTitleAndInclusiveLocalDatesFilterTheHistory(): void
    {
        $topic=$this->post();$early=$this->comment($topic,0,'1','Primero','2026-10-10 00:00:00');$late=$this->comment($topic,0,'1','Trazabilidad','2026-10-10 23:59:59');$this->comment($topic,0,'1','Fuera','2026-10-11 00:00:00');
        $history=CommentHistory::listing(['after'=>'2026-10-10','before'=>'2026-10-10']);self::assertSame([$late,$early],array_column($history['items'],'id'));
        self::assertSame(1,CommentHistory::listing(['q'=>'trazabilidad'])['total']);self::assertSame(3,CommentHistory::listing(['q'=>'PÚBLICO'])['total']);
        $this->expectException(ASCLA\Core\Rest\ApiException::class);CommentHistory::listing(['after'=>'2026-10-11','before'=>'2026-10-10']);
    }
    public function testHistoryRequiresActiveAuthenticatedMembership(): void
    {
        wp_set_current_user(0);$response=rest_do_request(new WP_REST_Request('GET','/ascla/v1/comments/mine'));self::assertSame(401,$response->get_status());
        wp_set_current_user($this->users[0]);update_user_meta($this->users[0],'_ascla_suspended',true);
        $response=rest_do_request(new WP_REST_Request('GET','/ascla/v1/comments/mine'));self::assertSame(403,$response->get_status());
    }
}
