<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Profiles,Notifications,InterestImports,InterestIndex,Attendance,Statistics};
use ASCLA\Core\Repositories\{Store,ImportRecords};
use ASCLA\Core\Domain\{InterestCatalog,AttendanceSessions};
use ASCLA\Core\Integrations\{EmailTemplate,AIProviderInterface,Mailer};

final class ReleaseCompletionTest extends TestCase
{
    private array $users=[],$terms=[],$posts=[];
    protected function setUp():void
    {
        foreach(['administrator','ascla_member','ascla_executive','ascla_moderator'] as $role){$name='completion_'.bin2hex(random_bytes(5));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);}
        wp_set_current_user($this->users[0]);$r=wp_insert_term('Revisión '.bin2hex(random_bytes(4)),'ascla_interest');$this->terms[]=(int)$r['term_id'];
    }
    protected function tearDown():void
    {
        wp_set_current_user($this->users[0]);foreach($this->posts as $id){wp_delete_post($id,true);Store::delete('registrations',['event_id'=>$id]);}
        foreach($this->users as $uid){foreach(Store::rows('imports','actor_id=%d',[$uid],'ORDER BY id') as $i){Store::delete('import_rows',['import_id'=>(int)$i['id']]);Store::delete('imports',['id'=>(int)$i['id']]);}foreach(['notifications','audit','registrations'] as $t)Store::delete($t,[$t==='audit'?'actor_id':'user_id'=>$uid]);wp_delete_user($uid);}
        foreach($this->terms as $id)wp_delete_term($id,'ascla_interest');wp_set_current_user(0);
    }
    private function rejected(int $status,callable $fn):void
    {
        try{$fn();self::fail('Expected rejection');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame($status,$e->getCode(),$e->getMessage());}
    }
    private function csv(array $rows):string
    {
        $f=fopen('php://temp','r+');fputcsv($f,['Email','Code','Checkbox','Free'],',','"','');foreach($rows as $r)fputcsv($f,$r,',','"','');rewind($f);$csv=stream_get_contents($f);fclose($f);return $csv;
    }
    private function start(array $rows,string $mode='merge'):array
    {
        return InterestImports::start(['csv'=>$this->csv($rows),'filename'=>'forms.csv','columns'=>['email'=>0,'code'=>1,'structured'=>2,'free'=>3],'mode'=>$mode]);
    }
    private function email(int $n=1):string{return get_userdata($this->users[$n])->user_email;}
    private function topicName(int $n=0):string{return get_term($this->terms[$n],'ascla_interest')->name;}
    public function testPhonePrivacyEditClearAndNoAiExposure():void
    {
        $uid=$this->users[1];wp_set_current_user($uid);Profiles::save(['phone'=>'+51 999 123 456']);self::assertSame('+51999123456',Profiles::visible($uid)['phone']);self::assertArrayNotHasKey('phone',Profiles::matchingProfile($uid));self::assertStringNotContainsString('999',wp_json_encode(Profiles::networkingContext($uid)));
        foreach([2,3] as $i){wp_set_current_user($this->users[$i]);self::assertArrayNotHasKey('phone',Profiles::visible($uid));$this->rejected(403,fn()=>Profiles::save(['phone'=>'+51 900 000 000'],$uid));}
        wp_set_current_user($uid);Profiles::save(['phone_visibility'=>'members']);wp_set_current_user($this->users[2]);self::assertSame('+51999123456',Profiles::visible($uid)['phone']);
        wp_set_current_user($this->users[0]);Profiles::save(['phone'=>'','phone_visibility'=>'private'],$uid);self::assertSame('',Profiles::visible($uid)['phone']);
        foreach([51999123456,'999123456','0051999123456','+0123456789','+1234567','+1234567890123456','<script>1234567</script>',[]] as $value)$this->rejected(400,fn()=>Profiles::save(['phone'=>$value],$uid));
    }
    public function testHtmlEmailEscapesValuesAndSupportOptOut():void
    {
        $html=EmailTemplate::render('Solicitud <script>','<img src=x>','Texto <b>privado</b>','Abrir','javascript:alert(1)');self::assertStringContainsString('<table',$html);self::assertStringNotContainsString('<script>',$html);self::assertStringNotContainsString('href="javascript:',$html);self::assertStringContainsString('&lt;img',$html);
        $captured=[];$hook=static function($return,$mail)use(&$captured){$captured[]=$mail;return true;};add_filter('pre_wp_mail',$hook,10,2);
        try{Notifications::send($this->users[1],'support_received','CONFIDENTIAL BODY',home_url('/intranet/'));self::assertCount(1,$captured);self::assertStringContainsString('Content-Type: text/html',implode(' ',(array)$captured[0]['headers']));self::assertStringNotContainsString('CONFIDENTIAL BODY',$captured[0]['message']);Notifications::saveEmailPreferences($this->users[1],['support'=>false]);Notifications::send($this->users[1],'support_update','Detalle');self::assertCount(1,$captured);wp_mail($this->email(),'Native','Plain native message');self::assertSame('Plain native message',$captured[1]['message']);}finally{remove_filter('pre_wp_mail',$hook,10);}
    }
    public function testMailTransportTestUsesInstitutionalHtmlTemplate():void
    {
        $captured=[];$hook=static function($return,$mail)use(&$captured){$captured[]=$mail;return true;};add_filter('pre_wp_mail',$hook,10,2);
        try{
            $result=Mailer::test();self::assertCount(1,$captured);$mail=$captured[0];
            self::assertSame('ASCLA · prueba de correo',$mail['subject']);
            self::assertStringContainsString('Content-Type: text/html; charset=UTF-8',implode(' ',(array)$mail['headers']));
            self::assertStringContainsString('<!doctype html>',$mail['message']);
            self::assertStringContainsString('ascla-logo.png',$mail['message']);
            self::assertStringContainsString('Ir a ASCLA',$mail['message']);
            self::assertStringContainsString('Comunidad profesional',$mail['message']);
            self::assertNotEmpty($result['message']);
        }finally{remove_filter('pre_wp_mail',$hook,10);}
    }
    public function testFormsRequireReviewAndApplyOnlySelectedInterests():void
    {
        update_user_meta($this->users[1],'_ascla_profile',['phone'=>'+51999123456','bio'=>'Keep biography','interests'=>[]]);$i=$this->start([[$this->email(),'',$this->topicName(),'']]);self::assertSame([],get_user_meta($this->users[1],'_ascla_profile',true)['interests']);$i=InterestImports::process((int)$i['id']);$row=$i['rows'][0];self::assertSame([$this->terms[0]],$row['payload']['topics']);$this->rejected(409,fn()=>InterestImports::confirm((int)$i['id']));
        InterestImports::review((int)$i['id'],$row['id'],['decision'=>'accept','topics'=>[$this->terms[0]]]);InterestImports::confirm((int)$i['id']);$done=InterestImports::apply((int)$i['id']);self::assertSame('complete',$done['status']);self::assertSame(1,$done['summary']['updated']);$p=get_user_meta($this->users[1],'_ascla_profile',true);self::assertSame('Keep biography',$p['bio']);self::assertSame('+51999123456',$p['phone']);self::assertSame([$this->terms[0]],$p['interests']);self::assertSame(2,Store::count('user_interests','user_id=%d AND term_id=%d',[$this->users[1],$this->terms[0]]));
    }
    public function testFormsStrictlyLimitApprovedTopicsToThree():void
    {
        for($n=1;$n<=3;$n++){$r=wp_insert_term('Límite Forms '.$n.' '.bin2hex(random_bytes(3)),'ascla_interest');$this->terms[]=(int)$r['term_id'];}
        $selected=array_slice($this->terms,0,4);$names=array_map(fn($id)=>get_term($id,'ascla_interest')->name,$selected);$i=$this->start([[$this->email(),' ',implode('; ',$names),'']]);$i=InterestImports::process((int)$i['id']);$row=$i['rows'][0];self::assertCount(4,$row['payload']['topics']);self::assertContains('La respuesta contiene más de tres opciones.',$row['payload']['warnings']);
        $this->rejected(400,fn()=>InterestImports::review((int)$i['id'],$row['id'],['decision'=>'accept','topics'=>$selected,'acknowledge'=>true]));
        InterestImports::review((int)$i['id'],$row['id'],['decision'=>'accept','topics'=>array_slice($selected,0,3),'acknowledge'=>true]);InterestImports::confirm((int)$i['id']);$done=InterestImports::apply((int)$i['id']);self::assertSame('complete',$done['status']);$forms=get_user_meta($this->users[1],'_ascla_forms_interests',true);self::assertCount(3,$forms['ids']);self::assertSame(array_slice($selected,0,3),$forms['ids']);
    }
    public function testEmptyReplacementKeepsInterestsAndConcurrentEditsRequireReview():void
    {
        update_user_meta($this->users[1],'_ascla_profile',['interests'=>[$this->terms[0]],'bio'=>'Before']);$i=$this->start([[$this->email(),'','','']],'replace');$i=InterestImports::process((int)$i['id']);InterestImports::review((int)$i['id'],$i['rows'][0]['id'],['decision'=>'accept','topics'=>[]]);InterestImports::confirm((int)$i['id']);$i=InterestImports::apply((int)$i['id']);self::assertSame(1,$i['counts']['unchanged']);self::assertSame([$this->terms[0]],get_user_meta($this->users[1],'_ascla_profile',true)['interests']);
        $i=$this->start([[$this->email(),'',$this->topicName(),'']]);$i=InterestImports::process((int)$i['id']);InterestImports::review((int)$i['id'],$i['rows'][0]['id'],['decision'=>'accept','topics'=>[$this->terms[0]]]);InterestImports::confirm((int)$i['id']);Profiles::save(['interests'=>[]],$this->users[1]);$i=InterestImports::apply((int)$i['id']);self::assertSame('review',$i['status']);self::assertSame(1,$i['counts']['conflict']);self::assertSame([],get_user_meta($this->users[1],'_ascla_profile',true)['interests']);
    }
    public function testUniqueCodePrecedenceAndAmbiguousIdentitiesNeverUseNames():void
    {
        update_user_meta($this->users[1],'_ascla_member_code','CODE-1');$i=$this->start([['','CODE-1',$this->topicName(),''],[$this->email(2),'CODE-1',$this->topicName(),''],['someone@example.invalid','',$this->topicName(),'']]);$i=InterestImports::process((int)$i['id']);self::assertSame($this->users[1],$i['rows'][0]['user_id']);self::assertSame(0,$i['rows'][1]['user_id']);self::assertSame(0,$i['rows'][2]['user_id']);self::assertSame(2,$i['summary']['not_found']);$this->rejected(400,fn()=>InterestImports::review((int)$i['id'],$i['rows'][1]['id'],['decision'=>'accept','topics'=>[$this->terms[0]],'acknowledge'=>true]));
    }
    public function testCatalogAliasesAreDeterministicAndDoNotGenerateUnknownTerms():void
    {
        $term=InterestCatalog::create('Ciberseguridad');if(!$term['existing'])$this->terms[]=$term['id'];$p=InterestCatalog::propose('CyberSecurity; Seguridad informática');self::assertSame([$term['id']],$p['ids']);self::assertSame(2,$p['selected_count']);$before=count(InterestCatalog::terms());$p=InterestCatalog::propose('Tema inexistente QZX');self::assertSame([],$p['ids']);self::assertNotEmpty($p['unknown']);self::assertCount($before,InterestCatalog::terms());$this->rejected(400,fn()=>InterestCatalog::validate([999999999]));
    }
    public function testFreeTextAiCannotInventIdsOrApplyWithoutHumanReview():void
    {
        $i=$this->start([[$this->email(),'','','Deseo formación; contacto persona@example.invalid']]);$i=InterestImports::process((int)$i['id']);$row=$i['rows'][0];$provider=new class($this->terms[0]) implements AIProviderInterface{public array $context=[];public function __construct(private int $term){}public function mode():string{return 'test';}public function generate(string $task,array $context):array{$this->context=$context;return ['intereses'=>[$this->term],'confianza'=>'media'];}};
        InterestImports::classify((int)$i['id'],$row['id'],$provider);self::assertSame(['text','catalog'],array_keys($provider->context));self::assertStringNotContainsString('persona@example.invalid',wp_json_encode($provider->context));self::assertSame('review',ImportRecords::rows((int)$i['id'])[0]['state']);self::assertEmpty(get_user_meta($this->users[1],'_ascla_profile',true));$this->rejected(409,fn()=>InterestImports::confirm((int)$i['id']));
        $j=$this->start([[$this->email(),'','','Otra respuesta libre']]);$j=InterestImports::process((int)$j['id']);$bad=new class implements AIProviderInterface{public function mode():string{return 'bad';}public function generate(string $task,array $context):array{return ['intereses'=>[99999999],'confianza'=>'alta'];}};$this->rejected(502,fn()=>InterestImports::classify((int)$j['id'],$j['rows'][0]['id'],$bad));
    }
    public function testBatchProgressPaginationAndAccessChecks():void
    {
        $i=$this->start(array_fill(0,55,[$this->email(),'','','']));$id=(int)$i['id'];$i=InterestImports::process($id);self::assertSame('processing',$i['status']);self::assertSame(5,$i['counts']['pending']);self::assertCount(20,$i['rows']);$i=InterestImports::process($id);self::assertSame('review',$i['status']);self::assertCount(15,InterestImports::detail($id,3)['rows']);
        foreach([1,2,3] as $n){wp_set_current_user($this->users[$n]);$this->rejected(403,fn()=>InterestImports::detail($id));$r=new WP_REST_Request('POST','/ascla/v1/admin/interest-imports/'.$id.'/confirm');self::assertSame(403,rest_do_request($r)->get_status());}
    }
    public function testSessionUnionThresholdAndExplicitDateFormats():void
    {
        $start=strtotime('2026-09-01T12:00:00Z');$row=static fn($a,$z,$seconds=1200)=>['line'=>2,'join'=>$a,'leave'=>$z,'seconds'=>$seconds];$o=['timezone'=>'UTC','date_format'=>'ymd'];
        $r=AttendanceSessions::calculate([$row('2026-09-01T11:50:00Z','2026-09-01T12:30:00Z'),$row('2026-09-01T12:20:00Z','2026-09-01T12:45:00Z')],$start,$start+3600,70,$o);self::assertSame(2700,$r['seconds']);self::assertSame('present',$r['status']);self::assertCount(1,$r['intervals']);
        $r=AttendanceSessions::calculate([$row('2026-09-01T12:00:00Z','2026-09-01T12:41:59Z')],$start,$start+3600,70,$o);self::assertSame('partial',$r['status']);self::assertSame(42,$r['minutes']);
        $r=AttendanceSessions::calculate([$row('',''),$row('','')],$start,$start+3600,70,$o);self::assertSame('review',$r['status']);self::assertNull($r['seconds']);
        $r=AttendanceSessions::calculate([$row('01/09/2026 12:00','01/09/2026 13:00')],$start,$start+3600,70,['timezone'=>'UTC','date_format'=>'dmy']);self::assertSame('present',$r['status']);
        $r=AttendanceSessions::calculate([$row('01/09/2026 12:00','01/09/2026 13:00')],$start,$start+3600,70,$o);self::assertSame('review',$r['status']);
    }
    public function testReleaseMetadataMatchesPluginVersionAndSchema():void
    {
        $root=getenv('ASCLA_PROJECT_ROOT')?:dirname(__DIR__,2);
        $plugin=file_get_contents(WP_PLUGIN_DIR.'/ascla-core/ascla-core.php');
        $readme=file_get_contents(WP_PLUGIN_DIR.'/ascla-core/readme.txt');
        $sonar=file_get_contents($root.'/sonar-project.properties');
        $docs=file_get_contents($root.'/README.md');
        $history=file_get_contents($root.'/VERSION_HISTORY.md');
        self::assertMatchesRegularExpression('/Version:\s*1\.10\.4/', $plugin);
        self::assertStringContainsString("define('ASCLA_VERSION', '1.10.4');", $plugin);
        self::assertMatchesRegularExpression('/Stable tag:\s*1\.10\.4/', $readme);
        self::assertStringContainsString('sonar.projectVersion=1.10.4', $sonar);
        self::assertStringContainsString('Versión actual: 1.10.4 · esquema 13', $docs);
        self::assertStringContainsString('**Versión actual:** `1.10.4`', $history);
        self::assertSame(13, \ASCLA\Core\Database\Installer::SCHEMA_VERSION);
    }
    public function testSqlRankingAndRosterReturnPagesWithoutLosingTotals():void
    {
        $start=time()-2*DAY_IN_SECONDS;$event=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'Paged attendance','post_author'=>$this->users[0]]);$this->posts[]=$event;update_post_meta($event,'_ascla',['start'=>gmdate('c',$start),'end'=>gmdate('c',$start+3600)]);wp_set_object_terms($event,$this->terms,'ascla_interest');
        for($n=0;$n<23;$n++){$name='pagination_'.bin2hex(random_bytes(5));$uid=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>'ascla_member']);$this->users[]=$uid;Store::insert('registrations',['event_id'=>$event,'user_id'=>$uid,'status'=>'accepted','created_at'=>gmdate('Y-m-d H:i:s')]);Store::insert('attendance',['event_id'=>$event,'user_id'=>$uid,'status'=>'present','minutes'=>50,'source'=>'manual','recorded_by'=>$this->users[0],'updated_at'=>gmdate('Y-m-d H:i:s')]);}
        Attendance::complete($event,true);$filter=['event'=>$event,'topic'=>$this->terms[0]];$a=Statistics::dashboard($filter);$z=Statistics::dashboard($filter+['page'=>2]);self::assertCount(20,$a['ranking']);self::assertCount(3,$z['ranking']);self::assertSame(23,$a['ranking_total']);self::assertSame(23,$a['summary']['attendances']);self::assertSame(100.0,$a['summary']['rate']);self::assertSame([],array_intersect(array_column($a['ranking'],'id'),array_column($z['ranking'],'id')));self::assertCount(3,Attendance::roster($event,2)['people']);self::assertSame(23,Attendance::roster($event)['summary']['present']);
    }
}
