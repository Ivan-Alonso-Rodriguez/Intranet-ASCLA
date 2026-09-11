<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Profiles,Notifications,Settings,Messaging};
use ASCLA\Core\Repositories\{Store,ContentQuery};
final class SprintCoreTest extends TestCase
{
    private array $users=[],$posts=[],$settings=[],$conversations=[];
    protected function setUp(): void {
        $this->settings=Settings::get();
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $this->users[]=wp_insert_user(['user_login'=>'sprint_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]); Settings::save(['moderate_comments'=>false]);
    }
    protected function tearDown(): void {
        foreach($this->posts as $id) wp_delete_post($id,true);
        foreach($this->conversations as $id){foreach(['messages','participants'] as $table)Store::delete($table,['conversation_id'=>$id]);Store::delete('conversations',['id'=>$id]);}
        foreach($this->users as $id){foreach(['notifications','jobs','relations'] as $t)Store::delete($t,['user_id'=>$id]);wp_delete_user($id);}
        update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    private function post(string $type='hub'): int {
        $id=wp_insert_post(['post_type'=>'ascla_'.$type,'post_title'=>'Recurso original','post_content'=>'Contenido original','post_status'=>'publish','post_author'=>$this->users[1]]);
        $this->posts[]=$id;return $id;
    }
    private function api(string $path,array $data=[]): WP_REST_Response {
        $r=new WP_REST_Request('POST','/ascla/v1/'.$path);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));return rest_do_request($r);
    }
    public function testLongValidNotificationsAndCorruptRowAreIsolated(): void {
        wp_set_current_user($this->users[1]);
        foreach([180,181,200,2000] as $length){
            $job=Store::insert('jobs',['kind'=>'answer','payload'=>wp_json_encode(['question'=>str_repeat('á',$length)]),'status'=>'completed','result'=>'{}','user_id'=>$this->users[1],'created_at'=>current_time('mysql',true)]);
            Notifications::send($this->users[1],'job','Respuesta','',['type'=>'job','id'=>$job]);
            if($length<=200){$id=$this->post();wp_update_post(['ID'=>$id,'post_title'=>str_repeat('é',$length)]);Notifications::send($this->users[1],'reaction','Reacción','',['type'=>'post','id'=>$id]);}
        }
        Store::insert('notifications',['user_id'=>$this->users[1],'kind'=>'job','label'=>'Corrupto','context'=>'{"type":["job"],"id":1}','created_at'=>current_time('mysql',true)]);
        $feed=Notifications::feed([]);self::assertCount(8,$feed['items']);
        foreach($feed['items'] as $notice){self::assertLessThanOrEqual(180,mb_strlen($notice['description']));self::assertStringStartsWith(home_url(),$notice['url']);Notifications::open($notice['id']);}
    }
    public function testRejectedEditLeavesAllPostDataMetadataAndTermsUntouched(): void {
        $id=$this->post('resource');update_post_meta($id,'_ascla',['source'=>'Origen','resource_type'=>'Artículo']);
        $snapshot=static fn()=>[get_post($id,ARRAY_A),get_post_meta($id),wp_get_object_terms($id,['ascla_interest','ascla_tag'],['fields'=>'ids'])];
        $before=$snapshot();
        foreach([['interest'=>[999999999]],['tag_names'=>[str_repeat('x',61)]],['status'=>'invalid'],['meta'=>['media_ids'=>[999999999]]],['parent'=>$id]] as $invalid){
            $r=$this->api('content/resource/'.$id,array_replace(['title'=>'Cambio inválido','body'=>'Otro cuerpo','status'=>'publish','meta'=>['source'=>'Nuevo']],$invalid));
            self::assertGreaterThanOrEqual(400,$r->get_status());self::assertSame($before,$snapshot());
        }
    }
    public function testHiddenNamesAreAliasedInProfilesSearchContentCommentsAndMessages(): void {
        $uid=$this->users[1];wp_set_current_user($uid);Profiles::save(['first_name'=>'NombreReservado','last_name'=>'ApellidoReservado','hidden'=>['first_name'],'networking'=>true]);
        wp_set_current_user($this->users[0]);$post=$this->post('resource');wp_set_current_user($uid);Content::comment($post,'Opinión del asociado');
        wp_set_current_user($this->users[2]);$profile=Profiles::visible($uid);
        self::assertArrayNotHasKey('first_name',$profile);self::assertArrayNotHasKey('last_name',$profile);self::assertSame('Asociado ASCLA '.$uid,$profile['name']);
        self::assertSame(0,Profiles::directory(['q'=>'NombreReservado'])['total']);
        self::assertSame($profile['name'],Content::serialize(get_post($post))['author']['name']);
        self::assertSame($profile['name'],Content::comments($post)[0]['author']);
        self::assertSame(0,Content::listing('resource',['q'=>'NombreReservado'])['total']);
        foreach(ContentQuery::authors() as $a){if((int)$a['id']===$uid)self::assertSame($profile['name'],$a['name']);}
        Notifications::send($this->users[2],'reaction','Reacción','',['type'=>'post','id'=>$post,'actor'=>$uid]);
        self::assertStringNotContainsString('NombreReservado',wp_json_encode(Notifications::list()));
    }
    public function testCommentApprovalNotifiesExactlyOnce(): void {
        $post=$this->post();wp_set_current_user($this->users[2]);$immediate=Content::comment($post,'Comentario inmediato');
        self::assertSame(1,Store::count('notifications',"user_id=%d AND kind='comment'",[$this->users[1]]));
        wp_set_current_user($this->users[0]);Settings::save(['moderate_comments'=>true]);wp_set_current_user($this->users[2]);
        $pending=Content::comment($post,'Comentario pendiente');self::assertSame(1,Store::count('notifications',"user_id=%d AND kind='comment'",[$this->users[1]]));
        wp_set_current_user($this->users[0]);foreach([$pending['id'],$pending['id'],$immediate['id']] as $cid){self::assertSame(200,$this->api('admin/comments/'.$cid,['decision'=>'approve'])->get_status());}
        self::assertSame(2,Store::count('notifications',"user_id=%d AND kind='comment'",[$this->users[1]]));
    }
}
