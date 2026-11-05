<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Profiles,ProfileReview,Settings};
use ASCLA\Core\Repositories\Store;
final class ProfileDirectoryReviewTest extends TestCase
{
    private array $users=[],$settings;
    private string $prefix;
    protected function setUp(): void
    {
        $this->settings=Settings::get();$this->prefix='Revision'.bin2hex(random_bytes(4));
        Settings::save(ProfileReview::DEFAULTS);
        foreach (range(0,12) as $n) {
            $id=wp_insert_user(['user_login'=>'review_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);$this->users[]=$id;
            wp_set_current_user($id);Profiles::save(ascla_test_profile(['first_name'=>$this->prefix,'last_name'=>'Persona '.$n,'networking'=>false]));
        }
        wp_set_current_user($this->users[0]);
    }
    protected function tearDown(): void
    {
        update_option('ascla_settings',$this->settings,false);
        foreach($this->users as $id){Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);}wp_set_current_user(0);
    }
    public function testReviewPersistsSnoozeAndConfirmationAndRejectsIncompleteConfirmation(): void
    {
        $id=$this->users[0];Settings::save(['profile_review_days'=>30,'profile_snooze_days'=>3]);
        update_user_meta($id,'_ascla_profile_review',['confirmed_at'=>time()-31*DAY_IN_SECONDS]);
        self::assertTrue(ProfileReview::status($id)['due']);
        $result=ProfileReview::respond('snooze');self::assertFalse($result['due']);self::assertEqualsWithDelta(time()+3*DAY_IN_SECONDS,$result['next_at'],2);
        wp_set_current_user(0);wp_set_current_user($id);self::assertFalse(ProfileReview::status($id)['due']);
        $result=ProfileReview::respond('confirm');self::assertFalse($result['due']);self::assertEqualsWithDelta(time()+30*DAY_IN_SECONDS,$result['next_at'],2);
        $profile=Profiles::raw($id);$profile['bio']='';update_user_meta($id,'_ascla_profile',$profile);
        self::assertTrue(ProfileReview::status($id)['due']);
        try{ProfileReview::respond('confirm');self::fail('Incomplete profile confirmed');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(400,$e->getCode());}
        self::assertFalse(ProfileReview::respond('snooze')['due']);
        Profiles::save(['bio'=>'Información profesional vigente']);self::assertFalse(ProfileReview::status($id)['incomplete']);
    }
    public function testExactNamePrecedesSubstringAndPaginationUsesTwelveProfilesDespiteLegacySetting(): void
    {
        $exact=$this->users[3];wp_set_current_user($exact);Profiles::save(['first_name'=>$this->prefix,'last_name'=>'Exacto']);
        wp_set_current_user($this->users[1]);Profiles::save(['first_name'=>'A '.$this->prefix,'last_name'=>'Exacto']);
        wp_set_current_user($this->users[0]);
        $results=Profiles::directory(['q'=>mb_strtoupper($this->prefix.' Exacto')]);
        self::assertSame($exact,$results['items'][0]['id']);self::assertCount(2,$results['items']);
        update_option('ascla_settings',array_merge(Settings::get(),['directory_page_size'=>15]),false);
        self::assertSame(12,Settings::get()['directory_page_size']);
        $one=Profiles::directory(['q'=>$this->prefix]);$two=Profiles::directory(['q'=>$this->prefix,'page'=>2]);
        self::assertSame(13,$one['total']);self::assertSame(12,$one['per_page']);self::assertSame(2,$one['pages']);self::assertSame(2,$two['page']);self::assertCount(12,$one['items']);self::assertCount(1,$two['items']);
        self::assertSame([],array_intersect(array_column($one['items'],'id'),array_column($two['items'],'id')));
        self::assertArrayNotHasKey('_rank',$one['items'][0]);
        $last=Profiles::directory(['q'=>$this->prefix,'page'=>99]);self::assertSame(2,$last['page']);self::assertSame($two['items'],$last['items']);
        $empty=Profiles::directory(['q'=>'NoMatch'.$this->prefix,'page'=>2]);self::assertSame(0,$empty['total']);self::assertSame(1,$empty['page']);self::assertSame(1,$empty['pages']);self::assertSame([],$empty['items']);
        self::assertSame(12,Settings::save(['directory_page_size'=>12])['directory_page_size']);
    }
    public function testSettingsCannotChangeTheTwelveProfilePageSize(): void
    {
        foreach ([5,13,100] as $size) {
            try { Settings::save(['directory_page_size'=>$size]);self::fail('Directory page size changed'); }
            catch (ASCLA\Core\Rest\ApiException $e) { self::assertSame(400,$e->getCode()); }
            self::assertSame(12,Settings::get()['directory_page_size']);
        }
        \ASCLA\Core\Rest\Router::routes();$request=new WP_REST_Request('GET','/ascla/v1/profiles');$request->set_param('q',$this->prefix);$request->set_param('page',2);$request->set_param('per_page',100);
        $response=rest_get_server()->dispatch($request);self::assertSame(200,$response->get_status());$body=$response->get_data();
        self::assertSame(12,$body['per_page']);self::assertSame(13,$body['total']);self::assertSame(2,$body['page']);self::assertCount(1,$body['items']);
    }
    public function testBrowserBootstrapAndReviewResponsesOmitInternalDatesWithoutChangingTheirSchedule(): void
    {
        $id=$this->users[0];$before=ProfileReview::status($id);
        $bootstrap=\ASCLA\Core\Services\Bootstrap::get();
        self::assertSame($before['due'],$bootstrap['profile_review']['due']);
        self::assertSame($before['incomplete'],$bootstrap['profile_review']['incomplete']);
        self::assertArrayNotHasKey('confirmed_at',$bootstrap['profile_review']);
        self::assertArrayNotHasKey('next_at',$bootstrap['profile_review']);
        \ASCLA\Core\Rest\Router::routes();$request=new WP_REST_Request('POST','/ascla/v1/profiles/review');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode(['decision'=>'snooze']));
        $response=rest_get_server()->dispatch($request);self::assertSame(200,$response->get_status());$body=$response->get_data();
        self::assertFalse($body['due']);self::assertSame(7,$body['snooze_days']);
        self::assertArrayNotHasKey('confirmed_at',$body);self::assertArrayNotHasKey('next_at',$body);
        $internal=ProfileReview::status($id);self::assertSame($before['confirmed_at'],$internal['confirmed_at']);self::assertGreaterThan(time(),$internal['next_at']);
    }
    public function testMultipleFiltersMatchAnyWithinEachGroupAndRankByOverlap(): void
    {
        $cat=Profiles::catalogs();$industries=array_map('intval',array_column(array_slice($cat['industry'],0,2),'id'));$areas=array_map('intval',array_column(array_slice($cat['area'],0,2),'id'));
        wp_set_current_user($this->users[1]);Profiles::save(['industries'=>$industries,'areas'=>$areas]);
        wp_set_current_user($this->users[2]);Profiles::save(['industries'=>[$industries[0]],'areas'=>[$areas[0]]]);
        wp_set_current_user($this->users[0]);
        $result=Profiles::directory(['q'=>$this->prefix,'industries'=>$industries,'areas'=>$areas]);self::assertSame(2,$result['total']);self::assertSame($this->users[1],$result['items'][0]['id']);
        $profile=Profiles::raw($this->users[1]);$profile['hidden']=['areas','position'];$profile['position']='Secreto'.$this->prefix;update_user_meta($this->users[1],'_ascla_profile',$profile);
        get_userdata($this->users[0])->set_role('administrator');wp_set_current_user(0);wp_set_current_user($this->users[0]);
        self::assertSame(0,Profiles::directory(['q'=>'Secreto'.$this->prefix])['total']);
        self::assertSame(1,Profiles::directory(['q'=>$this->prefix,'areas'=>$areas])['total']);
    }
}
