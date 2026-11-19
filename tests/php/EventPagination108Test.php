<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\Content;

final class EventPagination108Test extends TestCase
{
    private array $users=[],$posts=[];
    private string $query;
    protected function setUp(): void
    {
        $this->query='EventsPagination'.bin2hex(random_bytes(5));
        foreach(['administrator','ascla_executive','ascla_moderator','ascla_member'] as $role){
            $name='eventpages_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);add_filter('pre_wp_mail','__return_true');
    }
    protected function tearDown(): void
    {
        foreach($this->posts as $id)wp_delete_post($id,true);
        foreach($this->users as $id)wp_delete_user($id);
        remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    private function event(string $name,string $status='publish',array $extra=[],?int $author=null,bool $past=false): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>$status,'post_title'=>$this->query.' '.$name,'post_content'=>'Body','post_author'=>$author??$this->users[0]]);
        $this->posts[]=$id;$start=time()+($past?-2*DAY_IN_SECONDS:DAY_IN_SECONDS);
        update_post_meta($id,'_ascla',['start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600)]+$extra);return $id;
    }
    private function page(array $filter=[]): array
    {
        return Content::listing('event',$filter+['q'=>$this->query,'past'=>0]);
    }
    public static function readers(): array{return [['ascla_moderator',2],['ascla_member',3]];}
    #[DataProvider('readers')]
    public function testOnlyReadableEventsCountAndEveryPageIsFilled(string $role,int $index): void
    {
        unset($role);$visible=[];
        for($i=0;$i<20;$i++)$this->event('Foreign pending '.$i,'pending');
        for($i=0;$i<23;$i++)$visible[]=$this->event('Published '.$i);
        wp_set_current_user($this->users[$index]);$first=$this->page();$second=$this->page(['page'=>2]);
        self::assertSame(23,$first['total']);self::assertSame(2,$first['pages']);self::assertSame(18,$first['per_page']);self::assertCount(18,$first['items']);self::assertCount(5,$second['items']);
        $ids=array_merge(array_column($first['items'],'id'),array_column($second['items'],'id'));self::assertSame($visible,$ids);self::assertCount(23,array_unique($ids));
        $last=$this->page(['page'=>99]);self::assertSame(2,$last['page']);self::assertSame(array_column($second['items'],'id'),array_column($last['items'],'id'));
    }
    public function testMicroeventVisibilityAndOwnDraftsAreAppliedBeforeCounting(): void
    {
        for($i=0;$i<25;$i++)$this->event('Reserved '.$i,'publish',['micro'=>true,'invitees'=>[],'public_at'=>time()+DAY_IN_SECONDS]);
        $public=$this->event('Public');$invited=$this->event('Invited','publish',['micro'=>true,'invitees'=>[$this->users[3]],'public_at'=>time()+DAY_IN_SECONDS]);
        $own=$this->event('Own private','publish',['micro'=>true,'invitees'=>[],'public_at'=>time()+DAY_IN_SECONDS],$this->users[2]);
        $draft=$this->event('Own draft','draft',[],$this->users[2]);
        wp_set_current_user($this->users[2]);$list=$this->page();self::assertSame(3,$list['total']);self::assertCount(3,$list['items']);self::assertEqualsCanonicalizing([$public,$own,$draft],array_column($list['items'],'id'));
        wp_set_current_user($this->users[3]);$list=$this->page();self::assertSame(2,$list['total']);self::assertEqualsCanonicalizing([$public,$invited],array_column($list['items'],'id'));
        wp_set_current_user($this->users[0]);self::assertSame(28,$this->page()['total'],'Administration retains its management visibility; foreign drafts stay private.');
        wp_set_current_user($this->users[1]);self::assertSame(28,$this->page()['total'],'Executive shares administrative visibility.');
    }
    public function testEmptyAndPastViewsHaveConsistentPageMetadataAndFalseMeansUpcoming(): void
    {
        wp_set_current_user($this->users[3]);$empty=$this->page(['page'=>3]);self::assertSame(1,$empty['page']);self::assertSame(1,$empty['pages']);self::assertSame(0,$empty['total']);
        wp_set_current_user($this->users[0]);$past=$this->event('Past','publish',[],null,true);$future=$this->event('Future');
        wp_set_current_user($this->users[3]);self::assertSame([$future],array_column($this->page(['past'=>'false'])['items'],'id'));self::assertSame([$past],array_column($this->page(['past'=>'true'])['items'],'id'));
    }
    public function testRestBooleanFiltersNormalizeFalseAndRejectInvalidShapes(): void
    {
        $future=$this->event('Future');wp_set_current_user($this->users[3]);
        foreach (['false','0',''] as $value) {
            $r=new WP_REST_Request('GET','/ascla/v1/content/event');$r->set_query_params(['q'=>$this->query,'past'=>$value,'mine'=>$value]);$response=rest_get_server()->dispatch($r);
            self::assertSame(200,$response->get_status());self::assertSame([$future],array_column($response->get_data()['items'],'id'));
        }
        foreach (['past','mine','micro','recommended'] as $key) {
            $r=new WP_REST_Request('GET','/ascla/v1/content/event');$r->set_query_params([$key=>['false']]);self::assertSame(400,rest_get_server()->dispatch($r)->get_status());
        }
    }
}
