<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\Attendance;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Rest\ApiException;

final class AttendanceValidation108Test extends TestCase
{
    private array $users=[];
    private int $event,$member;
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member'] as $role) {
            $name='attendance108_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(28),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);$this->member=$this->users[1];add_filter('pre_wp_mail','__return_true');
        $this->event=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_author'=>$this->users[0],'post_title'=>'Attendance validation']);
        update_post_meta($this->event,'_ascla',['start'=>gmdate('c',time()-7200),'end'=>gmdate('c',time()-3600)]);
        Attendance::save($this->event,['user_id'=>$this->member,'status'=>'partial','minutes'=>40]);
        update_post_meta($this->event,'_ascla_attendance_threshold',80);Attendance::complete($this->event,true);
    }
    protected function tearDown(): void
    {
        Store::delete('attendance',['event_id'=>$this->event]);wp_delete_post($this->event,true);
        foreach ($this->users as $id)wp_delete_user($id);
        remove_filter('pre_wp_mail','__return_true');wp_set_current_user(0);
    }
    private function api(string $action,array $input): WP_REST_Response
    {
        $r=new WP_REST_Request('POST','/ascla/v1/admin/attendance/'.$this->event.$action);$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($input));return rest_get_server()->dispatch($r);
    }
    private function snapshot(): array
    {
        return [Store::rows('attendance','event_id=%d',[$this->event],'ORDER BY id'),get_post_meta($this->event,'_ascla_attendance_complete',true),get_post_meta($this->event,'_ascla_attendance_threshold',true)];
    }
    private function rejected(callable $action): void
    {
        try{$action();self::fail('Invalid attendance input must be rejected.');}catch(ApiException $e){self::assertSame(400,$e->getCode());}
    }
    public static function invalidMinutes(): array{return [[true],[false],[1.5],['12x'],[-1],[61],[[]]];}
    #[DataProvider('invalidMinutes')]
    public function testInvalidMinutesPreserveAttendanceAndCompletedRoster(mixed $value): void
    {
        $before=$this->snapshot();$input=['user_id'=>$this->member,'status'=>'present','minutes'=>$value];
        self::assertSame(400,$this->api('',$input)->get_status());self::assertSame($before,$this->snapshot());
        $this->rejected(fn()=>Attendance::save($this->event,$input));self::assertSame($before,$this->snapshot());
    }
    public static function invalidMembers(): array{return [[true],[false],[1.5],['suffix'],['negative'],[[]]];}
    #[DataProvider('invalidMembers')]
    public function testInvalidMemberIdsCannotModifyAnotherMember(mixed $value): void
    {
        if ($value==='suffix')$value=$this->member.'suffix';
        if ($value==='negative')$value=-$this->member;
        $before=$this->snapshot();$input=['user_id'=>$value,'email'=>get_userdata($this->member)->user_email,'status'=>'absent','minutes'=>0];
        self::assertSame(400,$this->api('',$input)->get_status());$this->rejected(fn()=>Attendance::save($this->event,$input));self::assertSame($before,$this->snapshot());
    }
    public static function invalidThresholds(): array{return [[true],[false],[1.5],['80%'],[0],[101],[[]]];}
    #[DataProvider('invalidThresholds')]
    public function testInvalidThresholdPreservesClassificationAndCompletion(mixed $value): void
    {
        $before=$this->snapshot();self::assertSame(400,$this->api('/settings',['threshold'=>$value])->get_status());
        $this->rejected(fn()=>Attendance::configure($this->event,['threshold'=>$value]));self::assertSame($before,$this->snapshot());
    }
    public static function invalidOptions(): array{return [['timezone',[]],['timezone',true],['timezone','Invalid/Zone'],['date_format',[]],['date_format',true],['date_format','ambiguous']];}
    #[DataProvider('invalidOptions')]
    public function testImportOptionsFailWithValidationErrorWithoutWriting(string $key,mixed $value): void
    {
        $before=$this->snapshot();$csv="Name,Email,Duration (Minutes)\nMember,".get_userdata($this->member)->user_email.",20\n";
        self::assertSame(400,$this->api('/preview',['csv'=>$csv,$key=>$value])->get_status());
        $this->rejected(fn()=>Attendance::preview($this->event,$csv,[$key=>$value]));self::assertSame($before,$this->snapshot());
    }
    public function testCanonicalValuesOptionalMinutesAndBoundaryValuesRemainSupported(): void
    {
        self::assertSame(200,$this->api('',['user_id'=>(string)$this->member,'status'=>'present','minutes'=>'60'])->get_status());
        self::assertSame('60',Store::rows('attendance','event_id=%d',[$this->event],'LIMIT 1')[0]['minutes']);
        self::assertFalse((bool)get_post_meta($this->event,'_ascla_attendance_complete',true));
        self::assertSame(200,$this->api('',['user_id'=>0,'email'=>get_userdata($this->member)->user_email,'status'=>'review','minutes'=>''])->get_status());
        self::assertNull(Store::rows('attendance','event_id=%d',[$this->event],'LIMIT 1')[0]['minutes']);
        foreach ([1,100,'80'] as $threshold)self::assertSame(200,$this->api('/settings',['threshold'=>$threshold])->get_status());
    }
}
