<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{InterestImports,Profiles};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\InterestCatalog;
use ASCLA\Core\Rest\ApiException;

final class FormsInput108Test extends TestCase
{
    private array $users=[];
    private int $topic;
    private string $csv;
    protected function setUp(): void
    {
        foreach(['administrator','ascla_member'] as $role){$name='formsinput_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);}
        wp_set_current_user($this->users[1]);Profiles::save(ascla_test_profile());wp_set_current_user($this->users[0]);add_filter('pre_wp_mail','__return_true');
        $name='FormsTopic'.bin2hex(random_bytes(5));$this->topic=(int)wp_insert_term($name,'ascla_interest')['term_id'];
        $this->csv="Email,Interests,Free\n".get_userdata($this->users[1])->user_email.",$name,Revisar texto libre\n";
    }
    protected function tearDown(): void
    {
        foreach(Store::rows('imports','actor_id=%d',[$this->users[0]],'ORDER BY id') as $row){Store::delete('import_rows',['import_id'=>$row['id']]);Store::delete('imports',['id'=>$row['id']]);}
        foreach($this->users as $id)wp_delete_user($id);wp_delete_term($this->topic,'ascla_interest');remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    private function input(): array{return ['csv'=>$this->csv,'filename'=>'forms.csv','columns'=>['email'=>'0','code'=>'-1','structured'=>'1','free'=>'2']];}
    private function api(string $route,array $body): WP_REST_Response
    {
        $r=new WP_REST_Request('POST','/ascla/v1/admin/interest-imports'.$route);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));return rest_get_server()->dispatch($r);
    }
    private function rejected(callable $action): void
    {
        try{$action();self::fail('Invalid input must be rejected.');}catch(ApiException $e){self::assertSame(400,$e->getCode());}
    }
    private function reviewRow(): array
    {
        $id=(int)InterestImports::start($this->input())['id'];$data=InterestImports::process($id);self::assertNotEmpty($data['rows'][0]['payload']['warnings']);return [$id,$data['rows'][0]['id']];
    }
    public static function columns(): array{return [[true],[false],[1.5],['1x'],[-2],[3],[null],[[]]];}
    #[DataProvider('columns')]
    public function testBadColumnValuesCannotCreateAnImport(mixed $value): void
    {
        $body=$this->input();$body['columns']['structured']=$value;$before=Store::count('imports','actor_id=%d',[$this->users[0]]);
        self::assertSame(400,$this->api('',$body)->get_status());$this->rejected(fn()=>InterestImports::start($body));self::assertSame($before,Store::count('imports','actor_id=%d',[$this->users[0]]));
    }
    public static function topics(): array{return [[true],[false],[1.5],['suffix'],['negative'],['map'],['nested']];}
    #[DataProvider('topics')]
    public function testBadInterestListsPreserveTheReviewRowAndProfile(mixed $value): void
    {
        $topics=match($value){'suffix'=>[$this->topic.'suffix'],'negative'=>[-$this->topic],'map'=>['topic'=>$this->topic],'nested'=>[[$this->topic]],default=>[$value]};
        [$id,$row]=$this->reviewRow();$before=Store::one('import_rows',$row);$profile=Profiles::raw($this->users[1]);
        self::assertSame(400,$this->api('/'.$id.'/rows/'.$row,['decision'=>'accept','topics'=>$topics,'acknowledge'=>true])->get_status());$this->rejected(fn()=>InterestCatalog::validate($topics));
        self::assertSame($before,Store::one('import_rows',$row));self::assertSame($profile,Profiles::raw($this->users[1]));
    }
    public static function acknowledgements(): array{return [[false],['false'],[[]],[2],['yes']];}
    #[DataProvider('acknowledgements')]
    public function testWarningsRequireAValidTrueAcknowledgement(mixed $value): void
    {
        [$id,$row]=$this->reviewRow();$before=Store::one('import_rows',$row);$input=['decision'=>'accept','topics'=>[$this->topic],'acknowledge'=>$value];
        self::assertSame(400,$this->api('/'.$id.'/rows/'.$row,$input)->get_status());$this->rejected(fn()=>InterestImports::review($id,$row,$input));self::assertSame($before,Store::one('import_rows',$row));
    }
    public function testCanonicalColumnsInterestsAndAcknowledgementRemainSupported(): void
    {
        [$id,$row]=$this->reviewRow();$r=$this->api('/'.$id.'/rows/'.$row,['decision'=>'accept','topics'=>[(string)$this->topic,$this->topic],'acknowledge'=>'true']);self::assertSame(200,$r->get_status());
        self::assertSame('approved',Store::one('import_rows',$row)['state']);self::assertSame([$this->topic],json_decode(Store::one('import_rows',$row)['payload'],true)['topics']);
    }
}
