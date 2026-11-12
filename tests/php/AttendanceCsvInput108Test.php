<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\Attendance;
use ASCLA\Core\Repositories\Store;

final class AttendanceCsvInput108Test extends TestCase
{
    private array $users=[];
    private int $event;
    private string $email;
    protected function setUp(): void
    {
        add_filter('pre_wp_mail','__return_true');
        foreach(['administrator','ascla_member'] as $role){$name='zoominput108_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);}
        wp_set_current_user($this->users[0]);$this->email=get_userdata($this->users[1])->user_email;
        $this->event=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_author'=>$this->users[0],'post_title'=>'Validación de CSV de asistencia']);
        update_post_meta($this->event,'_ascla',['start'=>'2026-03-02T12:00:00Z','end'=>'2026-03-02T13:00:00Z']);
        Store::insert('attendance',['event_id'=>$this->event,'user_id'=>$this->users[1],'status'=>'partial','minutes'=>10,'seconds'=>600,'source'=>'zoom','recorded_by'=>$this->users[0],'updated_at'=>current_time('mysql',true)]);
        Attendance::complete($this->event,true);
    }
    protected function tearDown(): void
    {
        foreach(Store::rows('imports','event_id=%d',[$this->event],'ORDER BY id') as $i){Store::delete('import_rows',['import_id'=>$i['id']]);Store::delete('imports',['id'=>$i['id']]);}
        Store::delete('attendance',['event_id'=>$this->event]);Store::delete('audit',['object_id'=>$this->event]);wp_delete_post($this->event,true);
        foreach($this->users as $uid){Store::delete('audit',['actor_id'=>$uid]);wp_delete_user($uid);}remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    private function api(string $action,array $input): WP_REST_Response
    {
        $r=new WP_REST_Request('POST','/ascla/v1/admin/attendance/'.$this->event.'/'.$action);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($input));return rest_get_server()->dispatch($r);
    }
    private function snapshot(): array
    {
        return [Store::rows('attendance','event_id=%d',[$this->event],'ORDER BY id'),get_post_meta($this->event,'_ascla_attendance_complete',true),Store::rows('imports','event_id=%d',[$this->event],'ORDER BY id'),Store::rows('audit','object_id=%d',[$this->event],'ORDER BY id')];
    }
    public function testPreviewAndConfirmationRejectBadStructureWithoutChangingCompletedRoster(): void
    {
        $valid="Name,Email,Duration\nPersona,$this->email,20\n";$token=Attendance::preview($this->event,$valid)['token'];$before=$this->snapshot();
        foreach([$valid."Otra,$this->email,20,Extra\n","Name,Email,User Email,Duration\nPersona,$this->email,other@example.invalid,20\n"] as $csv){
            foreach(['preview','import'] as $action){$r=$this->api($action,['csv'=>$csv,'token'=>$token]);self::assertSame(400,$r->get_status());self::assertSame($before,$this->snapshot());}
        }
    }
    public function testBadDurationDoesNotHideLaterValidEvidenceForTheSameMember(): void
    {
        foreach([['bad','20'],['20','bad']] as $durations){
            $csv="Name,Email,Duration\n";foreach($durations as $d)$csv.="Persona,$this->email,$d\n";
            $before=$this->snapshot();$p=Attendance::preview($this->event,$csv);self::assertSame(1,$p['importable']);self::assertCount(1,$p['unmatched']);self::assertSame(20,$p['matched'][0]['minutes']);self::assertSame($before,$this->snapshot());
            $result=Attendance::import($this->event,$csv,$p['token']);self::assertTrue($result['complete']);self::assertSame(1,$result['imported']);self::assertSame(1,$result['unmatched']);
            $row=Store::rows('attendance','event_id=%d',[$this->event],'LIMIT 1')[0];self::assertSame(1200,(int)$row['seconds']);self::assertSame('partial',$row['status']);
        }
    }
    public function testImpossibleIsoDateRemainsReviewableAndCorrectionCanBeImported(): void
    {
        $csv="Email,Join Time,Leave Time,Duration\n$this->email,2026-02-30T12:00:00Z,2026-02-30T13:00:00Z,60\n";$before=$this->snapshot();
        $p=Attendance::preview($this->event,$csv);self::assertSame('review',$p['matched'][0]['status']);self::assertNull($p['matched'][0]['seconds']);self::assertSame($before,$this->snapshot());
        $result=Attendance::import($this->event,$csv,$p['token']);self::assertTrue($result['complete']);$row=Store::rows('attendance','event_id=%d',[$this->event],'LIMIT 1')[0];self::assertSame('review',$row['status']);self::assertNull($row['seconds']);self::assertNotEmpty($row['review_reason']);
        $csv=str_replace('2026-02-30','2026-03-02',$csv);$p=Attendance::preview($this->event,$csv);Attendance::import($this->event,$csv,$p['token']);
        $row=Store::rows('attendance','event_id=%d',[$this->event],'LIMIT 1')[0];self::assertSame('present',$row['status']);self::assertSame(3600,(int)$row['seconds']);
    }
    public function testColumnErrorReportsTheRowInTheAdministratorsLanguage(): void
    {
        update_user_meta($this->users[0],'locale','en_US');
        $csv="Email,Duration\n$this->email,20\n$this->email,30,extra\n";
        $r=$this->api('preview',['csv'=>$csv]);self::assertSame(400,$r->get_status());self::assertSame('CSV row 3 does not match the header columns.',$r->get_data()['message']);
    }
}
