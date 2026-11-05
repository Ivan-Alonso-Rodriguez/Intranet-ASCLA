<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Administration,Profiles,Attendance,AccountRecovery};
use ASCLA\Core\Domain\{ZoomCsv,AttendanceSessions};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Frontend\Login;

final class ProfileAttendance1110Test extends TestCase
{
    private array $users=[],$media=[];
    private int $event;
    private string $password='Synthetic-account-password-2026!';
    private string $suffix;
    private array $get,$post;
    private mixed $action,$pagenow;
    protected function setUp(): void
    {
        $this->get=$_GET;$this->post=$_POST;$this->action=$GLOBALS['action']??null;$this->pagenow=$GLOBALS['pagenow']??null;$_GET=$_POST=[];
        $this->suffix=bin2hex(random_bytes(4));
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $login='profile_attendance_'.bin2hex(random_bytes(5));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>$this->password,'role'=>$role]);
        }
        foreach ([1=>['María Alejandra','Contreras'],2=>['Pedro Enrique','Villanueva']] as $index=>$parts) {
            $parts[1].=' '.$this->suffix;
            wp_update_user(['ID'=>$this->users[$index],'first_name'=>$parts[0],'last_name'=>$parts[1],'display_name'=>implode(' ',$parts)]);
            update_user_meta($this->users[$index],'_ascla_profile',ascla_test_profile(['first_name'=>$parts[0],'last_name'=>$parts[1],'member_type'=>'independent','hidden'=>['company'],'directory'=>true]));
        }
        wp_set_current_user($this->users[0]);
        $this->event=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_author'=>$this->users[0],'post_title'=>'CSV identity test']);
        update_post_meta($this->event,'_ascla',['start'=>'2026-09-25T13:00:00Z','end'=>'2026-09-25T14:30:00Z']);
    }
    protected function tearDown(): void
    {
        wp_set_current_user($this->users[0]);
        foreach(Store::rows('imports','event_id=%d',[$this->event],'ORDER BY id') as $import) { Store::delete('import_rows',['import_id'=>$import['id']]);Store::delete('imports',['id'=>$import['id']]); }
        Store::delete('attendance',['event_id'=>$this->event]);wp_delete_post($this->event,true);
        foreach($this->media as $id) { Store::delete('media',['id'=>$id]); }
        foreach($this->users as $id) { Store::delete('audit',['actor_id'=>$id]);Store::delete('audit',['object_id'=>$id]);wp_delete_user($id); }
        wp_set_current_user(0);$_GET=$this->get;$_POST=$this->post;$GLOBALS['action']=$this->action;$GLOBALS['pagenow']=$this->pagenow;
    }
    private function api(string $route,array $body): WP_REST_Response
    {
        $r=new WP_REST_Request('POST','/ascla/v1/'.$route);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));return rest_do_request($r);
    }
    private function account(array $input=[]): array
    {
        $user=get_userdata($this->users[1]);return $input+['email'=>$user->user_email,'first_name'=>$user->first_name,'last_name'=>$user->last_name,'role'=>'ascla_member'];
    }
    private function photo(): int
    {
        $id=Store::insert('media',['user_id'=>$this->users[1],'post_id'=>0,'name'=>'portrait.png','mime'=>'image/png','bytes'=>'synthetic-image-bytes','created_at'=>current_time('mysql',true)]);$this->media[]=$id;
        $profile=get_user_meta($this->users[1],'_ascla_profile',true);$profile['photo_id']=$id;update_user_meta($this->users[1],'_ascla_profile',$profile);return $id;
    }
    public function testOwnerAndAuthorizedAdministratorCanRestoreAvatarWithoutDeletingSharedBytes(): void
    {
        $file=$this->photo();wp_set_current_user($this->users[1]);
        self::assertNotSame('',Profiles::visible($this->users[1])['photo_url']);
        self::assertSame(200,$this->api('profiles/me',['photo_id'=>0])->get_status());
        self::assertSame('',Profiles::visible($this->users[1])['photo_url']);self::assertNotNull(Store::one('media',$file));
        $this->photo();wp_set_current_user($this->users[2]);
        self::assertSame(403,$this->api('admin/users/'.$this->users[1],$this->account(['photo_id'=>0]))->get_status());
        wp_set_current_user($this->users[0]);$before=Profiles::raw($this->users[1]);
        self::assertSame(200,$this->api('admin/users/'.$this->users[1],$this->account(['photo_id'=>0]))->get_status());
        $after=Profiles::raw($this->users[1]);self::assertSame(0,$after['photo_id']);self::assertSame($before['hidden'],$after['hidden']);
        self::assertSame(400,$this->api('admin/users/'.$this->users[1],$this->account(['photo_id'=>$file]))->get_status());
    }
    public function testRenamingKeepsIdentityHistoryAndPasswordAndInvalidatesTheOldLoginCache(): void
    {
        $id=$this->users[1];$old=get_userdata($id)->user_login;$new='renamed_'.bin2hex(random_bytes(5));get_user_by('login',$old);
        $response=$this->api('admin/users/'.$id,$this->account(['login'=>$new]));self::assertSame(200,$response->get_status());self::assertSame($new,$response->get_data()['login']);
        self::assertFalse(get_user_by('login',$old));self::assertSame($id,(int)get_user_by('login',$new)->ID);
        self::assertSame($id,(int)wp_authenticate($new,$this->password)->ID);self::assertTrue(is_wp_error(wp_authenticate($old,$this->password)));
        self::assertSame('María Alejandra',Profiles::raw($id)['first_name']);
        self::assertSame(1,Store::count('audit','object_id=%d AND action=%s',[$id,'member_login_updated']));
    }
    public function testRenameRejectsDuplicateInvalidAndUnauthorizedChangesBeforeWriting(): void
    {
        $id=$this->users[1];$old=get_userdata($id)->user_login;
        foreach (['bad name'=>400,''=>400,get_userdata($this->users[2])->user_login=>409] as $login=>$status) {
            self::assertSame($status,$this->api('admin/users/'.$id,$this->account(['login'=>$login]))->get_status());self::assertSame($old,get_userdata($id)->user_login);
        }
        wp_set_current_user($this->users[2]);self::assertSame(403,$this->api('admin/users/'.$id,$this->account(['login'=>'unauthorized']))->get_status());
        wp_set_current_user($this->users[0]);self::assertSame(400,$this->api('admin/users/'.$this->users[0],$this->account(['login'=>'selfrename']))->get_status());
    }
    public function testCsvCanUseExactEmailNormalizedNameOrOneClearTypoAndGroupsReconnects(): void
    {
        $email=get_userdata($this->users[1])->user_email;
        $csv="Nombre (nombre original),Correo electrónico,Hora de entrada,Hora de salida,Duración (minutos)\n".
             "Contreras María Alejandra $this->suffix,,09/25/2026 08:00:00 AM,09/25/2026 08:40:00 AM,40\n".
             "Maria Alejandra Contreraz $this->suffix,,09/25/2026 08:30:00 AM,09/25/2026 09:30:00 AM,60\n".
             "Pedro Enrique Villanueva,$email,09/25/2026 08:30:00 AM,09/25/2026 09:30:00 AM,60\n";
        $preview=Attendance::preview($this->event,$csv,['date_format'=>'auto','timezone'=>'America/Lima']);
        self::assertSame(1,$preview['importable']);self::assertCount(0,$preview['unmatched']);$person=$preview['matched'][0];self::assertSame($this->users[1],$person['id']);
        self::assertSame(['name','similar_name','email'],$person['identity_methods']);self::assertSame('present',$person['status']);self::assertSame(5400,$person['seconds']);self::assertSame(3,$person['connections']);
        self::assertSame(0,Store::count('attendance','event_id=%d',[$this->event]));
        self::assertTrue(Attendance::import($this->event,$csv,$preview['token'],['date_format'=>'auto','timezone'=>'America/Lima'])['complete']);
        self::assertSame(1,Store::count('attendance','event_id=%d',[$this->event]));
    }
    public function testAmbiguousNamesAndUnknownDevicesRemainUnassociatedAndNeverCreateAccounts(): void
    {
        wp_update_user(['ID'=>$this->users[2],'display_name'=>'María Alejandra Contreras '.$this->suffix]);
        $count=count_users()['total_users'];$csv="Name,Duration\nMaria Alejandra Contreras $this->suffix,80\niPhone,80\nMaria,80\n";
        $preview=Attendance::preview($this->event,$csv);self::assertSame(0,$preview['importable']);self::assertCount(3,$preview['unmatched']);
        self::assertSame($count,count_users()['total_users']);self::assertSame(0,Store::count('attendance','event_id=%d',[$this->event]));
    }
    public function testAutomaticDateDetectionDoesNotGuessAmbiguousDates(): void
    {
        $row=ZoomCsv::parse("Name,Join Time,Leave Time,Duration\nMaria,09/10/2026 08:00:00 AM,09/10/2026 09:00:00 AM,60\n")[0];
        $result=AttendanceSessions::calculate([$row],strtotime('2026-09-10T13:00:00Z'),strtotime('2026-09-10T14:00:00Z'),70,['date_format'=>'auto','timezone'=>'America/Lima']);
        self::assertSame('review',$result['status']);self::assertNull($result['seconds']);
    }
    public function testRecoveryReturnTracksCommunityOriginWhileTechnicalLoginKeepsItsDestination(): void
    {
        $GLOBALS['pagenow']='wp-login.php';$GLOBALS['action']='lostpassword';
        $native=site_url('wp-login.php','login');self::assertSame($native,wp_login_url());
        $link=wp_lostpassword_url(Login::url());parse_str(wp_parse_url($link,PHP_URL_QUERY),$_GET);
        self::assertSame('community',$_GET['ascla_login_context']);self::assertSame(Login::url(),wp_login_url());
        parse_str(wp_parse_url(AccountRecovery::confirmationUrl(),PHP_URL_QUERY),$query);self::assertSame('community',$query['ascla_login_context']);
        ob_start();do_action('lostpassword_form');$hidden=ob_get_clean();self::assertStringContainsString('ascla_login_context',$hidden);
        $reset=network_site_url('wp-login.php?action=rp&key=SYNTHETIC&login=member','login');
        self::assertStringContainsString('ascla_login_context=community',apply_filters('retrieve_password_message',$reset,'SYNTHETIC','member',get_userdata($this->users[1])));
        $_GET=[];self::assertSame($native,wp_login_url());self::assertSame($reset,apply_filters('retrieve_password_message',$reset,'SYNTHETIC','member',get_userdata($this->users[1])));
    }
}
