<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Repositories\Store;

final class AdminUserManagementTest extends TestCase
{
    private int $admin;
    private int $member;
    private int $otherAdmin;
    private array $posts=[];
    private array $createdUsers=[];

    protected function setUp(): void
    {
        $suffix=bin2hex(random_bytes(5));
        $this->admin=wp_insert_user(['user_login'=>'admin_'.$suffix,'user_email'=>'admin_'.$suffix.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'administrator']);
        $this->member=wp_insert_user(['user_login'=>'member_'.$suffix,'user_email'=>'member_'.$suffix.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'ascla_member']);
        $this->otherAdmin=wp_insert_user(['user_login'=>'other_admin_'.$suffix,'user_email'=>'other_admin_'.$suffix.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'administrator']);
        update_user_meta($this->member,'_ascla_profile',['first_name'=>'Ana','last_name'=>'Demo','position'=>'Analista','company'=>'ASCLA','member_type'=>'Asociado','directory'=>true]);
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->admin);
        foreach($this->posts as $id) if(get_post($id)) wp_delete_post($id,true);
        foreach(array_merge($this->createdUsers,[$this->member,$this->otherAdmin,$this->admin]) as $id) if(get_userdata($id)) wp_delete_user($id);
        wp_set_current_user(0);
    }

    private function api(string $method,string $route,array $body=[]): WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$route);
        if($method!=='GET') {
            $r->set_header('Content-Type','application/json');
            if($body) $r->set_body(wp_json_encode($body));
        }
        return rest_do_request($r);
    }

    public function testAdministratorCreatesCommunityUserInsideAscla(): void
    {
        wp_set_current_user($this->admin);
        $suffix=bin2hex(random_bytes(5));
        $response=$this->api('POST','admin/users',[
            'login'=>'new_member_'.$suffix,
            'email'=>'new_member_'.$suffix.'@example.invalid',
            'role'=>'ascla_member',
            'first_name'=>'María',
            'last_name'=>'Pérez',
            'position'=>'Especialista',
            'company'=>'ASCLA Demo',
            'member_type'=>'Asociado titular',
            'send_invite'=>false,
        ]);
        self::assertSame(200,$response->get_status());
        $data=$response->get_data();
        $id=(int)$data['id'];
        $this->createdUsers[]=$id;
        $user=get_userdata($id);
        self::assertNotFalse($user);
        self::assertSame('new_member_'.$suffix,$user->user_login);
        self::assertContains('ascla_member',$user->roles);
        $profile=(array)get_user_meta($id,'_ascla_profile',true);
        self::assertSame('María',$profile['first_name']);
        self::assertSame('Especialista',$profile['position']);
        self::assertTrue((bool)$profile['directory']);
        self::assertFalse((bool)$data['invite_requested']);
    }

    public function testCreateUserRejectsDuplicateEmailAndTechnicalRole(): void
    {
        wp_set_current_user($this->admin);
        $suffix=bin2hex(random_bytes(5));
        $duplicate=$this->api('POST','admin/users',[
            'login'=>'duplicate_'.$suffix,
            'email'=>get_userdata($this->member)->user_email,
            'role'=>'ascla_member',
            'first_name'=>'Duplicado',
            'last_name'=>'Correo',
            'send_invite'=>false,
        ]);
        self::assertSame(409,$duplicate->get_status());

        $technical=$this->api('POST','admin/users',[
            'login'=>'technical_'.$suffix,
            'email'=>'technical_'.$suffix.'@example.invalid',
            'role'=>'administrator',
            'first_name'=>'Admin',
            'last_name'=>'No permitido',
            'send_invite'=>false,
        ]);
        self::assertSame(400,$technical->get_status());
        self::assertFalse(username_exists('technical_'.$suffix));
    }

    public function testAdministratorEditsCommunityUserInsideAscla(): void
    {
        wp_set_current_user($this->admin);
        $detail=$this->api('GET','admin/users/'.$this->member);
        self::assertSame(200,$detail->get_status());
        self::assertSame('member_',$prefix=substr($detail->get_data()['login'],0,7));

        $newEmail='updated_'.bin2hex(random_bytes(4)).'@example.invalid';
        $response=$this->api('POST','admin/users/'.$this->member,[
            'email'=>$newEmail,
            'role'=>'ascla_executive',
            'first_name'=>'Ana María',
            'last_name'=>'Actualizada',
            'position'=>'Gerente',
            'company'=>'ASCLA Perú',
            'member_type'=>'Asociado titular',
        ]);
        self::assertSame(200,$response->get_status());
        $user=get_userdata($this->member);
        self::assertSame($newEmail,$user->user_email);
        self::assertContains('ascla_executive',$user->roles);
        $profile=(array)get_user_meta($this->member,'_ascla_profile',true);
        self::assertSame('Ana María',$profile['first_name']);
        self::assertSame('Gerente',$profile['position']);
    }

    public function testOnlyAdministratorCanUseUserManagementEndpoints(): void
    {
        wp_set_current_user($this->member);
        self::assertSame(403,$this->api('GET','admin/users/'.$this->member)->get_status());
        self::assertSame(403,$this->api('POST','admin/users',[
            'login'=>'forbidden_'.bin2hex(random_bytes(4)),
            'email'=>'forbidden_'.bin2hex(random_bytes(4)).'@example.invalid',
            'role'=>'ascla_member',
            'first_name'=>'No',
            'last_name'=>'Permitido',
            'send_invite'=>false,
        ])->get_status());
        self::assertSame(403,$this->api('DELETE','admin/users/'.$this->member)->get_status());
    }

    public function testTechnicalAdministratorsCannotBeEditedOrDeletedFromAsclaWorkspace(): void
    {
        wp_set_current_user($this->admin);
        self::assertSame(403,$this->api('GET','admin/users/'.$this->otherAdmin)->get_status());
        self::assertSame(403,$this->api('DELETE','admin/users/'.$this->otherAdmin)->get_status());
    }

    public function testDeletingMemberPreservesPublishedContentAndCleansAccessData(): void
    {
        wp_set_current_user($this->admin);
        $post=wp_insert_post(['post_type'=>'ascla_hub','post_title'=>'Contenido del asociado','post_content'=>'Prueba','post_status'=>'publish','post_author'=>$this->member]);
        $this->posts[]=$post;
        Store::insert('relations',['user_id'=>$this->member,'target_id'=>$this->admin,'kind'=>'follow','reason'=>'','detail'=>'','reviewed_at'=>null,'reviewed_by'=>0,'created_at'=>current_time('mysql',true)]);

        $response=$this->api('DELETE','admin/users/'.$this->member);
        self::assertSame(200,$response->get_status());
        self::assertFalse(get_userdata($this->member));
        self::assertSame($this->admin,(int)get_post($post)->post_author);
        self::assertSame(0,Store::count('relations','user_id=%d',[$this->member]));
    }
}
