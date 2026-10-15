<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{InterestImports,Attendance,Profiles};
use ASCLA\Core\Repositories\{Store,ImportRecords,Transaction,RepositoryException};

final class ImportAtomicityTest extends TestCase
{
    private array $users=[],$posts=[];
    private int $topic;
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member','ascla_member'] as $role) {
            $login='atomic_import_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        foreach (array_slice($this->users,1) as $id) { wp_set_current_user($id);Profiles::save(ascla_test_profile()); }
        wp_set_current_user($this->users[0]);$term=wp_insert_term('Interés importado '.bin2hex(random_bytes(6)),'ascla_interest');$this->topic=(int)$term['term_id'];
    }
    protected function tearDown(): void
    {
        foreach (Store::rows('imports','actor_id=%d',[$this->users[0]],'ORDER BY id ASC') as $import) {
            Store::delete('import_rows',['import_id'=>$import['id']]);Store::delete('imports',['id'=>$import['id']]);
        }
        foreach ($this->posts as $id) { Store::delete('attendance',['event_id'=>$id]);wp_delete_post($id,true); }
        foreach ($this->users as $id) {
            foreach (['jobs','notifications','audit'] as $table) { Store::delete($table,[$table==='audit'?'actor_id':'user_id'=>$id]); }
            wp_delete_user($id);
        }
        wp_delete_term($this->topic,'ascla_interest');wp_set_current_user(0);
    }
    private function failQuery(callable $match,callable $operation,int $at=1): void
    {
        global $wpdb;$count=0;$previous=$wpdb->suppress_errors(true);
        $fail=static function($sql)use($match,$at,&$count) {
            if ($match($sql) && ++$count===$at) { return 'INSERT INTO ascla_nonexistent_atomic_test (value) VALUES (1)'; }
            return $sql;
        };
        add_filter('query',$fail);
        try { $operation();self::fail('Expected a failed database write'); }
        catch (RepositoryException) { self::assertSame($at,$count); }
        finally { remove_filter('query',$fail);$wpdb->suppress_errors($previous);$wpdb->last_error=''; }
    }
    private function interests(): int
    {
        $csv="Email,Code,Checkbox,Free\n";
        foreach (array_slice($this->users,1) as $id) { $csv.=get_userdata($id)->user_email.",,,\n"; }
        $import=InterestImports::start(['csv'=>$csv,'filename'=>'forms.csv','columns'=>['email'=>0,'code'=>1,'structured'=>2,'free'=>3],'mode'=>'merge']);
        $id=(int)$import['id'];$result=InterestImports::process($id);
        foreach ($result['rows'] as $row) { InterestImports::review($id,$row['id'],['decision'=>'accept','topics'=>[$this->topic],'acknowledge'=>true]); }
        InterestImports::confirm($id);return $id;
    }
    private function event(): int
    {
        $start=time()-2*DAY_IN_SECONDS;
        $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'Evento de importación','post_author'=>$this->users[0]]);
        $this->posts[]=$id;update_post_meta($id,'_ascla',['chatham'=>false,'start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600)]);return $id;
    }
    private function attendanceImport(int $event): int
    {
        $rows=[];
        foreach (array_slice($this->users,1) as $id) {
            $rows[]=['id'=>$id,'kind'=>'matched','protected'=>false,'snapshot'=>hash('sha256',wp_json_encode(null)),'status'=>'present','minutes'=>50,'seconds'=>3000,'sessions'=>[],'review_reason'=>''];
        }
        $id=ImportRecords::create('attendance','zoom.csv',['threshold'=>70,'window'=>get_post_meta($event,'_ascla_end',true).'|'.get_post_meta($event,'_ascla_start',true)],$rows,$event);
        Store::update('imports',['status'=>'applying'],['id'=>$id]);return $id;
    }
    public function testNestedImportCreationNeverCommitsItsOuterTransaction(): void
    {
        $before=Store::count('imports','actor_id=%d',[$this->users[0]]);
        $rowsBefore=Store::count('import_rows','1=%d',[1]);
        try {
            Transaction::run(static function() {
                ImportRecords::create('interests','nested.csv',[],[['email'=>'example@example.invalid']]);
                throw new RuntimeException('Rollback the whole operation');
            });
            self::fail('Expected outer rollback');
        } catch (RuntimeException) { self::assertSame($before,Store::count('imports','actor_id=%d',[$this->users[0]])); }
        self::assertSame($rowsBefore,Store::count('import_rows','1=%d',[1]));
    }
    public function testFailedImportCreationLeavesNoHeaderOrPartialChunks(): void
    {
        $before=Store::count('imports','actor_id=%d',[$this->users[0]]);$rowsBefore=Store::count('import_rows','1=%d',[1]);
        $rows=array_fill(0,101,['email'=>'example@example.invalid']);
        $this->failQuery(static fn($sql)=>str_starts_with($sql,'INSERT INTO '.Store::table('import_rows').' '),fn()=>ImportRecords::create('interests','chunks.csv',[],$rows),2);
        self::assertSame($before,Store::count('imports','actor_id=%d',[$this->users[0]]));self::assertSame($rowsBefore,Store::count('import_rows','1=%d',[1]));
    }
    public function testLateInterestFailureRollsBackEveryProfileSourceIndexAndImportState(): void
    {
        $id=$this->interests();$profiles=[];$sources=[];$indexes=[];
        foreach (array_slice($this->users,1) as $user) {
            $profiles[$user]=get_user_meta($user,'_ascla_profile',true);$sources[$user]=get_user_meta($user,'_ascla_forms_interests',true);$indexes[$user]=Store::rows('user_interests','user_id=%d',[$user],'ORDER BY id ASC');
        }
        $revision=(int)get_option('ascla_profile_revision',0);$state=Store::one('imports',$id);
        $this->failQuery(static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.Store::table('audit').'`') && str_contains($sql,"'interests_import_applied'"),fn()=>InterestImports::apply($id));
        self::assertSame($state,Store::one('imports',$id));self::assertSame(['approved'=>2],ImportRecords::counts($id));
        foreach (array_slice($this->users,1) as $user) {
            self::assertSame($profiles[$user],get_user_meta($user,'_ascla_profile',true));self::assertSame($sources[$user],get_user_meta($user,'_ascla_forms_interests',true));self::assertSame($indexes[$user],Store::rows('user_interests','user_id=%d',[$user],'ORDER BY id ASC'));
        }
        self::assertSame($revision,(int)get_option('ascla_profile_revision',0));
        $done=InterestImports::apply($id);self::assertSame('complete',$done['status']);self::assertSame(2,$done['counts']['updated']);
        foreach (array_slice($this->users,1) as $user) { self::assertContains($this->topic,get_user_meta($user,'_ascla_profile',true)['interests']); }
    }
    public function testEveryProfileLockIsHeldUntilTheBatchCommit(): void
    {
        global $wpdb;$id=$this->interests();$original=$wpdb;$competitor=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$competitor->set_prefix($wpdb->prefix);$probes=0;
        $probe=function($sql)use($original,$competitor,&$probes) {
            if (!str_starts_with($sql,'INSERT INTO `'.Store::table('audit').'`') || !str_contains($sql,"'interests_import_applied'")) { return $sql; }
            global $wpdb;$wpdb=$competitor;
            try {
                foreach (array_slice($this->users,1) as $user) {
                    try { Store::lock('profile-interests:'.$user,static fn()=>true,0);self::fail('Concurrent profile writer entered an uncommitted batch'); }
                    catch (RepositoryException) { ++$probes; }
                }
            } finally { $wpdb=$original; }
            return $sql;
        };
        add_filter('query',$probe);
        try { self::assertSame('complete',InterestImports::apply($id)['status']); }
        finally { remove_filter('query',$probe);$wpdb=$original; }
        self::assertSame(2,$probes);
        $wpdb=$competitor;
        try { foreach (array_slice($this->users,1) as $user) { self::assertTrue(Store::lock('profile-interests:'.$user,static fn()=>true,0)); } }
        finally { $wpdb=$original;$competitor->close(); }
    }
    public function testAttendanceRowsMetadataAndFinalAuditRollBackTogether(): void
    {
        $event=$this->event();$id=$this->attendanceImport($event);Attendance::complete($event,true);
        $complete=get_post_meta($event,'_ascla_attendance_complete',true);$state=Store::one('imports',$id);
        $this->failQuery(static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.Store::table('audit').'`') && str_contains($sql,"'attendance_imported'"),fn()=>Attendance::apply($event,$id));
        self::assertSame($complete,get_post_meta($event,'_ascla_attendance_complete',true));self::assertSame($state,Store::one('imports',$id));self::assertSame(['pending'=>2],ImportRecords::counts($id));self::assertSame(0,Store::count('attendance','event_id=%d',[$event]));
        $done=Attendance::apply($event,$id);self::assertTrue($done['complete']);self::assertSame(2,$done['imported']);self::assertSame(2,Store::count('attendance','event_id=%d',[$event]));self::assertFalse(Attendance::roster($event)['complete']);
        self::assertSame(2,Attendance::apply($event,$id)['imported']);
    }
    public function testThresholdRecalculationFailureRestoresSettingsAndAttendance(): void
    {
        $event=$this->event();$id=$this->attendanceImport($event);Attendance::apply($event,$id);Attendance::complete($event,true);
        $rows=Store::rows('attendance','event_id=%d',[$event],'ORDER BY id ASC');$meta=get_post_meta($event,'_ascla_attendance_complete',true);$threshold=get_post_meta($event,'_ascla_attendance_threshold',true);
        $this->failQuery(static fn($sql)=>str_starts_with($sql,'UPDATE '.Store::table('attendance').' SET status=CASE'),fn()=>Attendance::configure($event,['threshold'=>95]));
        self::assertSame($threshold,get_post_meta($event,'_ascla_attendance_threshold',true));self::assertSame($meta,get_post_meta($event,'_ascla_attendance_complete',true));self::assertSame($rows,Store::rows('attendance','event_id=%d',[$event],'ORDER BY id ASC'));
        Attendance::configure($event,['threshold'=>95]);self::assertSame('partial',Store::rows('attendance','event_id=%d',[$event])[0]['status']);
    }
}
