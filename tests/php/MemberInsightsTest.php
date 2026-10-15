<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\MemberInsights;
use ASCLA\Core\Repositories\Store;

final class MemberInsightsTest extends TestCase
{
    private array $users=[],$posts=[],$terms=[];
    private int $admin,$member;
    protected function setUp(): void
    {
        foreach(['administrator','ascla_member','ascla_executive','ascla_moderator'] as $role) {
            $login='insights_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        [$this->admin,$this->member]=$this->users;wp_set_current_user($this->admin);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach($this->posts as $id) { wp_delete_post($id,true); }
        foreach($this->terms as $id) { wp_delete_term($id,'ascla_interest'); }
        foreach($this->users as $id) {
            foreach(['relations','registrations','attendance','user_interests','notifications'] as $table) { Store::delete($table,['user_id'=>$id]); }
            Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function post(array $extra=[],array $meta=[]): int
    {
        $date=gmdate('Y-m-d H:i:s',time()-60);
        $id=wp_insert_post($extra+['post_type'=>'ascla_hub','post_status'=>'publish','post_title'=>'Contenido visible','post_content'=>'No mostrar este cuerpo en la ficha','post_author'=>$this->member,'post_date_gmt'=>$date,'post_date'=>get_date_from_gmt($date)]);
        $this->posts[]=$id;update_post_meta($id,'_ascla',$meta+['chatham'=>false]);return $id;
    }
    private function like(int $id,string $date='',string $kind='like'): void
    {
        Store::insert('relations',['user_id'=>$this->member,'target_id'=>$id,'kind'=>$kind,'created_at'=>$date?:gmdate('Y-m-d H:i:s')]);
    }
    private function api(array $filter=[],int $id=0): WP_REST_Response
    {
        $request=new WP_REST_Request('GET','/ascla/v1/admin/users/'.($id?:$this->member).'/insights');
        $request->set_query_params($filter);return rest_do_request($request);
    }
    public function testOnlyActiveAdministratorsCanReadInsightsIncludingTheirOwnAccount(): void
    {
        foreach(array_slice($this->users,1) as $id) { wp_set_current_user($id);self::assertSame(403,$this->api()->get_status()); }
        wp_set_current_user(0);self::assertSame(401,$this->api()->get_status());
        wp_set_current_user($this->admin);self::assertSame(200,$this->api()->get_status());self::assertSame(200,$this->api([], $this->admin)->get_status());
        self::assertSame(404,$this->api([],PHP_INT_MAX)->get_status());
        update_user_meta($this->admin,'_ascla_suspended',true);self::assertSame(403,$this->api()->get_status());
    }
    public function testDeclaredInterestsAndObservedLikesHaveIndependentEvidence(): void
    {
        $term=wp_insert_term('Tema '.bin2hex(random_bytes(4)),'ascla_interest');$topic=(int)$term['term_id'];$this->terms[]=$topic;
        update_user_meta($this->member,'_ascla_profile',['first_name'=>'Persona','last_name'=>'Reservada','hidden'=>['first_name'],'interests'=>[$topic],'bio'=>'PRIVATE_PROFILE_BODY']);
        $post=$this->post();wp_set_object_terms($post,[$topic],'ascla_interest');$this->like($post);
        $comment=wp_insert_comment(['comment_post_ID'=>$post,'user_id'=>$this->member,'comment_content'=>'PRIVATE_COMMENT_BODY','comment_approved'=>1]);$this->like($comment,'','comment_like');
        $result=MemberInsights::get($this->member);
        self::assertSame(1,$result['summary']['publications']);self::assertSame(1,$result['summary']['comments']);self::assertSame(2,$result['summary']['likes']);
        self::assertSame(2,$result['liked_topics'][0]['likes']);self::assertSame([$topic],array_column($result['liked_topics'],'id'));
        self::assertSame([$termName=get_term($topic)->name],$result['interests']['interests']);self::assertNotEmpty($termName);
        self::assertSame('Asociado ASCLA '.$this->member,$result['user']['name']);
        self::assertStringNotContainsString('PRIVATE_',wp_json_encode($result));self::assertStringNotContainsString('No mostrar este cuerpo',wp_json_encode($result));
        Store::delete('relations',['user_id'=>$this->member]);$without=MemberInsights::get($this->member);
        self::assertSame(0,$without['summary']['likes']);self::assertSame([],$without['liked_topics']);self::assertSame($result['interests'],$without['interests']);
    }
    public function testPrivateUnpublishedAndChathamAssociationsAreAbsentEvenFromCounts(): void
    {
        $visible=$this->post();$this->like($visible);
        $protected=$this->post(['post_title'=>'PRIVATE_CHATHAM'],['chatham'=>true]);
        $ids=[$protected,$this->post(['post_title'=>'PRIVATE_DERIVED','post_parent'=>$protected]),$this->post(['post_title'=>'PRIVATE_MICRO'],['micro'=>true]),$this->post(['post_status'=>'draft','post_title'=>'PRIVATE_DRAFT']),$this->post(['post_status'=>'trash','post_title'=>'PRIVATE_TRASH']),$this->post(['post_type'=>'ascla_contact','post_title'=>'PRIVATE_CONTACT'])];
        foreach($ids as $id) { $this->like($id); }
        $pending=wp_insert_comment(['comment_post_ID'=>$visible,'user_id'=>$this->member,'comment_content'=>'PRIVATE_PENDING','comment_approved'=>0]);$this->like($pending,'','comment_like');
        $data=MemberInsights::get($this->member);
        self::assertSame(1,$data['summary']['publications']);self::assertSame(1,$data['summary']['likes']);self::assertSame(0,$data['summary']['comments']);self::assertSame(2,$data['total']);
        self::assertStringNotContainsString('PRIVATE_',wp_json_encode($data));
    }
    public function testPaginationCrossesRepositoryBatchesWithoutTruncationOrDuplicates(): void
    {
        for($i=0;$i<207;$i++) { $this->post(['post_title'=>'Publicación '.$i]); }
        // Invisible rows mixed into the source do not consume a page or inflate totals.
        $this->post(['post_title'=>'No atribuible'],['chatham'=>true]);
        $seen=[];
        foreach([1,2,11] as $page) {
            $data=MemberInsights::get($this->member,['activity'=>'publications','page'=>$page]);
            self::assertSame(207,$data['total']);self::assertSame(207,$data['summary']['publications']);self::assertSame(11,$data['pages']);
            self::assertCount($page===11?7:20,$data['items']);$seen=array_merge($seen,array_column(array_column($data['items'],'target'),'id'));
        }
        self::assertCount(47,array_unique($seen));
    }
    public function testTimeRangeAndActivityFilterKeepSummaryScopedAndDoNotTreatRegistrationAsAttendance(): void
    {
        $event=$this->post(['post_type'=>'ascla_event']);$past=$this->post(['post_author'=>$this->admin]);$this->like($past,gmdate('Y-m-d H:i:s',time()-60*DAY_IN_SECONDS));
        Store::insert('registrations',['event_id'=>$event,'user_id'=>$this->member,'status'=>'accepted','created_at'=>gmdate('Y-m-d H:i:s')]);
        $data=MemberInsights::get($this->member,['days'=>30,'activity'=>'registrations']);
        self::assertSame(0,$data['summary']['likes']);self::assertSame(1,$data['summary']['registrations']);self::assertSame(0,$data['summary']['attendances']);self::assertSame(1,$data['total']);
        Store::insert('attendance',['event_id'=>$event,'user_id'=>$this->member,'status'=>'present','source'=>'manual','recorded_by'=>$this->admin,'updated_at'=>gmdate('Y-m-d H:i:s')]);
        $all=MemberInsights::get($this->member,['days'=>0]);self::assertSame(1,$all['summary']['likes']);self::assertSame(1,$all['summary']['attendances']);
    }
    public function testEmptyAndSuspendedMembersCanBeReviewedWithoutPretendingTheyHaveActivity(): void
    {
        update_user_meta($this->member,'_ascla_suspended',true);
        $data=MemberInsights::get($this->member);self::assertSame('suspended',$data['user']['membership_status']);self::assertSame(0,$data['total']);self::assertSame([],$data['items']);self::assertNull($data['last_activity']);
    }
    public function testMalformedFiltersAreRejectedBeforeQueries(): void
    {
        foreach([['days'=>['30']],['days'=>'30 OR 1=1'],['activity'=>'messages'],['activity'=>[]],['page'=>0],['page'=>'1.5'],['page'=>10001]] as $filter) { self::assertSame(400,$this->api($filter)->get_status(),wp_json_encode($filter)); }
    }
    public function testDatabaseFailureIsAnErrorAndNeverAnEmptySuccessfulSummary(): void
    {
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        $break=static fn(string $query): string=>str_starts_with($query,'SELECT a.* FROM (')?'SELECT missing_column FROM missing_admin_insights_table':$query;
        add_filter('query',$break);
        try { self::assertSame(500,$this->api()->get_status()); }
        finally { remove_filter('query',$break);$wpdb->suppress_errors($previous); }
    }
}
