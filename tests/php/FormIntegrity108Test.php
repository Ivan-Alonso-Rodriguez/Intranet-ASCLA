<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{Connections,Content,Messaging,Profiles,Settings};
use ASCLA\Core\Repositories\Store;

final class FormIntegrity108Test extends TestCase
{
    private array $users=[],$posts=[],$groups=[],$settings=[];
    private int $admin,$member,$friend;
    protected function setUp(): void
    {
        $this->settings=Settings::get();
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $name='forms108_'.bin2hex(random_bytes(5));
            $id=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role,'first_name'=>'José','last_name'=>'Prueba']);
            $this->users[]=$id;update_user_meta($id,'_ascla_profile',ascla_test_profile(['first_name'=>'José','last_name'=>'Prueba','position'=>'Analista','company'=>'ASCLA']));
        }
        [$this->admin,$this->member,$this->friend]=$this->users;wp_set_current_user($this->admin);
        add_filter('pre_wp_mail','__return_true');add_filter('send_auth_cookies','__return_false');
    }
    protected function tearDown(): void
    {
        update_option('ascla_settings',$this->settings,false);
        foreach($this->posts as $id)wp_delete_post($id,true);
        foreach($this->groups as $id){Store::delete('messages',['conversation_id'=>$id]);Store::delete('participants',['conversation_id'=>$id]);Store::delete('conversations',['id'=>$id]);}
        foreach($this->users as $id)wp_delete_user($id);
        remove_filter('pre_wp_mail','__return_true');remove_filter('send_auth_cookies','__return_false');wp_set_current_user(0);
    }
    private function api(string $route,array $input): WP_REST_Response
    {
        $r=new WP_REST_Request('POST','/ascla/v1/'.$route);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($input));return rest_get_server()->dispatch($r);
    }
    public static function invalidWeights(): array
    {
        return [[101],[-1],[1.5],['30 people'],[true],[null],[['value'=>30]]];
    }
    #[DataProvider('invalidWeights')]
    public function testInvalidWeightDoesNotClampOrPersistOtherSettings(mixed $weight): void
    {
        $before=Settings::get();$result=$this->api('settings',['matching_weights'=>['interests'=>$weight],'demo'=>!$before['demo']]);
        self::assertSame(400,$result->get_status());self::assertSame($before,Settings::get());
    }
    public static function invalidSettings(): array
    {
        return [['micro_min',true],['micro_priority_hours',false],['micro_interval_days',7.5],['profile_snooze_days',true],['profile_review_days',[180]],['directory_page_size','15x'],['remember_days',true],['login_challenge_after',true],['password_reset_minutes',5.5],['micro_capacity',101],['demo',['false']]];
    }
    #[DataProvider('invalidSettings')]
    public function testAdministrativeValuesAreRejectedWithoutPartialChanges(string $field,mixed $value): void
    {
        $before=Settings::get();self::assertSame(400,$this->api('settings',[$field=>$value])->get_status());self::assertSame($before,Settings::get());
    }
    public static function invisibleFields(): array
    {
        return [['first_name',"\u{200D}"],['last_name',"\u{2060}"],['position',"\u{202E}\u{202C}"],['company'," \u{200C}\u{FEFF} "],['bio',"\u{2060}"]];
    }
    #[DataProvider('invisibleFields')]
    public function testInvisibleRequiredProfileFieldDoesNotChangeOtherPreferences(string $field,string $value): void
    {
        wp_set_current_user($this->member);$before=get_user_meta($this->member,'_ascla_profile',true);
        self::assertSame(400,$this->api('profiles/me',[$field=>$value,'directory'=>!($before['directory']??true)])->get_status());self::assertSame($before,get_user_meta($this->member,'_ascla_profile',true));
    }
    public function testMalformedIdsCannotSelectValidTermsByRemovingCharacters(): void
    {
        wp_set_current_user($this->member);$before=Profiles::raw($this->member);$term=(int)$before['interests'][0];
        foreach ([-$term,$term.'suffix',true,1.5,0,null,[$term]] as $invalid) {
            self::assertSame(400,$this->api('profiles/me',['interests'=>[$invalid],'directory'=>false])->get_status());self::assertSame($before,Profiles::raw($this->member));
        }
        $valid=$this->api('profiles/me',['interests'=>[(string)$term,$term],'first_name'=>'José']);self::assertSame(200,$valid->get_status());self::assertSame([$term],Profiles::raw($this->member)['interests']);
        wp_set_current_user($this->admin);foreach([-$term,$term.'suffix'] as $invalid){self::assertSame(400,$this->api('content/hub',['title'=>'Valid title','body'=>'Valid text','interest'=>[$invalid]])->get_status());}
    }
    public function testNestedMetadataAndExcessTagsDoNotCreateContent(): void
    {
        foreach ([['url'=>false],['location'=>['invalid']],['capacity'=>null],['chatham'=>['false']],['media_ids'=>['1suffix']],['reminder_hours'=>[true]],['reminder_hours'=>[1.5]]] as $meta) {
            self::assertSame(400,$this->api('content/event',['title'=>'Valid','body'=>'Valid text','meta'=>$meta])->get_status());
        }
        self::assertSame(400,$this->api('content/hub',['title'=>'Valid','body'=>'Valid text','tag_names'=>array_fill(0,21,'Too many')])->get_status());
        foreach([1.5,true] as $duration){self::assertSame(400,$this->api('items/999999/video-duration',['duration_seconds'=>$duration])->get_status());}
        self::assertSame([],get_posts(['post_type'=>['ascla_hub','ascla_event'],'author'=>$this->admin,'post_status'=>'any']));
    }
    public function testIncompleteInvisibleLegacyProfileIsStillVisibleButCannotBeSaved(): void
    {
        wp_set_current_user($this->member);$data=get_user_meta($this->member,'_ascla_profile',true);$data['bio']="\u{200D}\u{2060}";update_user_meta($this->member,'_ascla_profile',$data);
        self::assertLessThan(100,Profiles::completion($this->member)['percent']);self::assertContains('Descripción profesional',Profiles::completion($this->member)['missing']);
        self::assertSame(400,$this->api('profiles/me',['networking'=>false])->get_status());self::assertSame($data,get_user_meta($this->member,'_ascla_profile',true));
        update_user_meta($this->member,'locale','en_US');
        self::assertSame('Complete the required fields: Professional description.',$this->api('profiles/me',['networking'=>false])->get_data()['message']);
        wp_set_current_user($this->admin);self::assertContains($this->member,array_column(Profiles::directory([])['items'],'id'));
        self::assertSame(400,$this->api('admin/users',['login'=>'invisible_'.bin2hex(random_bytes(4)),'email'=>'invisible@example.invalid','first_name'=>"\u{200D}",'last_name'=>'','role'=>'ascla_member'])->get_status());
    }
    public function testIntegerStringsAndFalseStringsKeepTheirMeaning(): void
    {
        $result=$this->api('settings',['matching_weights'=>['interests'=>'31'],'micro_priority_hours'=>'0','profile_snooze_days'=>'1','demo'=>'false']);self::assertSame(200,$result->get_status());$settings=Settings::get();self::assertSame(31,$settings['matching_weights']['interests']);self::assertSame(0,$settings['micro_priority_hours']);self::assertSame(1,$settings['profile_snooze_days']);self::assertFalse($settings['demo']);
        $result=$this->api('content/hub',['title'=>'Título público intacto','body'=>'Texto original','meta'=>['chatham'=>'false']]);self::assertSame(200,$result->get_status());$this->posts[]=$result->get_data()['id'];self::assertFalse(get_post_meta(end($this->posts),'_ascla',true)['chatham']);
    }
    public function testGroupTitleAndMalformedMembersCannotModifyAConversation(): void
    {
        foreach([$this->member,$this->friend] as $uid){wp_set_current_user($this->admin);$request=Connections::request($uid);wp_set_current_user($uid);Connections::respond($request['request_id'],'accept');}
        wp_set_current_user($this->admin);$group=Messaging::createGroup('Grupo original',[(string)$this->member,$this->friend]);$this->groups[]=$group['id'];
        self::assertSame(400,$this->api('conversations/'.$group['id'].'/group',['title'=>"\u{200D}\u{2060}",'description'=>'Must not change'])->get_status());self::assertSame('Grupo original',Messaging::conversation($group['id'])['title']);
        self::assertSame(400,$this->api('conversations/group',['title'=>'Valid','users'=>[$this->member.'suffix',$this->friend]])->get_status());
        self::assertCount(3,Store::rows('participants','conversation_id=%d',[$group['id']],''));
    }
}
