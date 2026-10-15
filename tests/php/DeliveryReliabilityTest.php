<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Jobs\{Deliveries,DeliveryPayload,DeliveryHandlers};
use ASCLA\Core\Services\{Notifications,Events,Content};
use ASCLA\Core\Repositories\{Store,Transaction,RepositoryException};
use ASCLA\Core\Integrations\Secrets;

final class DeliveryReliabilityTest extends TestCase
{
    private array $users=[],$posts=[],$mails=[];
    private $mail;
    private bool $accept=true;
    protected function setUp(): void
    {
        foreach (['administrator','ascla_member'] as $role) {
            $login='delivery_'.bin2hex(random_bytes(6));
            $this->users[]=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
        wp_set_current_user($this->users[0]);
        $this->mail=function($result,$mail) { if ($result!==null) { return $result; }$this->mails[]=$mail;return $this->accept; };
        add_filter('pre_wp_mail',$this->mail,PHP_INT_MAX,2);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail',$this->mail,PHP_INT_MAX);
        foreach ($this->posts as $id) { Store::delete('registrations',['event_id'=>$id]);wp_delete_post($id,true); }
        foreach ($this->users as $id) {
            Store::delete('deliveries',['actor_id'=>$id]);Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);
            Secrets::remove('google_calendar_'.$id);wp_delete_user($id);
        }
        wp_set_current_user(0);
    }
    private function withoutCallbacks(callable $callback): mixed
    {
        // Simulate termination immediately after COMMIT, before PHP can run the memory callbacks.
        return Transaction::run(static function() use($callback) {
            $result=$callback();$queue=new ReflectionProperty(Transaction::class,'afterCommit');$queue->setValue(null,[]);return $result;
        });
    }
    private function due(int $id): void { Store::update('deliveries',['available_at'=>gmdate('Y-m-d H:i:s',time()-1)],['id'=>$id]); }
    private function event(): int
    {
        $post=Content::save('event',['title'=>'Evento fiable','body'=>'Datos privados','status'=>'publish','meta'=>['start'=>gmdate('c',time()+3600),'end'=>gmdate('c',time()+7200)]]);
        $this->posts[]=(int)$post['id'];return (int)$post['id'];
    }
    public function testOverviewStaysWithinMemoryBudgetWithThirtyLargePendingPayloads(): void
    {
        $payload=str_repeat('A',1024*1024);
        for ($i=0;$i<30;$i++) {
            Store::insert('deliveries',['kind'=>'mail','delivery_key'=>bin2hex(random_bytes(16)),'actor_id'=>$this->users[0],
                'payload'=>$payload,'status'=>'pending','available_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS),'created_at'=>gmdate('Y-m-d H:i:s')]);
        }
        unset($payload);
        $code='require '.var_export(ABSPATH.'wp-load.php',true).';$data=ASCLA\\Core\\Jobs\\Deliveries::overview();echo count($data["items"]);';
        exec(escapeshellarg(PHP_BINARY).' -d memory_limit=64M -r '.escapeshellarg($code),$output,$exit);
        self::assertSame(0,$exit,'The metadata panel must not materialize thirty payloads in PHP.');self::assertSame('30',implode('',$output));
    }
    public function testASeparateWordPressProcessResumesMailAfterCommitAndKeepsAttachments(): void
    {
        $path=tempnam(sys_get_temp_dir(),'ascla-delivery-');file_put_contents($path,'Adjunto privado de prueba');
        try {
            $id=$this->withoutCallbacks(static function() use($path) {
                wp_mail('resume@example.invalid','Correo privado','Enlace privado de prueba',[],[$path]);
                return (int)Store::rows('deliveries',"kind='mail'",[],'ORDER BY id DESC LIMIT 1')[0]['id'];
            });
        } finally { wp_delete_file($path); }
        self::assertSame([],$this->mails);$row=Store::one('deliveries',$id);self::assertSame('pending',$row['status']);
        self::assertStringNotContainsString('resume@example.invalid',$row['payload']);
        $code='require '.var_export(ABSPATH.'wp-load.php',true).'; add_filter("pre_wp_mail",static function($result,$mail) { $bytes=file_get_contents($mail["attachments"][0]); return $bytes==="Adjunto privado de prueba"; },PHP_INT_MAX,2); ASCLA\\Core\\Jobs\\Deliveries::run('.$id.'); echo ASCLA\\Core\\Repositories\\Store::one("deliveries",'.$id.')["status"];';
        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code),$output,$exit);
        self::assertSame(0,$exit);self::assertSame('sent',implode('', $output));
        $row=Store::one('deliveries',$id);self::assertSame('',$row['payload']);self::assertSame(1,(int)$row['attempts']);
        Deliveries::run($id);self::assertSame([],$this->mails,'A completed delivery cannot be resent.');
    }
    public function testARejectedDeliveryWriteRollsBackNotificationAndLateFailuresSendNothing(): void
    {
        global $wpdb;$old=$wpdb->suppress_errors(true);
        $fail=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.Store::table('deliveries').'`')?str_replace(Store::table('deliveries'),Store::table('deliveries').'_missing',$sql):$sql;
        add_filter('query',$fail);
        try {
            Notifications::send($this->users[1],'connection','Solicitud privada');self::fail('Expected SQL failure');
        } catch (RepositoryException) { self::assertSame(0,Store::count('notifications','user_id=%d',[$this->users[1]])); }
        finally { remove_filter('query',$fail);$wpdb->suppress_errors($old);$wpdb->last_error=''; }
        try {
            Transaction::run(function() { Notifications::send($this->users[1],'connection','Solicitud privada');wp_mail('private@example.invalid','Privado','Datos');throw new RuntimeException('Late failure'); });
        } catch (RuntimeException) { self::assertSame(0,Store::count('deliveries','actor_id=%d',[$this->users[0]])); }
        self::assertSame([],$this->mails);self::assertSame(0,Store::count('notifications','user_id=%d',[$this->users[1]]));
    }
    public function testMailFailureRetriesWithoutConsumingMessageThrottleAndRestoresWorkerUser(): void
    {
        $this->accept=false;
        Notifications::send($this->users[1],'message','Texto privado','',['type'=>'conversation','id'=>654321]);
        $row=Store::rows('deliveries','actor_id=%d',[$this->users[0]],'ORDER BY id DESC LIMIT 1')[0];$id=(int)$row['id'];
        self::assertSame('pending',$row['status']);self::assertSame(1,(int)$row['attempts']);
        self::assertGreaterThan(time(),strtotime($row['available_at'].' UTC'));
        self::assertSame('',get_user_meta($this->users[1],'_ascla_message_email_last_sent',true));
        $this->accept=true;$this->due($id);wp_set_current_user(0);Deliveries::run($id);
        self::assertSame(0,get_current_user_id());self::assertSame('sent',Store::one('deliveries',$id)['status']);self::assertCount(2,$this->mails);
        wp_set_current_user($this->users[0]);Notifications::send($this->users[1],'message','Otro texto','',['type'=>'conversation','id'=>654321]);
        self::assertCount(2,$this->mails,'Successful retry starts the throttle, not the failed attempt.');
    }
    public function testRevokedMembershipDeletedNoticeAndChangedPreferencesPreventDelayedMail(): void
    {
        foreach (['deleted','preferences','membership'] as $reason) {
            $id=$this->withoutCallbacks(function() {
                Notifications::send($this->users[1],'connection','Solicitud');return (int)Store::rows('deliveries','actor_id=%d',[$this->users[0]],'ORDER BY id DESC LIMIT 1')[0]['id'];
            });
            $payload=DeliveryPayload::open('notification',Store::one('deliveries',$id)['payload']);
            if ($reason==='deleted') { Store::delete('notifications',['id'=>$payload['id']]); }
            if ($reason==='preferences') { Notifications::saveEmailPreferences($this->users[1],['connections'=>false]); }
            if ($reason==='membership') { Notifications::saveEmailPreferences($this->users[1],['connections'=>true]);update_user_meta($this->users[1],'_ascla_suspended',true); }
            Deliveries::run($id);self::assertContains(Store::one('deliveries',$id)['status'],['sent','skipped']);
        }
        self::assertSame([],$this->mails);
    }
    public function testInterruptedClaimRecoversAndConcurrentWorkerCannotDuplicateDelivery(): void
    {
        $id=$this->withoutCallbacks(static fn()=>Deliveries::enqueue('mail',['to'=>'a@example.invalid','subject'=>'Privado','message'=>'Texto','headers'=>[],'files'=>[]]));
        Store::update('deliveries',['status'=>'processing','locked_at'=>gmdate('Y-m-d H:i:s',time()-601)],['id'=>$id]);
        global $wpdb;$second=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$lock='ascla_'.substr(hash('sha256',$wpdb->prefix.'deliveries'),0,56);
        $check=static function($result) use($second,$lock,$id) { self::assertSame('0',(string)$second->get_var($second->prepare('SELECT GET_LOCK(%s,0)',$lock)));Deliveries::run($id);return $result; };
        add_filter('pre_wp_mail',$check,9);
        try { Deliveries::run($id); } finally { remove_filter('pre_wp_mail',$check,9);$second->close(); }
        self::assertSame('sent',Store::one('deliveries',$id)['status']);self::assertCount(1,$this->mails);
    }
    public function testBoundedRetriesAndPrivateAdministrativeStatus(): void
    {
        $this->accept=false;$id=Deliveries::enqueue('mail',['to'=>'private@example.invalid','subject'=>'Private title','message'=>'Secret link','headers'=>[],'files'=>[]]);
        foreach (range(1,7) as $attempt) { $this->due($id);Deliveries::run($id); }
        self::assertSame('failed',Store::one('deliveries',$id)['status']);self::assertSame(8,(int)Store::one('deliveries',$id)['attempts']);
        $json=wp_json_encode(Deliveries::overview());foreach (['payload','actor_id','private@example.invalid','Private title','Secret link'] as $private) { self::assertStringNotContainsString($private,$json); }
        wp_set_current_user($this->users[1]);$response=rest_do_request(new WP_REST_Request('GET','/ascla/v1/admin/deliveries'));self::assertSame(403,$response->get_status());
        try { Deliveries::retry($id);self::fail('Member may not retry deliveries'); } catch (ASCLA\Core\Rest\ApiException $error) { self::assertSame(403,$error->getCode()); }
        wp_set_current_user($this->users[0]);$this->accept=true;Deliveries::retry($id);self::assertSame('sent',Store::one('deliveries',$id)['status']);
        self::assertSame(1,Store::count('audit','action=%s AND object_id=%d',['delivery_retry',$id]));
    }
    public function testCalendarRecoversAmbiguousCreateWithoutDuplicatingAndLaterCancellationWins(): void
    {
        Secrets::set('google_calendar_'.$this->users[0],wp_json_encode(['access_token'=>'fixture-token','expires_at'=>time()+3600]));
        $event=$this->event();$created=[];$calls=[];$first=true;
        $http=static function($pre,$args,$url) use(&$created,&$calls,&$first) {
            $body=isset($args['body'])?json_decode($args['body'],true):[];$calls[]=$args['method'];$id=$body['id']??basename(wp_parse_url($url,PHP_URL_PATH));
            if ($args['method']==='POST') {
                if (isset($created[$id])) { return ['response'=>['code'=>409],'body'=>'{}','headers'=>[]]; }
                $created[$id]=$body;
                if ($first) { $first=false;return new WP_Error('http_request_failed','Ambiguous timeout'); }
            }
            if ($args['method']==='DELETE') { unset($created[$id]);return ['response'=>['code'=>204],'body'=>'','headers'=>[]]; }
            return ['response'=>['code'=>200],'body'=>wp_json_encode(['id'=>$id]),'headers'=>[]];
        };
        add_filter('pre_http_request',$http,10,3);
        try {
            Events::invite($event,[$this->users[1]]);
            $row=Store::rows('deliveries',"actor_id=%d AND kind='calendar'",[$this->users[0]],'ORDER BY id DESC LIMIT 1')[0];$id=(int)$row['id'];
            self::assertSame('pending',$row['status']);self::assertCount(1,$created);$remote=array_key_first($created);self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/',$remote);
            $this->due($id);Deliveries::run($id);self::assertSame('sent',Store::one('deliveries',$id)['status']);self::assertCount(1,$created);self::assertSame(['POST','POST','PATCH'],$calls);
            self::assertSame($remote,get_post_meta($event,'_ascla_google_organizer_event',true));
            $update=$this->withoutCallbacks(static fn()=>Deliveries::enqueue('calendar',['id'=>$event]));
            Events::cancel($event,'Cancelado por prueba');self::assertSame([],$created);
            Deliveries::run($update);self::assertSame([],$created);self::assertNotContains('POST',array_slice($calls,3));
        } finally { remove_filter('pre_http_request',$http,10); }
    }
    public function testAuthenticatedPayloadRejectsTamperingAndSevenDayRetentionErasesPrivateData(): void
    {
        $id=$this->withoutCallbacks(static fn()=>Deliveries::enqueue('mail',['to'=>'private@example.invalid','message'=>'Secret','subject'=>'Private']));
        $row=Store::one('deliveries',$id);
        try { DeliveryPayload::open('calendar',$row['payload']);self::fail('Wrong authenticated type accepted'); } catch (RuntimeException) { self::assertTrue(true); }
        Store::update('deliveries',['created_at'=>gmdate('Y-m-d H:i:s',time()-8*DAY_IN_SECONDS)],['id'=>$id]);Deliveries::run($id);
        self::assertSame('expired',Store::one('deliveries',$id)['status']);self::assertSame('',Store::one('deliveries',$id)['payload']);self::assertSame([],$this->mails);
    }
    public function testDelayedWaitlistMailIsSkippedAfterOfferExpires(): void
    {
        $event=$this->event();$registration=Store::insert('registrations',['event_id'=>$event,'user_id'=>$this->users[1],'status'=>'offered','created_at'=>gmdate('Y-m-d H:i:s')]);
        update_post_meta($event,'_ascla_waitlist_offers',[$registration=>time()+3600]);
        $id=$this->withoutCallbacks(function() use($event) {
            Notifications::once($this->users[1],'offer-test:'.$event,'event_waitlist_available','Hay un cupo','',['type'=>'post','id'=>$event]);
            return (int)Store::rows('deliveries','actor_id=%d',[$this->users[0]],'ORDER BY id DESC LIMIT 1')[0]['id'];
        });
        update_post_meta($event,'_ascla_waitlist_offers',[$registration=>time()-1]);Deliveries::run($id);
        self::assertSame('skipped',Store::one('deliveries',$id)['status']);self::assertSame([],$this->mails);
        self::assertSame(1,Store::count('notifications','user_id=%d AND event_key=%s',[$this->users[1],'offer-test:'.$event]));
    }
}
