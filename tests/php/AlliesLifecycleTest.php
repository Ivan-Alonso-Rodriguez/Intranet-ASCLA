<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Allies,Content,ContentSchedule};
use ASCLA\Core\Repositories\Store;

final class AlliesLifecycleTest extends TestCase
{
    private array $users=[],$posts=[];
    private int $admin,$other,$member,$executive;
    protected function setUp(): void
    {
        foreach(['administrator','administrator','ascla_member','ascla_executive'] as $role) {
            $name='allyqa_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);
        }
        [$this->admin,$this->other,$this->member,$this->executive]=$this->users;wp_set_current_user($this->admin);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach($this->posts as $id) { wp_delete_post($id,true);Store::delete('audit',['object_id'=>$id]);Store::delete('relations',['target_id'=>$id]); }
        foreach($this->users as $id) { foreach(['audit'=>'actor_id','notifications'=>'user_id','jobs'=>'user_id'] as $table=>$column) { Store::delete($table,[$column=>$id]); }wp_delete_user($id); }
        wp_set_current_user(0);
    }
    private function api(string $method,string $route,array $data=[]): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$route);
        if($method==='GET')$r->set_query_params($data);else{$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));}
        return rest_do_request($r);
    }
    private function ally(string $status='publish',array $meta=[],string $title='Alianza institucional'): int
    {
        $data=Content::save('ally',['title'=>$title,'body'=>'Descripción de la organización','status'=>$status,'meta'=>$meta+['alliance_type'=>'Convenio','benefits'=>'Capacitaciones para asociados','conditions'=>'Membresía activa','contact_email'=>'alianzas@example.invalid']]);
        $this->posts[]=(int)$data['id'];return (int)$data['id'];
    }
    public function testRetiringPreservesTheRecordAndReactivationUsesTheSameIdAndHistory(): void
    {
        $id=$this->ally();$original=get_post($id);$meta=get_post_meta($id,'_ascla',true);
        $comment=wp_insert_comment(['comment_post_ID'=>$id,'user_id'=>$this->member,'comment_content'=>'Comentario histórico','comment_approved'=>1]);
        Store::insert('relations',['user_id'=>$this->member,'target_id'=>$id,'kind'=>'like','created_at'=>gmdate('Y-m-d H:i:s')]);
        self::assertSame(200,$this->api('POST','admin/allies/'.$id.'/status',['state'=>'inactive'])->get_status());
        self::assertSame('ascla_hidden',get_post_status($id));self::assertSame($original->post_author,get_post($id)->post_author);self::assertSame($original->post_date,get_post($id)->post_date);
        self::assertSame($meta,get_post_meta($id,'_ascla',true));self::assertNotNull(get_comment($comment));self::assertSame(1,Store::count('relations',"target_id=%d AND kind='like'",[$id]));
        $before=count(Allies::history($id));Allies::changeStatus($id,'inactive');self::assertCount($before,Allies::history($id));
        self::assertSame(200,$this->api('POST','admin/allies/'.$id.'/status',['state'=>'active'])->get_status());self::assertSame('publish',get_post_status($id));
        self::assertSame($id,Content::serialize(Content::get($id))['id']);self::assertSame('active',Allies::history($id)[0]['to']);self::assertSame('inactive',Allies::history($id)[1]['to']);
    }
    public function testMembersCannotFindOrReadInactiveAlliesEvenWhenTheyAreLegacyAuthors(): void
    {
        $title='Ally '.bin2hex(random_bytes(5));$id=$this->ally('inactive',[],$title);wp_update_post(['ID'=>$id,'post_author'=>$this->member]);
        wp_set_current_user($this->member);
        foreach(['items/'.$id,'items/'.$id.'/comments'] as $route) { self::assertSame(404,$this->api('GET',$route)->get_status()); }
        self::assertSame(0,$this->api('GET','content/ally',['q'=>$title,'mine'=>1,'status'=>'ascla_hidden'])->get_data()['total']);
        foreach([$this->member,$this->executive] as $user){wp_set_current_user($user);foreach(['admin/allies','admin/allies/'.$id.'/history'] as $route) self::assertSame(403,$this->api('GET',$route)->get_status());self::assertSame(403,$this->api('POST','admin/allies/'.$id.'/status',['state'=>'active'])->get_status());}
        wp_set_current_user(0);self::assertSame(401,$this->api('GET','admin/allies')->get_status());
    }
    public function testEveryAdministratorCanReadAndEditDraftsAndInactiveRecordsFromOtherAdministrators(): void
    {
        $draft=$this->ally('draft');$inactive=$this->ally('inactive');wp_set_current_user($this->other);
        self::assertSame(200,$this->api('GET','items/'.$draft)->get_status());self::assertSame(200,$this->api('GET','items/'.$inactive)->get_status());
        $query=$this->api('GET','admin/allies',['q'=>'Alianza institucional'])->get_data();self::assertContains($draft,array_column($query['items'],'id'));self::assertContains($inactive,array_column($query['items'],'id'));
        $saved=$this->api('POST','content/ally/'.$inactive,['title'=>'Alianza actualizada','body'=>'Descripción revisada','status'=>'inactive']);self::assertSame(200,$saved->get_status());self::assertSame('inactive',$saved->get_data()['meta']['ally_status']);
    }
    public function testRegistryFiltersAndPaginatesWithoutIncludingTrash(): void
    {
        $title='Catálogo '.bin2hex(random_bytes(5));
        for($i=0;$i<20;$i++)$this->ally($i%2?'inactive':'publish',[],$title.' '.$i);
        $trash=$this->ally('publish',[],$title.' eliminado');wp_trash_post($trash);
        $first=Allies::listing(['q'=>$title]);$second=Allies::listing(['q'=>$title,'page'=>2]);
        self::assertSame(20,$first['total']);self::assertSame(2,$first['pages']);self::assertCount(18,$first['items']);self::assertCount(2,$second['items']);
        self::assertCount(20,array_unique(array_merge(array_column($first['items'],'id'),array_column($second['items'],'id'))));
        self::assertSame(10,Allies::listing(['q'=>$title,'state'=>'inactive'])['total']);self::assertSame(10,Allies::listing(['q'=>$title,'state'=>'active'])['total']);
    }
    public function testRetiringCancelsScheduledPublicationAndDoesNotLoseContent(): void
    {
        $id=$this->ally('draft');$at=time()+7200;
        $saved=Content::save('ally',['title'=>'Alianza programada','body'=>'Información conservada','status'=>'scheduled','publish_at'=>gmdate('c',$at)],$id);
        self::assertSame('scheduled',$saved['meta']['ally_status']);self::assertNotFalse(wp_next_scheduled('ascla_publish_content',[$id,$at]));
        Allies::changeStatus($id,'inactive');self::assertFalse(wp_next_scheduled('ascla_publish_content',[$id,$at]));
        self::assertArrayNotHasKey('publish_at',(array)get_post_meta($id,'_ascla',true));ContentSchedule::publish($id,$at);self::assertSame('ascla_hidden',get_post_status($id));self::assertSame('Información conservada',get_post($id)->post_content);
    }
    public function testLegacyScheduledRecordsWithoutIndexAreNotMislabelledAsInactive(): void
    {
        $id=$this->ally('inactive');$meta=(array)get_post_meta($id,'_ascla',true);$meta['publish_at']=gmdate('c',time()+3600);update_post_meta($id,'_ascla',$meta);delete_post_meta($id,'_ascla_publish_timestamp');
        self::assertContains($id,array_column(Allies::listing(['state'=>'scheduled'])['items'],'id'));
        self::assertNotContains($id,array_column(Allies::listing(['state'=>'inactive'])['items'],'id'));
    }
    public function testStatusFailureRollsBackMetadataStateAndHistory(): void
    {
        $id=$this->ally('inactive');$meta=get_post_meta($id,'_ascla',true);$count=count(Allies::history($id));
        $break=static function(array $data,array $post)use($id):array{if((int)($post['ID']??0)===$id && $data['post_status']==='publish')$data['post_status']='draft';return $data;};
        add_filter('wp_insert_post_data',$break,100,2);
        try{self::assertSame(500,$this->api('POST','admin/allies/'.$id.'/status',['state'=>'active'])->get_status());}finally{remove_filter('wp_insert_post_data',$break,100);}
        self::assertSame('ascla_hidden',get_post_status($id));self::assertSame($meta,get_post_meta($id,'_ascla',true));self::assertCount($count,Allies::history($id));
    }
    public function testInstitutionalContactFieldsAreValidatedAndAnInactiveEditorSaveIsSupported(): void
    {
        $id=$this->ally('inactive',['contact_name'=>'Área de alianzas','contact_phone'=>'+51 987 654 321']);
        $view=Content::serialize(Content::get($id));self::assertSame('Área de alianzas',$view['meta']['contact_name']);self::assertFalse($view['meta']['chatham']);
        $bad=$this->api('POST','content/ally/'.$id,['title'=>'Alianza','body'=>'Información','status'=>'inactive','meta'=>['contact_email'=>'not-an-email']]);self::assertSame(400,$bad->get_status());
        self::assertSame('alianzas@example.invalid',get_post_meta($id,'_ascla',true)['contact_email']);
        self::assertSame(400,$this->api('POST','admin/allies/'.$id.'/status',['state'=>'deleted'])->get_status());
        self::assertSame(400,$this->api('POST','content/hub',['title'=>'Hub','body'=>'Texto','status'=>'inactive'])->get_status());
    }
}
