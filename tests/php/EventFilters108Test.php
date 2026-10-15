<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Content;
use ASCLA\Core\Database\EventFiltersMigration;
use ASCLA\Core\Repositories\RepositoryException;

final class EventFilters108Test extends TestCase
{
    private array $users=[],$posts=[];
    private string $prefix;
    private mixed $timezone,$index;
    protected function setUp(): void
    {
        $this->prefix='EventFilters'.bin2hex(random_bytes(5));$this->timezone=get_option('timezone_string');$this->index=get_option(EventFiltersMigration::OPTION,false);
        update_option('timezone_string','America/Lima');
        foreach (['administrator','ascla_member'] as $role) {
            $name='eventfilters_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);add_filter('pre_wp_mail','__return_true');
    }
    protected function tearDown(): void
    {
        foreach ($this->posts as $id)wp_delete_post($id,true);
        foreach ($this->users as $id)wp_delete_user($id);
        update_option('timezone_string',$this->timezone);
        if ($this->index===false)delete_option(EventFiltersMigration::OPTION);else update_option(EventFiltersMigration::OPTION,$this->index);
        remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    private function event(string $start,string $end,string $modality='Virtual',string $status='publish'): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>$status,'post_title'=>$this->prefix.' Title','post_content'=>'DescriptionMatch','post_author'=>$this->users[0],'post_date'=>'2020-01-01 12:00:00','post_date_gmt'=>'2020-01-01 17:00:00']);$this->posts[]=$id;
        update_post_meta($id,'_ascla',['start'=>gmdate('c',strtotime($start)),'end'=>gmdate('c',strtotime($end)),'modality'=>$modality]);return $id;
    }
    private function listing(array $filter): array
    {
        wp_set_current_user($this->users[1]);return Content::listing('event',$filter+['q'=>$this->prefix]);
    }
    public function testDatesFilterTheEventWindowRatherThanThePublicationDateAndUseLocalBoundaries(): void
    {
        $early=$this->event('2026-11-15T23:00:00-05:00','2026-11-16T23:59:59-05:00');
        $overlap=$this->event('2026-11-16T23:00:00-05:00','2026-11-17T00:00:00-05:00');
        $first=$this->event('2026-11-17T00:00:00-05:00','2026-11-17T01:00:00-05:00','Presencial');
        $last=$this->event('2026-11-19T23:59:59-05:00','2026-11-20T01:00:00-05:00','Híbrido');
        $late=$this->event('2026-11-20T00:00:00-05:00','2026-11-20T01:00:00-05:00');
        $hidden=$this->event('2026-11-18T12:00:00-05:00','2026-11-18T13:00:00-05:00','Virtual','pending');
        $list=$this->listing(['after'=>'2026-11-17','before'=>'2026-11-19']);self::assertSame(3,$list['total']);self::assertSame([$overlap,$first,$last],array_column($list['items'],'id'));
        self::assertEqualsCanonicalizing([$overlap,$first,$last,$late],array_column($this->listing(['after'=>'2026-11-17'])['items'],'id'));
        self::assertEqualsCanonicalizing([$early,$overlap,$first,$last],array_column($this->listing(['before'=>'2026-11-19'])['items'],'id'));
        self::assertNotContains($hidden,array_column($list['items'],'id'));
    }
    public function testModalityTextDatesAndPaginationAreCombinedBeforeTotals(): void
    {
        $ids=[];for($i=0;$i<20;$i++)$ids[]=$this->event('2026-11-18T12:00:00-05:00','2026-11-18T13:00:00-05:00','Presencial');
        $this->event('2026-11-18T12:00:00-05:00','2026-11-18T13:00:00-05:00');$this->event('2026-11-18T12:00:00-05:00','2026-11-18T13:00:00-05:00','Híbrido');
        $filters=['modality'=>'Presencial','after'=>'2026-11-18','before'=>'2026-11-18','q'=>'DescriptionMatch'];$a=$this->listing($filters);$b=$this->listing($filters+['page'=>2]);
        self::assertSame(20,$a['total']);self::assertSame(2,$a['pages']);self::assertCount(18,$a['items']);self::assertCount(2,$b['items']);self::assertSame($ids,array_merge(array_column($a['items'],'id'),array_column($b['items'],'id')));
    }
    public function testInvalidModalityDateAndReversedRangeReturn400(): void
    {
        wp_set_current_user($this->users[1]);
        foreach ([['modality'=>['Virtual']],['modality'=>'Remote'],['after'=>'2026-02-30'],['before'=>[]],['after'=>'2026-11-20','before'=>'2026-11-19']] as $filter) {
            $r=new WP_REST_Request('GET','/ascla/v1/content/event');$r->set_query_params($filter);self::assertSame(400,rest_get_server()->dispatch($r)->get_status());
        }
    }
    public function testLegacyIndexesAreBackfilledAndMigrationIsIdempotent(): void
    {
        $ids=[];for($i=0;$i<201;$i++){$id=$this->event('2026-11-18T12:00:00-05:00','2026-11-18T13:00:00-05:00','Híbrido');delete_post_meta($id,'_ascla_modality');$ids[]=$id;}
        $meta=get_post_meta($ids[0],'_ascla',true);delete_option(EventFiltersMigration::OPTION);EventFiltersMigration::run();self::assertTrue(EventFiltersMigration::ready());
        foreach($ids as $id)self::assertSame('Híbrido',get_post_meta($id,'_ascla_modality',true));
        self::assertSame($meta,get_post_meta($ids[0],'_ascla',true));self::assertSame('publish',get_post_status($ids[0]));
        EventFiltersMigration::run();self::assertCount(1,get_post_meta($ids[0],'_ascla_modality',false));
    }
    public function testFailedIndexWriteDoesNotMarkMigrationCompleteAndCanBeRetried(): void
    {
        $id=$this->event('2026-11-18T12:00:00-05:00','2026-11-18T13:00:00-05:00','Presencial');delete_post_meta($id,'_ascla_modality');delete_option(EventFiltersMigration::OPTION);
        $filter=static fn($sql)=>str_starts_with($sql,'INSERT')&&str_contains($sql,"'_ascla_modality'")?'INSERT INTO ascla_missing_table VALUES (1)':$sql;
        global $wpdb;$suppressed=$wpdb->suppress_errors();add_filter('query',$filter);
        try {EventFiltersMigration::run();self::fail('Failed index writes must reject migration.');}catch(RepositoryException){self::assertFalse(EventFiltersMigration::ready());self::assertSame('',get_post_meta($id,'_ascla_modality',true));}finally{remove_filter('query',$filter);$wpdb->suppress_errors($suppressed);}
        EventFiltersMigration::run();self::assertTrue(EventFiltersMigration::ready());self::assertSame('Presencial',get_post_meta($id,'_ascla_modality',true));
    }
}
