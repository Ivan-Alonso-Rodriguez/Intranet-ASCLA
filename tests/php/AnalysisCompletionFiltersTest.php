<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{Content,Profiles,ProfileRequirements,ProfileReview,Settings};
use ASCLA\Core\Repositories\Store;

final class AnalysisCompletionFiltersTest extends TestCase
{
    private array $users=[],$posts=[],$settings;
    private string $prefix;

    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->prefix='Rango'.bin2hex(random_bytes(4));
        update_option('ascla_settings',array_merge($this->settings,ProfileRequirements::DEFAULTS),false);
        foreach (['administrator','ascla_member'] as $role) {
            $login='range_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[1]);Profiles::save(ascla_test_profile());
    }

    protected function tearDown(): void
    {
        update_option('ascla_settings',$this->settings,false);
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->users as $id) {
            foreach (['audit','jobs','notifications'] as $table) { Store::delete($table,[$table==='audit'?'actor_id':'user_id'=>$id]); }
            wp_delete_user($id);
        }
        wp_set_current_user(0);
    }

    private function api(string $method,string $route,array $data=[]): WP_REST_Response
    {
        $request=new WP_REST_Request($method,'/ascla/v1/'.$route);
        if ($method==='GET') { $request->set_query_params($data); }
        else { $request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($data)); }
        return rest_do_request($request);
    }

    private function post(string $type,string $date,int $category,string $status='publish'): int
    {
        wp_set_current_user($this->users[0]);
        $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_title'=>$this->prefix.' '.$date,'post_content'=>'Contenido público','post_author'=>$this->users[0],'post_status'=>$status,'post_date'=>$date,'post_date_gmt'=>get_gmt_from_date($date)]);
        $this->posts[]=$id;update_post_meta($id,'_ascla',['chatham'=>false]);
        wp_set_post_terms($id,[$category],'ascla_category');
        wp_set_current_user($this->users[1]);
        return $id;
    }

    public static function sections(): array { return [['hub'],['topic'],['gallery'],['resource']]; }

    #[DataProvider('sections')]
    public function testPublicationRangeIncludesWholeDaysAndCombinesWithCategoriesAndPagination(string $type): void
    {
        $categories=array_map('intval',array_column(Profiles::catalogs()['category'],'id'));
        self::assertGreaterThanOrEqual(2,count($categories));[$a,$b]=$categories;
        $early=$this->post($type,'2020-03-16 23:59:59',$a);
        $start=$this->post($type,'2020-03-17 00:00:00',$a);
        $middle=$this->post($type,'2020-03-18 12:00:00',$a);
        $end=$this->post($type,'2020-03-19 23:59:59',$a);
        $late=$this->post($type,'2020-03-20 00:00:00',$a);
        $other=$this->post($type,'2020-03-18 14:00:00',$b);
        $draft=$this->post($type,'2020-03-18 15:00:00',$a,'draft');
        $filter=['q'=>$this->prefix,'after'=>'2020-03-17','before'=>'2020-03-19','category'=>$a,'per_page'=>2];
        $one=$this->api('GET','content/'.$type,$filter);self::assertSame(200,$one->get_status());$one=$one->get_data();
        $two=Content::listing($type,$filter+['page'=>2]);
        self::assertSame(3,$one['total']);self::assertSame(2,$one['pages']);
        self::assertCount(2,$one['items']);self::assertCount(1,$two['items']);
        $ids=array_merge(array_column($one['items'],'id'),array_column($two['items'],'id'));
        self::assertEqualsCanonicalizing([$start,$middle,$end],$ids);
        foreach ([$early,$late,$other,$draft] as $excluded) { self::assertNotContains($excluded,$ids); }
        $sameDay=Content::listing($type,['q'=>$this->prefix,'after'=>'2020-03-19','before'=>'2020-03-19']);
        self::assertSame([$end],array_column($sameDay['items'],'id'));
        self::assertSame(1,Content::listing($type,['q'=>$this->prefix,'before'=>'2020-03-16'])['total']);
        self::assertSame(1,Content::listing($type,['q'=>$this->prefix,'after'=>'2020-03-20'])['total']);
        self::assertSame(6,Content::listing($type,['q'=>$this->prefix])['total']);
    }

    public function testInvalidDatesAndReversedRangesAreRejectedByTheApi(): void
    {
        foreach ([['after'=>'2020-02-30'],['before'=>'2020-13-01'],['before'=>'0000-01-01'],['after'=>['2020-01-01']],['after'=>'tomorrow'],['after'=>'2020-03-20','before'=>'2020-03-17']] as $filter) {
            self::assertSame(400,$this->api('GET','content/hub',$filter)->get_status(),wp_json_encode($filter));
        }
        self::assertSame(200,$this->api('GET','content/hub',['after'=>'2020-02-29'])->get_status());
        self::assertSame(200,$this->api('GET','content/hub',['after'=>'','before'=>''])->get_status());
    }

    public function testAdminCanConfigureAdditionalCriteriaWithoutWeakeningMinimumSaveRequirements(): void
    {
        $profile=Profiles::raw($this->users[1]);self::assertSame(100,ProfileRequirements::completion($profile)['percent']);
        self::assertSame(403,$this->api('POST','settings',['profile_completion_extra'=>['experience']])->get_status());
        wp_set_current_user($this->users[0]);
        $response=$this->api('POST','settings',['profile_completion_extra'=>['experience','languages','experience']]);
        self::assertSame(200,$response->get_status());self::assertSame(['experience','languages'],Settings::get()['profile_completion_extra']);
        self::assertCount(8,$response->get_data()['profile_completion_options']['required']);
        wp_set_current_user($this->users[1]);
        $result=Profiles::completion($this->users[1]);self::assertSame(10,$result['total']);self::assertSame(80,$result['percent']);
        self::assertSame(['Experiencia profesional','Idiomas'],$result['missing']);self::assertSame([],ProfileRequirements::missing($profile));
        self::assertTrue(ProfileReview::status($this->users[1])['incomplete']);
        self::assertFalse(ProfileReview::respond('snooze')['due']);
        self::assertSame(400,$this->api('POST','profiles/review',['decision'=>'confirm'])->get_status());
        Profiles::save(['bio'=>'Descripción actualizada']); // Additional calculation criteria do not block a minimum-complete save.
        self::assertSame(80,Profiles::completion($this->users[1])['percent']);
        $profile=Profiles::raw($this->users[1]);$profile['experience']="\u{200B} ";
        self::assertFalse(ProfileRequirements::completion($profile)['checks']['experience']);
        $language=(int)Profiles::catalogs()['language'][0]['id'];
        Profiles::save(['experience'=>'Trayectoria profesional','languages'=>[$language],'hidden'=>['experience','languages']]);
        self::assertSame(100,Profiles::completion($this->users[1])['percent']);
        self::assertFalse(ProfileReview::status($this->users[1])['incomplete']);
        self::assertSame([],ProfileRequirements::missing(Profiles::raw($this->users[1])));
        foreach (['bio','position','company'] as $field) {
            self::assertSame(400,$this->api('POST','profiles/me',[$field=>''])->get_status());
        }
    }

    public function testOnlyAllowedCriteriaAreStoredAndClearingRestoresOptionalFieldBehavior(): void
    {
        wp_set_current_user($this->users[0]);Settings::save(['profile_completion_extra'=>['website']]);
        foreach (['experience',['birth_date'],['unknown'],['name'],['key'=>'website'],[42]] as $invalid) {
            self::assertSame(400,$this->api('POST','settings',['profile_completion_extra'=>$invalid])->get_status());
            self::assertSame(['website'],Settings::get()['profile_completion_extra']);
        }
        Settings::save(['directory_page_size'=>12]);self::assertSame(['website'],Settings::get()['profile_completion_extra']);
        Settings::save(['profile_completion_extra'=>[]]);
        $profile=Profiles::raw($this->users[1]);$profile['website']='';$profile['experience']='';$profile['languages']=[];
        $result=ProfileRequirements::completion($profile);self::assertSame(8,$result['total']);self::assertSame(100,$result['percent']);
        $profile['bio']='';self::assertLessThan(100,ProfileRequirements::completion($profile)['percent']);
    }
}
