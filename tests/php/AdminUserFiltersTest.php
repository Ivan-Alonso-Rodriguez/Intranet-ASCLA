<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Administration;

final class AdminUserFiltersTest extends TestCase
{
    private array $users=[],$terms=[];
    private int $admin;
    private string $prefix;
    protected function setUp(): void
    {
        $this->prefix='filters_'.bin2hex(random_bytes(5));
        $this->admin=$this->account('admin',[],'administrator');
        wp_set_current_user($this->admin);
        foreach(['industry','interest','area'] as $taxonomy) {
            foreach([1,2] as $number) {
                $term=wp_insert_term($this->prefix.$taxonomy.$number,'ascla_'.$taxonomy);
                $this->terms[$taxonomy][]=(int)$term['term_id'];
            }
        }
    }
    protected function tearDown(): void
    {
        foreach($this->users as $id) { wp_delete_user($id); }
        foreach($this->terms as $taxonomy=>$ids) { foreach($ids as $id) { wp_delete_term($id,'ascla_'.$taxonomy); } }
        wp_set_current_user(0);
    }
    private function account(string $suffix,array $profile=[],string $role='ascla_member'): int
    {
        $login=$this->prefix.'_'.$suffix;
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(24),'display_name'=>$login,'role'=>$role]);
        self::assertIsInt($id);$this->users[]=$id;
        update_user_meta($id,'_ascla_profile',$profile);
        return $id;
    }
    private function profile(array $overrides=[]): array
    {
        return $overrides+['country'=>'Perú','company'=>'Empresa '.$this->prefix,'position'=>'Ingeniería','experience'=>'Gobernanza y sostenibilidad','directory'=>false,'industries'=>[$this->terms['industry'][0]],'interests'=>[$this->terms['interest'][0]],'areas'=>[$this->terms['area'][0]]];
    }
    private function ids(array $filter): array { return array_column(Administration::users($filter)['items'],'id'); }
    public function testFacetsUseOrWithinAndAcrossGroupsWithoutPublicDirectoryRestrictions(): void
    {
        $first=$this->account('first',$this->profile());
        $second=$this->account('second',$this->profile(['industries'=>[$this->terms['industry'][1]]]));
        update_user_meta($second,'_ascla_suspended',true);
        $this->account('other-country',$this->profile(['country'=>'Chile']));
        $this->account('other-interest',$this->profile(['interests'=>[$this->terms['interest'][1]]]));
        $filter=['q'=>$this->prefix,'country'=>'peru','industries'=>$this->terms['industry'],'interests'=>[$this->terms['interest'][0]],'areas'=>[$this->terms['area'][0]]];
        $ids=$this->ids($filter);sort($ids);$expected=[$first,$second];sort($expected);self::assertSame($expected,$ids);
        self::assertSame([$second],$this->ids($filter+['state'=>'suspended']));
        self::assertSame([$first],$this->ids($filter+['state'=>'active']));
        self::assertSame([],$this->ids(array_replace($filter,['areas'=>[$this->terms['area'][1]]])));
    }
    public function testSearchIncludesProfessionalFieldsLoginEmailAndRespectsRole(): void
    {
        $member=$this->account('search',$this->profile());
        $executive=$this->account('executive',$this->profile(),'ascla_executive');
        foreach(['ingenieria','sostenibilidad','Empresa '.$this->prefix,$this->prefix.'_search@example.invalid'] as $query) {
            self::assertSame([$member],$this->ids(['q'=>$query,'role'=>'ascla_member','industries'=>[$this->terms['industry'][0]]]));
        }
        self::assertSame([$executive],$this->ids(['q'=>$this->prefix,'role'=>'ascla_executive']));
        self::assertSame([],$this->ids(['q'=>$this->prefix.'_missing']));
    }
    public function testFiltersAreAppliedBeforeTotalsAndPagination(): void
    {
        $expected=[];
        for($i=0;$i<23;$i++) { $expected[]=$this->account('page'.sprintf('%02d',$i),$this->profile()); }
        $this->account('excluded',$this->profile(['country'=>'Chile']));
        $filter=['q'=>$this->prefix,'country'=>'Perú','interests'=>[$this->terms['interest'][0]]];
        $first=Administration::users($filter+['page'=>1]);$second=Administration::users($filter+['page'=>2]);
        self::assertSame(23,$first['total']);self::assertSame(23,$second['total']);self::assertSame(2,$first['pages']);
        self::assertCount(20,$first['items']);self::assertCount(3,$second['items']);
        $ids=array_merge(array_column($first['items'],'id'),array_column($second['items'],'id'));sort($ids);sort($expected);self::assertSame($expected,$ids);
    }
    public function testRestFiltersRemainAdministrativeAndAcceptMultipleIds(): void
    {
        $member=$this->account('rest',$this->profile());
        $request=new WP_REST_Request('GET','/ascla/v1/admin/users');
        $request->set_query_params(['q'=>$this->prefix,'industries'=>$this->terms['industry'],'interests'=>[$this->terms['interest'][0]]]);
        $response=rest_do_request($request);self::assertSame(200,$response->get_status());self::assertSame([$member],array_column($response->get_data()['items'],'id'));
        wp_set_current_user($member);self::assertSame(403,rest_do_request($request)->get_status());
    }
}
