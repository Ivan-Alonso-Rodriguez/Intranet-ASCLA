<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Content;
use ASCLA\Core\Domain\Transcript;

final class EditorialPrivacyTest extends TestCase
{
    private array $users=[];
    private int $post=0;
    protected function setUp(): void
    {
        foreach(['administrator','ascla_executive','ascla_moderator','ascla_member'] as $role){
            $login='editorial_privacy_'.bin2hex(random_bytes(5));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
        $this->post=wp_insert_post(['post_type'=>'ascla_resource','post_status'=>'publish','post_author'=>$this->users[1],
            'post_title'=>'Sesión de Persona Prueba','post_content'=>'Persona Prueba explicó el seguimiento de riesgos en Compañía Privada.']);
        update_post_meta($this->post,'_ascla',['chatham'=>true,'transcript'=>'Persona Prueba conserva aquí la transcripción original.','identities'=>"Persona Prueba\nCompañía Privada",
            'summary'=>'Persona Prueba explicó sus acuerdos.','video_id'=>'M7lc1UVf-VE','youtube_url'=>'https://www.youtube.com/watch?v=M7lc1UVf-VE',
            'moments'=>[['start'=>60,'end'=>120,'title'=>'Persona Prueba revisó riesgos.','youtube_url'=>'https://untrusted.invalid/private']]]);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]);wp_delete_post($this->post,true);
        foreach($this->users as $id){wp_delete_user($id);}wp_set_current_user(0);
    }
    public function testReadersIncludingModeratorsReceiveAnonymousDataWithoutLosingVideoLinks(): void
    {
        $original=get_post($this->post)->post_content;$meta=get_post_meta($this->post,'_ascla',true);
        foreach([$this->users[2],$this->users[3]] as $id){
            wp_set_current_user($id);
            $response=rest_do_request(new WP_REST_Request('GET','/ascla/v1/items/'.$this->post));self::assertSame(200,$response->get_status());
            $view=$response->get_data();$text=wp_json_encode($view,JSON_UNESCAPED_UNICODE);
            foreach(['Persona Prueba','Compañía Privada','untrusted.invalid'] as $secret){self::assertStringNotContainsString($secret,$text);}
            self::assertArrayNotHasKey('transcript',$view['meta']);self::assertArrayNotHasKey('identities',$view['meta']);
            self::assertSame('https://www.youtube.com/watch?v=M7lc1UVf-VE&t=60s',$view['meta']['moments'][0]['youtube_url']);
        }
        self::assertSame($original,get_post($this->post)->post_content);self::assertSame($meta,get_post_meta($this->post,'_ascla',true));
    }
    public function testOnlyAuthorizedEditorsSeeTheOriginalSourceAndOptOutKeepsAuthoredAttribution(): void
    {
        foreach([$this->users[0],$this->users[1]] as $id){wp_set_current_user($id);$view=Content::serialize(Content::get($this->post));
            self::assertStringContainsString('Persona Prueba',$view['body']);self::assertArrayHasKey('transcript',$view['meta']);}
        // A publisher who does not own this resource is also a reader.
        wp_update_post(['ID'=>$this->post,'post_author'=>$this->users[0]]);wp_set_current_user($this->users[1]);
        $view=Content::serialize(Content::get($this->post));self::assertArrayNotHasKey('transcript',$view['meta']);self::assertStringNotContainsString('Persona Prueba',$view['body']);
        $meta=get_post_meta($this->post,'_ascla',true);$meta['chatham']=false;update_post_meta($this->post,'_ascla',$meta);
        wp_set_current_user($this->users[3]);$view=Content::serialize(Content::get($this->post));
        self::assertStringContainsString('Persona Prueba',$view['body']);self::assertArrayNotHasKey('transcript',$view['meta']);
    }
    public function testVideoLinksCannotReuseProviderSuppliedUrlsOrInvalidVideoIds(): void
    {
        $clip=['start'=>60,'youtube_url'=>'https://untrusted.invalid/identity'];
        self::assertSame('https://www.youtube.com/watch?v=M7lc1UVf-VE&t=60s',Transcript::videoLinks([$clip],'M7lc1UVf-VE')[0]['youtube_url']);
        foreach(['','invalid','M7lc1UVf-VE&identity=secret'] as $id){self::assertSame('',Transcript::videoLinks([$clip],$id)[0]['youtube_url']);}
    }
}
