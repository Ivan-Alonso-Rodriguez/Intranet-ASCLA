<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{Account,Content,Settings};
use ASCLA\Core\Repositories\Store;

final class FormWriteValidationTest extends TestCase
{
    private int $user;private array $settings;private array $posts=[];
    private const CURRENT='B4!oW8jK-2LqV7eR';private const NEXT='D2#kP9wX-vT6rH8eQ';
    protected function setUp(): void
    {
        $this->settings=Settings::get();$name='form_guard_'.bin2hex(random_bytes(4));
        $this->user=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>self::CURRENT,'role'=>'administrator']);
        wp_set_current_user($this->user);add_filter('send_auth_cookies','__return_false');
    }
    protected function tearDown(): void
    {
        update_option('ascla_settings',$this->settings,false);remove_filter('send_auth_cookies','__return_false');
        foreach($this->posts as $id)wp_delete_post($id,true);wp_delete_user($this->user);wp_set_current_user(0);
    }
    private function request(string $path,mixed $input,string $method='POST'): WP_REST_Response
    {
        $request=new WP_REST_Request($method,'/ascla/v1'.$path);$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($input));
        return rest_get_server()->dispatch($request);
    }
    public static function malformedRequests(): array
    {
        return [
            'scalar JSON'=>['/content/hub','invalid'],
            'nonempty list'=>['/content/hub',['invalid']],
            'null JSON'=>['/content/hub',null],
            'title object'=>['/content/hub',['title'=>['invalid'],'body'=>'Valid text']],
            'boolean body'=>['/content/hub',['title'=>'Valid title','body'=>true]],
            'meta scalar'=>['/content/event',['title'=>'Valid title','body'=>'Valid text','meta'=>'wrong']],
            'comments object'=>['/items/999999/comments',['body'=>['not text']]],
            'message object'=>['/conversations/999999/messages',['body'=>['not text']]],
            'group users object'=>['/conversations/group',['title'=>'A group','users'=>['member'=>1]]],
            'group users nested'=>['/conversations/group',['title'=>'A group','users'=>[[1]]]],
            'boolean object'=>['/relations',['target'=>1,'kind'=>'block','active'=>['false']]],
            'target object'=>['/conversations',['target'=>[1]]],
            'password object'=>['/account/password',['current_password'=>['wrong'],'new_password'=>'fake','confirm_password'=>'fake']],
            'preferences scalar'=>['/profiles/me',['email_notifications'=>'wrong']],
        ];
    }
    #[DataProvider('malformedRequests')]
    public function testMalformedFieldShapesAre400BeforeAnyWrite(string $path,mixed $input): void
    {
        $before=count(Store::rows('audit','actor_id=%d',[$this->user],''));
        $response=$this->request($path,$input);self::assertSame(400,$response->get_status(),wp_json_encode($response->get_data()));
        self::assertSame($before,count(Store::rows('audit','actor_id=%d',[$this->user],'')));
        self::assertSame([],get_posts(['post_type'=>array_values(array_map(static fn($t)=>'ascla_'.$t,['hub','event','contact'])),'author'=>$this->user,'post_status'=>'any']));
    }
    public static function invalidEventNumbers(): array
    {
        return [['capacity',-1],['capacity',1.5],['capacity','12 people'],['capacity',true],['capacity',100001],['offer_hours',0],['offer_hours',169],['offer_hours',1.5],['duration_seconds',1.5],['duration_seconds',true],['duration_seconds',604801]];
    }
    #[DataProvider('invalidEventNumbers')]
    public function testEventNumbersAreRejectedWithoutChangingAPublishedEvent(string $field,mixed $value): void
    {
        $event=Content::save('event',['title'=>'Valid original','body'=>'Valid body','status'=>'publish','meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200),'capacity'=>10,'offer_hours'=>24]]);$this->posts[]=$event['id'];
        $old=get_post($event['id']);$meta=get_post_meta($event['id'],'_ascla',true);
        $result=$this->request('/content/event/'.$event['id'],['title'=>'Must not save','body'=>'Changed text','status'=>'publish','meta'=>[$field=>$value]]);
        self::assertSame(400,$result->get_status());self::assertSame($old->post_title,get_post($event['id'])->post_title);self::assertSame($meta,get_post_meta($event['id'],'_ascla',true));
    }
    public function testContactLimitAndInvisibleMandatoryContentCannotBypassBrowserRules(): void
    {
        foreach([['title'=>'Valid','body'=>str_repeat('x',5001)],['title'=>"\u{200B}\u{200D}",'body'=>'Valid'],['title'=>'Valid','body'=>"\u{FEFF}"]] as $payload){self::assertSame(400,$this->request('/content/contact',$payload)->get_status());}
        $good=$this->request('/content/contact',['title'=>'Valid','body'=>str_repeat('x',5000)]);self::assertSame(200,$good->get_status());$this->posts[]=$good->get_data()['id'];
    }
    public function testListFiltersStillAcceptQueryParametersAndIntegerStringsStillWork(): void
    {
        $r=new WP_REST_Request('GET','/ascla/v1/content/hub');$r->set_query_params(['category'=>'','after'=>'2026-01-01']);self::assertSame(200,rest_get_server()->dispatch($r)->get_status());
        $r=new WP_REST_Request('GET','/ascla/v1/content/topic');$r->set_query_params(['parent'=>'','after'=>'2026-01-01']);self::assertSame(200,rest_get_server()->dispatch($r)->get_status());
        $r->set_query_params(['parent'=>'-1']);self::assertSame(400,rest_get_server()->dispatch($r)->get_status());
        $event=$this->request('/content/event',['title'=>'Numbers','body'=>'Valid text','meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200),'capacity'=>'100000','offer_hours'=>'168']]);self::assertSame(200,$event->get_status());$this->posts[]=$event->get_data()['id'];
    }
    public function testErrorsUseTheAccountLocaleAndDoNotRewriteContent(): void
    {
        update_user_meta($this->user,'locale','en_US');
        $bad=$this->request('/content/hub',['title'=>str_repeat('x',201),'body'=>'Valid']);self::assertSame(400,$bad->get_status());self::assertSame('The text exceeds the limit of 200 characters.',$bad->get_data()['message']);
        $shape=$this->request('/account/password',['current_password'=>['invalid']]);self::assertSame('Invalid text.',$shape->get_data()['message']);
        $title='Complete título y contenido.';$body='Título original en español con <script>alert(1)</script> texto.';
        $good=$this->request('/content/hub',['title'=>$title,'body'=>$body,'status'=>'publish']);self::assertSame(200,$good->get_status());$this->posts[]=$good->get_data()['id'];self::assertSame($title,get_post(end($this->posts))->post_title);self::assertStringNotContainsString('<script>',get_post(end($this->posts))->post_content);
        update_user_meta($this->user,'locale','es_ES');self::assertSame('El texto supera el límite de 200 caracteres.',$this->request('/content/hub',['title'=>str_repeat('x',201),'body'=>'Valid'])->get_data()['message']);
    }
    public function testFailedPasswordWriteDoesNotClaimSuccessOrIssueANewCookie(): void
    {
        global $wpdb;$id=$this->user;$table=$wpdb->users;$cookies=0;
        $filter=static function($q)use($table,$id){if(preg_match('/^UPDATE\s+`?'.preg_quote($table,'/').'`?\s+SET /i',$q)&&str_contains($q,'user_pass')&&preg_match('/`?ID`?\s*=\s*'.$id.'\b/',$q))return "UPDATE $table SET user_pass=user_pass WHERE ID=$id";return $q;};
        $cookie=static function()use(&$cookies){$cookies++;};add_filter('query',$filter);add_action('set_auth_cookie',$cookie);
        try{Account::changePassword(['current_password'=>self::CURRENT,'new_password'=>self::NEXT,'confirm_password'=>self::NEXT]);self::fail('A failed write must reject the request');}
        catch(\ASCLA\Core\Rest\ApiException $e){self::assertSame(500,$e->getCode());}
        finally{remove_filter('query',$filter);remove_action('set_auth_cookie',$cookie);}
        self::assertTrue(wp_check_password(self::CURRENT,get_userdata($id)->user_pass,$id));self::assertFalse(wp_check_password(self::NEXT,get_userdata($id)->user_pass,$id));self::assertSame(0,$cookies);self::assertSame([],Store::rows('audit',"actor_id=%d AND action='password_changed'",[$id],''));
    }
}
