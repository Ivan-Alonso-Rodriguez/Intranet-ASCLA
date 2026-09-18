<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Attendance,Statistics,TopicInsights,Content,Administration,Reports,Settings};
use ASCLA\Core\Repositories\{Store,StatisticsRepository};
use ASCLA\Core\Domain\{ZoomCsv,StatisticsPeriod};
use ASCLA\Core\Integrations\AIProviderInterface;

final class StatisticsTest extends TestCase
{
    private array $users=[],$posts=[],$terms=[],$events=[],$filter=[];
    private int $topic;
    protected function setUp():void
    {
        foreach(['administrator','ascla_executive','ascla_moderator','ascla_member','ascla_member'] as $role) {
            $name='stats_'.bin2hex(random_bytes(6));$this->users[]=wp_insert_user(['user_login'=>$name,'user_email'=>$name.'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role,'display_name'=>$name]);
        }
        wp_set_current_user($this->users[0]);
        $t=wp_insert_term('Analytics '.bin2hex(random_bytes(5)),'ascla_interest');$this->topic=(int)$t['term_id'];$this->terms[]=$this->topic;
        $today=new DateTimeImmutable('today',wp_timezone());
        $this->filter=['from'=>$today->modify('-5 days')->format('Y-m-d'),'to'=>$today->modify('-1 day')->format('Y-m-d'),'topic'=>$this->topic];
        foreach([-3,-2,-8] as $offset)$this->events[]=$this->event($offset);
        update_user_meta($this->users[3],'_ascla_profile',['interests'=>[$this->topic]]);
    }
    private function event(int $days,array $extra=[]):int
    {
        $day=(new DateTimeImmutable('today',wp_timezone()))->modify($days.' days')->setTime(12,0)->getTimestamp();
        $id=wp_insert_post(['post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'ChatGPT y LLM para el directorio','post_content'=>'Evento de prueba','post_author'=>$this->users[0]]);
        $this->posts[]=$id;update_post_meta($id,'_ascla',$extra+['start'=>gmdate('c',$day),'end'=>gmdate('c',$day+3600),'chatham'=>false]);wp_set_object_terms($id,[$this->topic],'ascla_interest');return $id;
    }
    protected function tearDown():void
    {
        wp_set_current_user($this->users[0]);foreach($this->posts as $id){wp_delete_post($id,true);foreach(['registrations','attendance'] as $table)Store::delete($table,['event_id'=>$id]);}
        foreach($this->terms as $id)wp_delete_term($id,'ascla_interest');
        foreach($this->users as $id){foreach(['relations','notifications','jobs','registrations','attendance'] as $t)Store::delete($t,['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);}wp_set_current_user(0);
    }
    private function api(string $method,string $route,array $data=[]):WP_REST_Response
    {
        $r=new WP_REST_Request($method,'/ascla/v1/'.$route);if($method==='GET')$r->set_query_params($data);else{$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($data));}return rest_do_request($r);
    }
    private function register(int $event,int $user,string $status='accepted'):void
    {
        Store::insert('registrations',['event_id'=>$event,'user_id'=>$user,'status'=>$status,'created_at'=>current_time('mysql',true)]);
    }
    private function email(int $index):string{return get_userdata($this->users[$index])->user_email;}
    private function expectApiException(int $code,callable $operation):void
    {
        try{$operation();self::fail('Expected rejection '.$code);}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame($code,$e->getCode(),$e->getMessage());}
    }
    public function testCapabilitiesProtectAllStatisticsAndAttendanceRoutes():void
    {
        foreach($this->users as $i=>$id) {
            wp_set_current_user($id);$allowed=$i<2;
            foreach(['admin/statistics','admin/statistics/topics','admin/attendance/'.$this->events[0]] as $route)self::assertSame($allowed?200:403,$this->api('GET',$route,$route==='admin/attendance/'.$this->events[0]?[]:$this->filter)->get_status(),$route);
            if(!$allowed)foreach(['admin/statistics/topics','admin/attendance/'.$this->events[0],'admin/attendance/'.$this->events[0].'/complete','admin/attendance/'.$this->events[0].'/preview','admin/attendance/'.$this->events[0].'/import'] as $route)self::assertSame(403,$this->api('POST',$route,['complete'=>true])->get_status(),$route);
        }
        wp_set_current_user(0);self::assertSame(401,$this->api('GET','admin/statistics')->get_status());
        wp_set_current_user($this->users[1]);update_user_meta($this->users[1],'_ascla_suspended',1);self::assertSame(403,$this->api('GET','admin/statistics')->get_status());
    }
    public function testRegistrationsAreNotAssistanceAndCompleteRosterControlsRates():void
    {
        [$a,$b,$old]=$this->events;[$admin,$exec,$mod,$u,$v]=$this->users;
        $this->register($a,$u);$this->register($a,$v);$this->register($b,$u);$this->register($b,$v,'waitlisted');
        $empty=Statistics::dashboard($this->filter);self::assertSame(3,$empty['summary']['registrations']);self::assertSame(0,$empty['summary']['attendances']);self::assertNull($empty['summary']['rate']);
        Attendance::save($a,['user_id'=>$u,'status'=>'present','minutes'=>40]);Attendance::save($a,['user_id'=>$exec,'status'=>'present','minutes'=>20]);Attendance::save($a,['user_id'=>$v,'status'=>'absent']);Attendance::save($b,['user_id'=>$u,'status'=>'present']);Attendance::save($old,['user_id'=>$u,'status'=>'present']);
        Attendance::complete($a,true);Attendance::complete($b,true);Attendance::complete($old,true);
        $d=Statistics::dashboard($this->filter);$s=$d['summary'];self::assertSame(2,$s['events']);self::assertSame(3,$s['attendances']);self::assertSame(2,$s['attendees']);self::assertSame(1,$s['recurring']);self::assertSame(66.7,$s['rate']);self::assertSame(1.5,$s['average']);self::assertSame(60,$s['minutes']);
        self::assertSame($u,$d['ranking'][0]['id']);self::assertSame(100.0,$d['ranking'][0]['rate']);self::assertSame(1,$d['topics'][0]['declared']);self::assertSame(2,$d['topics'][0]['attendees']);self::assertSame(3,$d['topics'][0]['attendances']);self::assertSame(200.0,$d['topics'][0]['change']);
        Attendance::save($b,['user_id'=>$u,'status'=>'unknown']);self::assertFalse(Attendance::roster($b)['complete']);self::assertSame(50.0,Statistics::dashboard($this->filter)['summary']['rate']);
    }
    public function testCategoryAndEventFiltersDoNotConfuseCurrentProfileInterestsWithHistoricalAttendance():void
    {
        $category=wp_insert_term('Statistics category '.bin2hex(random_bytes(5)),'ascla_category');$cid=(int)$category['term_id'];
        try{wp_set_object_terms($this->events[0],[$cid],'ascla_category');$d=Statistics::dashboard($this->filter+['category'=>$cid]);self::assertSame(1,$d['summary']['events']);self::assertSame(1,$d['topics'][0]['declared']);$e=Statistics::dashboard($this->filter+['event'=>$this->events[1]]);self::assertSame(1,$e['summary']['events']);self::assertSame(0,$e['previous']['events']);}finally{wp_delete_term($cid,'ascla_category');}
    }
    public function testCsvPreviewMatchesEmailMergesReconnectsAndIsIdempotent():void
    {
        $event=$this->events[0];$email=$this->email(3);$start=strtotime(get_post_meta($event,'_ascla_start',true));$a=gmdate('c',$start);$b=gmdate('c',$start+1200);$c=gmdate('c',$start+2400);
        $csv="Meeting,Summary\nName (Original Name),User Email,Join Time,Leave Time,Duration (Minutes)\n\"Usuario, Uno\",$email,{$a},{$b},20\n\"Usuario, Uno\",$email,{$b},{$c},20\n\"Usuario, Uno\",$email,{$b},{$c},20\nExterno,unknown@example.invalid,{$a},{$b},20\n";
        $p=Attendance::preview($event,$csv);self::assertSame(1,$p['importable']);self::assertSame(1,$p['duplicates']);self::assertCount(1,$p['unmatched']);self::assertSame(40,$p['matched'][0]['minutes']);self::assertSame(2,$p['matched'][0]['connections']);self::assertCount(0,Attendance::roster($event)['people']);
        Attendance::import($event,$csv,$p['token']);$p=Attendance::preview($event,$csv);Attendance::import($event,$csv,$p['token']);self::assertSame(1,Store::count('attendance','event_id=%d',[$event]));$r=Attendance::roster($event);self::assertSame(40,$r['people'][0]['minutes']);self::assertFalse($r['complete']);
    }
    public function testCsvNeverOverwritesManualCorrectionAndRejectsStalePreview():void
    {
        $id=$this->events[0];$csv="Name,Email,Duration (Minutes)\nPersona,".$this->email(3).",15\n";$p=Attendance::preview($id,$csv);
        Attendance::save($id,['email'=>$this->email(3),'status'=>'absent']);
        $this->expectApiException(409,fn()=>Attendance::import($id,$csv,$p['token']));$next=Attendance::preview($id,$csv);self::assertSame(1,$next['protected']);self::assertSame(0,$next['importable']);self::assertSame('absent',Attendance::roster($id)['people'][0]['status']);
        $this->expectApiException(400,fn()=>Attendance::import($id,$csv,$next['token']));
    }
    public function testCsvSupportsSpanishBomSemicolonAndUnknownDurationWithoutInventingMinutes():void
    {
        $csv="\xEF\xBB\xBFNombre;Correo electrónico;Duración (minutos)\nPersona;".$this->email(3).";12,5\nOtra;".$this->email(4).";\n";
        $rows=ZoomCsv::parse($csv);self::assertSame(13,$rows[0]['minutes']);self::assertNull($rows[1]['minutes']);
        $p=Attendance::preview($this->events[0],$csv);self::assertSame(2,$p['importable']);
        foreach(["Name,Email,Duration\nX,".$this->email(3).",=10+20", "Name,Email,Duration\nX,".$this->email(3).",-1"] as $bad){$p=Attendance::preview($this->events[0],$bad);self::assertSame(0,$p['importable']);self::assertCount(1,$p['unmatched']);}
        foreach(['',"Name,Minutes\nX,12",str_repeat('x',524289),"Email\n\0"] as $bad)$this->expectApiException(400,fn()=>ZoomCsv::parse($bad));
    }
    public function testAttendanceRejectsFutureCancelledAndInaccessiblePrivateEvents():void
    {
        $future=$this->event(3);$cancelled=$this->event(-2,['cancelled'=>true]);$private=$this->event(-2,['micro'=>true,'invitees'=>[$this->users[3]]]);
        self::assertSame(409,$this->api('POST','admin/attendance/'.$future,['email'=>$this->email(3),'status'=>'present'])->get_status());self::assertSame(409,$this->api('GET','admin/attendance/'.$cancelled)->get_status());
        wp_set_current_user($this->users[1]);self::assertSame(404,$this->api('GET','admin/attendance/'.$private)->get_status());
        $ids=array_column(Statistics::dashboard($this->filter)['options']['events'],'id');self::assertNotContains($private,$ids);self::assertNotContains($future,$ids);self::assertNotContains($cancelled,$ids);
        self::assertSame(400,$this->api('POST','admin/attendance/'.$this->events[0],['email'=>$this->email(3),'status'=>'present','minutes'=>61])->get_status());
    }
    public function testPeriodsUseLocalCalendarAndRejectInvalidOrUnboundedRanges():void
    {
        $p=new StatisticsPeriod($this->filter);self::assertSame(5,$p->days);self::assertSame(5,(int)$p->previous->diff($p->from)->days);self::assertNull(StatisticsPeriod::change(2,0));self::assertSame(0.0,StatisticsPeriod::change(0,0));self::assertSame(-100.0,StatisticsPeriod::change(0,3));
        foreach([['from'=>'2026-02-30','to'=>'2026-03-02'],['from'=>'2020-01-01','to'=>'2022-01-01'],['from'=>'2025-10-02','to'=>'2025-10-01'],['to'=>'2999-01-01'],['from'=>[]]] as $invalid)$this->expectApiException(400,fn()=>new StatisticsPeriod($invalid));
    }
    public function testMoreThanOneHundredRecordsAreNotSilentlyTruncated():void
    {
        // Reuse existing members to exercise the repository's complete row retrieval without creating accounts.
        $ids=[];for($i=0;$i<105;$i++){$eid=$this->event(-2);$ids[$eid]=['id'=>$eid];$this->register($eid,$this->users[3]);}
        self::assertCount(105,StatisticsRepository::rows('registrations',$ids));
        $d=Statistics::dashboard($this->filter);self::assertSame(105,$d['summary']['registrations']);self::assertSame(107,$d['summary']['events']);self::assertSame(107,$d['events_total']);self::assertCount(20,$d['events']);
        $page2=Statistics::dashboard($this->filter+['event_page'=>2]);self::assertSame(2,$page2['event_page']);self::assertCount(20,$page2['events']);self::assertNotSame(array_column($d['events'],'id'),array_column($page2['events'],'id'));
    }
    public function testAiReceivesOnlySanitizedTitlesAndCatalogWhileCountsRemainVerified():void
    {
        $secret=$this->event(-2,['chatham'=>true]);$micro=$this->event(-2,['micro'=>true,'invitees'=>[$this->users[0]]]);
        $meta=(array)get_post_meta($this->events[0],'_ascla',true);$meta['identities']='NOMBREPRIVADOUNICO';update_post_meta($this->events[0],'_ascla',$meta);wp_update_post(['ID'=>$this->events[0],'post_title'=>'ChatGPT NOMBREPRIVADOUNICO personal@example.invalid']);
        Attendance::save($this->events[0],['user_id'=>$this->users[3],'status'=>'present']);
        $provider=new class($this->topic) implements AIProviderInterface {
            public array $context=[];public function __construct(private int $topic){} public function mode():string{return 'Fixture classifier';}
            public function generate(string $task,array $context):array{$this->context=$context;return ['assignments'=>array_map(fn($i)=>['id'=>$i['id'],'topic_id'=>$this->topic],$context['items']),'attendances'=>999999];}
        };
        $result=TopicInsights::analyze($this->filter,$provider);self::assertSame(3,$result['classified']);self::assertSame(2,$result['excluded']);self::assertSame(1,$result['groups'][0]['attendances']);self::assertSame(0,$result['pending']);self::assertSame(['topics','items'],array_keys($provider->context));$json=wp_json_encode($provider->context);self::assertStringNotContainsString('NOMBREPRIVADOUNICO',$json);self::assertStringNotContainsString('personal@example.invalid',$json);self::assertStringNotContainsString($this->email(3),$json);
        self::assertSame([$this->topic],array_map('intval',wp_get_object_terms($this->events[0],'ascla_interest',['fields'=>'ids'])));
        wp_update_post(['ID'=>$this->events[0],'post_title'=>'Título modificado']);self::assertSame(1,TopicInsights::summary($this->filter)['pending']);
    }
    public function testAiRejectsInventedIdsAndDoesNotStorePartialAssignments():void
    {
        $provider=new class implements AIProviderInterface {public function mode():string{return 'Invalid fixture';}public function generate(string $task,array $context):array{return ['assignments'=>array_map(fn($i)=>['id'=>$i['id'],'topic_id'=>99999999],$context['items'])];}};
        $this->expectApiException(502,fn()=>TopicInsights::analyze($this->filter,$provider));foreach($this->events as $id)self::assertSame('',get_post_meta($id,'_ascla_statistics_topic',true));
    }
    public function testOnlyReviewedReportsCanBeDeletedWithoutDeletingTheirTarget():void
    {
        $p=Content::save('hub',['title'=>'Reporte de prueba','body'=>'Contenido','status'=>'publish']);$this->posts[]=$p['id'];
        wp_set_current_user($this->users[3]);Content::report($p['id'],'other','Prueba');$r=Store::rows('relations','target_id=%d AND kind=%s',[$p['id'],'report'],'LIMIT 1')[0];
        self::assertSame(403,$this->api('DELETE','admin/reports/'.$r['id'])->get_status());wp_set_current_user($this->users[2]);self::assertSame(409,$this->api('DELETE','admin/reports/'.$r['id'])->get_status());Reports::review((int)$r['id']);
        wp_set_current_user($this->users[3]);Content::report($p['id'],'other','Reabierto');wp_set_current_user($this->users[2]);self::assertSame(409,$this->api('DELETE','admin/reports/'.$r['id'])->get_status());Reports::review((int)$r['id']);self::assertSame(200,$this->api('DELETE','admin/reports/'.$r['id'])->get_status());self::assertNull(Store::one('relations',(int)$r['id']));self::assertSame('publish',get_post_status($p['id']));self::assertSame(404,$this->api('DELETE','admin/reports/'.$r['id'])->get_status());
        $like=Store::insert('relations',['user_id'=>$this->users[3],'target_id'=>$p['id'],'kind'=>'like','created_at'=>current_time('mysql',true)]);self::assertSame(404,$this->api('DELETE','admin/reports/'.$like)->get_status());
    }
    public function testCommentReportsHaveTheSameDeletionGate():void
    {
        $p=Content::save('hub',['title'=>'Comentario reportado','body'=>'Contenido','status'=>'publish']);$this->posts[]=$p['id'];$comment=wp_insert_comment(['comment_post_ID'=>$p['id'],'user_id'=>$this->users[0],'comment_content'=>'Texto original','comment_approved'=>1]);
        wp_set_current_user($this->users[3]);Content::reportComment($comment,'other','Revisar');$r=Store::rows('relations','target_id=%d AND kind=%s',[$comment,'comment_report'],'LIMIT 1')[0];wp_set_current_user($this->users[1]);Reports::review((int)$r['id']);Reports::remove((int)$r['id']);self::assertNotNull(get_comment($comment));self::assertNull(Store::one('relations',(int)$r['id']));
    }
    public function testResolvedRequestsCanBeDeletedButGenericContentRouteCannotBypassState():void
    {
        wp_set_current_user($this->users[3]);$p=Content::save('contact',['title'=>'Solicitud de prueba','body'=>'Detalle']);$id=$p['id'];$this->posts[]=$id;self::assertFalse($p['can_delete']);self::assertSame(403,$this->api('DELETE','items/'.$id)->get_status());
        wp_set_current_user($this->users[2]);foreach(['admin/contact/','items/'] as $route)self::assertSame(409,$this->api('DELETE',$route.$id)->get_status());Administration::contactStatus($id,'closed');self::assertTrue(Content::serialize(Content::get($id))['can_delete']);Administration::contactStatus($id,'progress');self::assertSame(409,$this->api('DELETE','admin/contact/'.$id)->get_status());Administration::contactStatus($id,'closed');self::assertSame(200,$this->api('DELETE','admin/contact/'.$id)->get_status());self::assertSame('trash',get_post_status($id));self::assertSame(404,$this->api('POST','admin/contact/'.$id,['status'=>'open'])->get_status());
    }
    public function testDeletingAUserOrEventCleansOnlyTheirAttendance():void
    {
        Attendance::save($this->events[0],['user_id'=>$this->users[3],'status'=>'present']);Attendance::save($this->events[1],['user_id'=>$this->users[4],'status'=>'present']);wp_delete_user($this->users[3]);self::assertSame(0,Store::count('attendance','user_id=%d',[$this->users[3]]));self::assertSame(1,Store::count('attendance','event_id=%d',[$this->events[1]]));wp_delete_post($this->events[1],true);self::assertSame(0,Store::count('attendance','event_id=%d',[$this->events[1]]));
    }
}
