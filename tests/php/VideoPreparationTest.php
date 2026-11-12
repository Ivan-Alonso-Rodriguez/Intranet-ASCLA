<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Content,Settings,VideoPreparation,NotificationTarget};
use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Repositories\Store;

final class VideoPreparationTest extends TestCase
{
    private array $users=[],$posts=[],$jobs=[],$before=[],$saved=[],$replies=[],$requests=[];
    protected function setUp(): void
    {
        global $wpdb;$this->jobs=Store::rows('jobs','1=1',[],'ORDER BY id');
        foreach($this->jobs as $row){if($row['status']==='pending')Store::update('jobs',['locked_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS)],['id'=>$row['id']]);}
        $this->before=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%ascla_sd_%' OR option_name LIKE '%ascla_yt_public_%'");
        $this->saved=['settings'=>get_option('ascla_settings'),'key'=>getenv('ASCLA_SUPADATA_KEY'),'flag'=>getenv('ASCLA_EXTERNAL_SECRETS_ONLY'),'secret'=>get_option('ascla_secret_supadata_key',null)];
        putenv('ASCLA_SUPADATA_KEY=auto-'.bin2hex(random_bytes(10)));putenv('ASCLA_EXTERNAL_SECRETS_ONLY=0');
        foreach(['administrator','ascla_member'] as $role)$this->users[]=wp_insert_user(['user_login'=>'video_auto_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>$role]);
        wp_set_current_user($this->users[0]);Settings::save(['youtube_mode'=>'supadata','supadata_language'=>'es','supadata_generate'=>false,'ai_provider'=>'mock']);add_filter('pre_http_request',[$this,'http'],10,3);
    }
    protected function tearDown(): void
    {
        global $wpdb;remove_filter('pre_http_request',[$this,'http'],10);
        foreach($this->posts as $id)wp_delete_post($id,true);
        foreach(Store::rows('jobs','1=1',[],'ORDER BY id') as $row){if(!in_array($row['id'],array_column($this->jobs,'id'),true))Store::delete('jobs',['id'=>$row['id']]);}
        foreach($this->jobs as $row)Store::update('jobs',['locked_at'=>$row['locked_at']],['id'=>$row['id']]);
        foreach($this->users as $id){Store::delete('notifications',['user_id'=>$id]);Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id);}
        $keys=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%ascla_sd_%' OR option_name LIKE '%ascla_yt_public_%'");foreach(array_diff($keys,$this->before) as $name)delete_option($name);
        update_option('ascla_settings',$this->saved['settings'],false);if($this->saved['secret']===null)delete_option('ascla_secret_supadata_key');else update_option('ascla_secret_supadata_key',$this->saved['secret'],false);
        foreach(['ASCLA_SUPADATA_KEY'=>'key','ASCLA_EXTERNAL_SECRETS_ONLY'=>'flag'] as $name=>$key)putenv($this->saved[$key]===false?$name:$name.'='.$this->saved[$key]);wp_set_current_user(0);
    }
    private function reply(int $code,array $data): array{return ['response'=>['code'=>$code],'body'=>wp_json_encode($data),'headers'=>[],'cookies'=>[]];}
    public function http($pre,$args,$url)
    {
        if(str_starts_with($url,'https://api.supadata.ai/')){$this->requests[]=$url;$reply=array_shift($this->replies);self::assertNotNull($reply,'Unexpected paid request');return $reply;}
        if(str_contains($url,'youtube.com/')||str_contains($url,'googleapis.com/'))return $this->reply(200,['lengthSeconds'=>'7500']);return $pre;
    }
    private function create(string $status='publish'): int
    {
        $p=Content::save('resource',['title'=>'Video que se prepara automáticamente','body'=>'Material de la comunidad.','status'=>$status,'meta'=>['youtube_url'=>'https://youtu.be/ASCLAAUTO01','resource_type'=>'Video','chatham'=>false]]);$this->posts[]=$p['id'];return $p['id'];
    }
    private function automatic(int $id): array{return array_values(array_filter(Store::rows('jobs','kind=%s',['multimedia']),static fn($row)=>(json_decode($row['payload'],true)['resource_id']??0)===$id));}
    private function due(): void
    {
        global $wpdb;$names=$wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_ascla_sd_%'");foreach(array_diff($names,$this->before) as $name){$key=substr($name,11);$value=get_transient($key);if(is_array($value)){$value['until']=0;set_transient($key,$value,DAY_IN_SECONDS);}elseif(is_numeric($value))delete_transient($key);}
        foreach(Store::rows('jobs','user_id=%d AND status=%s',[$this->users[0],'pending']) as $row)Store::update('jobs',['locked_at'=>null],['id'=>$row['id']]);
    }
    public function testPublishingQueuesOnceAndDraftDoesNotSpendCredits(): void
    {
        $id=$this->create('draft');self::assertCount(0,$this->automatic($id));$p=Content::save('resource',['title'=>'Video','body'=>'Material publicado.','status'=>'publish'],$id);self::assertCount(1,$this->automatic($id));self::assertSame('pending',$p['meta']['video_preparation']['status']);Content::save('resource',['title'=>'Título actualizado','body'=>'Otro texto.','status'=>'publish'],$id);self::assertCount(1,$this->automatic($id));self::assertCount(0,$this->requests);
    }
    public function testAutomaticAsyncPipelineCompletesWithoutManualJobOrSuccessNotification(): void
    {
        $id=$this->create();$this->replies[]=$this->reply(202,['jobId'=>'automatic-long']);$this->replies[]=$this->reply(200,['status'=>'completed','content'=>str_repeat('La junta directiva supervisa el gobierno corporativo y la gestión de riesgos. ',20).'ULTIMO_SEGMENTO','lang'=>'es']);Queue::run();self::assertSame('pending',VideoPreparation::status($id)['status']);$this->due();Queue::run();self::assertSame('completed',VideoPreparation::status($id)['status']);$meta=get_post_meta($id,'_ascla',true);self::assertTrue($meta['ai_enriched']);self::assertStringContainsString('ULTIMO_SEGMENTO',$meta['transcript']);self::assertCount(2,$this->requests);self::assertSame(0,Store::count('notifications','user_id=%d AND kind=%s',[$this->users[0],'job']));
        wp_set_current_user($this->users[1]);self::assertArrayNotHasKey('video_preparation',Content::serialize(Content::get($id))['meta']);
    }
    public function testMissingKeyNotifiesAndSavingCorrectConfigurationAutomaticallyResumes(): void
    {
        putenv('ASCLA_SUPADATA_KEY');delete_option('ascla_secret_supadata_key');$id=$this->create();Queue::run();self::assertSame('error',VideoPreparation::status($id)['status']);$notices=Store::rows('notifications','user_id=%d AND kind=%s',[$this->users[0],'job_error']);self::assertCount(1,$notices);$view=NotificationTarget::view($notices[0]);self::assertStringContainsString('API Key',$view['description']);self::assertStringContainsString('item='.$id,$view['url']);Queue::run();self::assertCount(1,Store::rows('notifications','user_id=%d AND kind=%s',[$this->users[0],'job_error']));
        Settings::save(['supadata_key'=>'configured-test-key']);self::assertSame('pending',VideoPreparation::status($id)['status']);$this->replies[]=$this->reply(200,['content'=>str_repeat('El gobierno corporativo necesita supervisión del directorio y gestión de riesgos. ',8)]);Queue::run();self::assertSame('completed',VideoPreparation::status($id)['status']);
    }
    public function testChangedVideoSkipsObsoleteWorkAndDoesNotReplaceNewSource(): void
    {
        $id=$this->create();$this->replies[]=$this->reply(202,['jobId'=>'old-video']);Queue::run();Content::save('resource',['title'=>'Otro video','body'=>'Fuente actualizada.','status'=>'publish','meta'=>['youtube_url'=>'https://youtu.be/ASCLAAUTO02']],$id);$this->due();$this->replies[]=$this->reply(200,['content'=>str_repeat('Fuente nueva sobre gobierno corporativo y gestión de riesgos. ',10)]);Queue::run();Queue::run();self::assertSame('ASCLAAUTO02',get_post_meta($id,'_ascla',true)['video_id']);self::assertSame('completed',VideoPreparation::status($id)['status']);self::assertCount(2,$this->requests);
    }
    public function testScheduledPublicationFindsAuthorizedActorWithoutBrowserSession(): void
    {
        $id=$this->create('draft');wp_set_current_user(0);wp_publish_post($id);$jobs=$this->automatic($id);self::assertCount(1,$jobs);self::assertSame($this->users[0],(int)$jobs[0]['user_id']);
    }
    public function testHiddenResourceIsNotSentToProviderAndCanResumeWhenPublished(): void
    {
        $id=$this->create();wp_update_post(['ID'=>$id,'post_status'=>'ascla_hidden']);Queue::run();self::assertSame('paused',VideoPreparation::status($id)['status']);self::assertCount(0,$this->requests);wp_publish_post($id);self::assertCount(2,$this->automatic($id));
    }
    public function testRegenerationReusesTheSourcePreservesMaterialAndDeduplicatesClicks(): void
    {
        $id=$this->create();$source='';for($i=1;$i<=8;$i++)$source.='La idea '.$i.' del encuentro desarrolla el gobierno corporativo, la supervisión de riesgos y las responsabilidades del directorio con ejemplos aplicables a las decisiones de cada organización. ';
        $this->replies[]=$this->reply(200,['content'=>$source]);Queue::run();$meta=get_post_meta($id,'_ascla',true);$meta['summary']='Resumen anterior que sigue disponible.';$meta['technical_note']='Nota anterior que sigue disponible.';update_post_meta($id,'_ascla',wp_slash($meta));
        $first=VideoPreparation::regenerate($id);$second=VideoPreparation::regenerate($id);self::assertSame($first['id'],$second['id']);self::assertSame($meta['summary'],get_post_meta($id,'_ascla',true)['summary']);self::assertCount(2,$this->automatic($id));Queue::run();
        $updated=get_post_meta($id,'_ascla',true);self::assertSame('completed',VideoPreparation::status($id)['status']);self::assertNotSame($meta['summary'],$updated['summary']);self::assertSame(trim($source),$updated['transcript']);self::assertCount(1,$this->requests);self::assertGreaterThanOrEqual(3,count(explode("\n\n",$updated['summary'])));self::assertGreaterThan(180,count(preg_split('/\s+/u',$updated['summary'])));
    }
    public function testFailedRegenerationKeepsThePreviousSummaryAndNotifies(): void
    {
        $id=$this->create();$meta=get_post_meta($id,'_ascla',true);$meta['transcript']='Breve';$meta['transcript_status']='manual';$meta['summary']='Resumen previo conservado';$meta['technical_note']='Nota previa conservada';update_post_meta($id,'_ascla',$meta);VideoPreparation::regenerate($id);Queue::run();Queue::run();
        self::assertSame('error',VideoPreparation::status($id)['status']);self::assertSame($meta['summary'],get_post_meta($id,'_ascla',true)['summary']);self::assertSame($meta['technical_note'],get_post_meta($id,'_ascla',true)['technical_note']);self::assertSame(1,Store::count('notifications','user_id=%d AND kind=%s',[$this->users[0],'job_error']));self::assertCount(0,$this->requests);
    }
    public function testRegenerationRejectsMembersAndUnpublishedResources(): void
    {
        $id=$this->create('draft');wp_set_current_user($this->users[1]);
        try{VideoPreparation::regenerate($id);self::fail('Member regeneration allowed');}catch(\ASCLA\Core\Rest\ApiException $e){self::assertSame(403,$e->getCode());}
        wp_set_current_user($this->users[0]);try{VideoPreparation::regenerate($id);self::fail('Draft regeneration allowed');}catch(\ASCLA\Core\Rest\ApiException $e){self::assertSame(400,$e->getCode());}self::assertCount(0,$this->automatic($id));
    }
}
