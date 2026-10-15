<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{AssistantContext,Content,Profiles,Notifications};
use ASCLA\Core\Repositories\Store;

final class Release107PriorityTest extends TestCase
{
    private array $users=[],$posts=[];

    protected function setUp(): void
    {
        foreach (['administrator','ascla_executive','ascla_moderator','ascla_member','ascla_executive'] as $role) {
            $login='priority107_'.bin2hex(random_bytes(7));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[3]);
        Profiles::save(ascla_test_profile(['networking'=>false]));
        wp_set_current_user($this->users[0]);
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]);
        foreach ($this->posts as $id) { wp_delete_post($id,true); }
        foreach ($this->users as $id) {
            foreach (['notifications','jobs','relations','audit'] as $table) {
                Store::delete($table,[$table==='audit'?'actor_id':'user_id'=>$id]);
            }
            wp_delete_user($id);
        }
        wp_set_current_user(0);
    }

    private function post(string $type,string $title,string $body,array $meta=[]): int
    {
        $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_status'=>'publish','post_author'=>$this->users[1],'post_title'=>$title,'post_content'=>$body]);
        $this->posts[]=$id;update_post_meta($id,'_ascla',$meta);
        return $id;
    }

    public function testUpcomingEventsSurviveHistoryAndPagesOfRestrictedOrCancelledEvents(): void
    {
        for ($i=0;$i<31;$i++) {
            $past=strtotime('2020-01-01 UTC')+$i*DAY_IN_SECONDS;
            $this->post('event','Evento anterior '.$i,'Evento finalizado.',['start'=>gmdate('c',$past),'end'=>gmdate('c',$past+3600)]);
            $this->post('event','Microevento reservado '.$i,'Solo invitados.',[
                'start'=>gmdate('c',time()+60+$i),'end'=>gmdate('c',time()+3600),
                'micro'=>true,'invitees'=>[],'micro_public_at'=>time()+DAY_IN_SECONDS,
            ]);
        }
        $cancelled=$this->post('event','Evento cancelado','No debe recomendarse.',[
            'start'=>gmdate('c',time()+100),'end'=>gmdate('c',time()+3600),'cancelled'=>true,
        ]);
        $visible=$this->post('event','Evento próximo disponible','Descripción autorizada.',[
            'start'=>gmdate('c',time()+120),'end'=>gmdate('c',time()+7200),
        ]);
        wp_set_current_user($this->users[3]);
        $context=AssistantContext::build('¿Hay eventos próximos?');
        self::assertContains($visible,array_column($context['sources'],'id'));
        self::assertNotContains($cancelled,array_column($context['sources'],'id'));
        self::assertLessThanOrEqual(8,count($context['live']['upcoming_events']));
        self::assertNotContains('Solo invitados.',array_column($context['sources'],'body'));
    }

    public function testSearchChecksReaderTextBeforePaginationAndRetainsAuthorizedOriginals(): void
    {
        $identity='identidadprivada'.bin2hex(random_bytes(8));$publicLabel='referenciapublica'.bin2hex(random_bytes(8));
        $private=$this->post('resource',$publicLabel.' '.$identity,'El ponente '.$identity.' presentó el programa.',[
            'chatham'=>true,'identities'=>$identity,'source'=>'Fuente de '.$identity,
        ]);
        $public=$this->post('resource','Publicación abierta',$identity.' es una referencia públicamente autorizada.',['chatham'=>false]);
        $original=get_post($private)->post_content;
        foreach ([$this->users[2],$this->users[3],$this->users[4]] as $reader) {
            wp_set_current_user($reader);
            $found=Content::listing('resource',['q'=>$identity,'per_page'=>1]);
            self::assertSame([$public],array_column($found['items'],'id'));
            self::assertSame(1,$found['total']);self::assertSame(1,$found['pages']);
            self::assertSame([$private],array_column(Content::listing('resource',['q'=>$publicLabel])['items'],'id'));
            $author=get_userdata($this->users[1])->display_name;
            self::assertContains($private,array_column(Content::listing('resource',['q'=>$author])['items'],'id'));
        }
        foreach ([$this->users[0],$this->users[1]] as $editor) {
            wp_set_current_user($editor);
            self::assertContains($private,array_column(Content::listing('resource',['q'=>$identity])['items'],'id'));
        }
        self::assertSame($original,get_post($private)->post_content);
        wp_set_current_user($this->users[0]);update_post_meta($private,'_ascla',['chatham'=>false]);
        wp_set_current_user($this->users[3]);
        self::assertSame(2,Content::listing('resource',['q'=>$identity])['total']);
    }

    public static function rejectedWrites(): array
    {
        return ['profile'=>['_ascla_profile'],'native identity'=>['first_name'],
            'review'=>['_ascla_profile_review'],'email preferences'=>['_ascla_email_notifications'],
            'revision'=>['option']];
    }

    public function testSearchDoesNotFallBackToOriginalTextWhenPrivacyQueryFails(): void
    {
        $identity='queryfailure'.bin2hex(random_bytes(8));
        $this->post('resource','Referencia pública','El ponente '.$identity.' intervino.',['chatham'=>true,'identities'=>$identity]);
        wp_set_current_user($this->users[3]);
        global $wpdb;
        $previous=$wpdb->suppress_errors(true);
        $fail=static fn($sql)=>str_starts_with($sql,'SELECT ID FROM '.$wpdb->posts.' WHERE post_type IN')?'SELECT ID FROM '.$wpdb->prefix.'ascla_missing_review_table':$sql;
        add_filter('query',$fail);
        try {
            $request=new WP_REST_Request('GET','/ascla/v1/content/resource');$request->set_query_params(['q'=>$identity]);
            $response=rest_do_request($request);
            self::assertSame(500,$response->get_status());
            self::assertStringNotContainsString($identity,wp_json_encode($response->get_data()));
        } finally { remove_filter('query',$fail);$wpdb->suppress_errors($previous); }
    }

    #[DataProvider('rejectedWrites')]
    public function testFailedProfileWriteReturnsErrorAndRollsBackAllChanges(string $key): void
    {
        $id=$this->users[3];wp_set_current_user($id);
        update_user_meta($id,'_ascla_profile_review',['confirmed_at'=>time()-DAY_IN_SECONDS,'snoozed_until'=>0]);
        $before=Profiles::raw($id);$review=get_user_meta($id,'_ascla_profile_review',true);
        $email=get_user_meta($id,'_ascla_email_notifications',true);$revision=get_option('ascla_profile_revision');
        $filter=$key==='option'?'pre_update_option_ascla_profile_revision':'update_user_metadata';
        $reject=$key==='option'?static fn($value,$old)=>$old
            :static fn($check,$uid,$field)=>$uid===$id && $field===$key?false:$check;
        add_filter($filter,$reject,30,$key==='option'?2:3);
        try {
            $request=new WP_REST_Request('POST','/ascla/v1/profiles/me');
            $request->set_header('Content-Type','application/json');
            $request->set_body(wp_json_encode(['first_name'=>'Nombre actualizado','company'=>'Empresa actualizada','email_notifications'=>['connections'=>false]]));
            self::assertSame(500,rest_do_request($request)->get_status());
        } finally { remove_filter($filter,$reject,30); }
        wp_set_current_user(0);wp_set_current_user($id);
        self::assertSame($before,Profiles::raw($id));
        self::assertSame($before['first_name'],get_userdata($id)->first_name);
        self::assertSame($review,get_user_meta($id,'_ascla_profile_review',true));
        self::assertSame($email,get_user_meta($id,'_ascla_email_notifications',true));
        self::assertEquals($revision,get_option('ascla_profile_revision'));
    }

    public function testSuccessfulProfileSaveConfirmsIdentityPreferencesAndReview(): void
    {
        $id=$this->users[3];wp_set_current_user($id);
        $saved=Profiles::save(['first_name'=>'Nombre actualizado','company'=>'Empresa actualizada','email_notifications'=>['connections'=>false]]);
        self::assertSame('Empresa actualizada',$saved['company']);
        self::assertSame('Nombre actualizado',get_userdata($id)->first_name);
        self::assertFalse(Notifications::emailPreferences($id)['connections']);
        self::assertGreaterThanOrEqual(time()-2,get_user_meta($id,'_ascla_profile_review',true)['confirmed_at']);
    }

    public function testEventContextAndReaderMetadataHideReservedLocation(): void
    {
        $identity='identity'.bin2hex(random_bytes(8));
        $id=$this->post('event','Programa público 2026','Descripción autorizada.',[
            'start'=>gmdate('c',time()+120),'end'=>gmdate('c',time()+7200),
            'chatham'=>true,'identities'=>$identity,'location'=>'Sala '.$identity,'modality'=>'Sesión con '.$identity,
        ]);
        wp_set_current_user($this->users[3]);
        $context=AssistantContext::build('Eventos próximos');
        self::assertContains($id,array_column($context['sources'],'id'));
        self::assertStringNotContainsString($identity,wp_json_encode($context['live']));
        self::assertStringNotContainsString($identity,wp_json_encode($context['sources']));
        self::assertStringNotContainsString($identity,wp_json_encode(Content::serialize(Content::get($id))));
        self::assertSame('Programa público 2026',array_column($context['sources'],'title','id')[$id]);
        self::assertStringContainsString($identity,get_post_meta($id,'_ascla',true)['location']);
    }
}
