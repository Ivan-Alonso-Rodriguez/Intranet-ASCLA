<?php
namespace ASCLA\Core\Jobs;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\{Access,Audit,Knowledge,MicroEvents,Notifications,Content,Discovery,Birthdays};
final class Queue
{
    private const DEMO_MODE='DEMO MODE';
    public static function boot(): void { add_action('ascla_jobs',[self::class,'run']); add_action('ascla_jobs',[Birthdays::class,'maybeProcess'],20); add_action('ascla_jobs_continue',[self::class,'run']); add_action('ascla_monthly',[MicroEvents::class,'monthly']); add_action('ascla_discovery',static fn()=>self::enqueue('discovery',[],0)); }
    public static function enqueue(string $kind,array $payload,int $user=-1): array
    {
        Access::require(in_array($kind,['multimedia','answer','microevents','social','resource_notifications','discovery','video_metadata'],true),'Trabajo no válido.',400);
        $id=Store::insert('jobs',['kind'=>$kind,'user_id'=>$user<0?get_current_user_id():$user,'payload'=>wp_json_encode($payload),'status'=>'pending','created_at'=>current_time('mysql',true)]);
        self::wake(); return ['id'=>$id,'status'=>'pending'];
    }
    public static function get(int $id): array
    {
        $row=Store::one('jobs',$id); Access::require($row && ((int)$row['user_id']===get_current_user_id()||current_user_can('ascla_manage')),'Trabajo no encontrado.',404);
        $payload=json_decode($row['payload'],true)?:[];
        unset($row['payload']); $row['result']=json_decode($row['result']??'null',true);
        if ($row['kind']==='microevents' && is_array($row['result'])) { $row['result']=MicroEvents::sanitizeResult($row['result']); }
        if ($row['kind']==='answer') {
            $row['question']=Access::text($payload['question']??'',2000);
            $row['thread']=self::threadKey((string)($payload['thread']??'legacy'));
            if ($row['status']==='completed' && is_array($row['result'])) { $row['result']=Knowledge::storedAnswer($row['result']); }
        }
        return $row;
    }
    public static function answers(): array
    {
        $rows=Store::rows('jobs','user_id=%d AND kind=%s',[get_current_user_id(),'answer'],'ORDER BY id DESC LIMIT 50');
        return array_map(static function($row) {
            $payload=json_decode($row['payload'],true)?:[];
            return ['id'=>(int)$row['id'],'question'=>Access::text($payload['question']??'Consulta al asistente',2000),'thread'=>self::threadKey((string)($payload['thread']??'legacy')),'status'=>$row['status'],'created_at'=>$row['created_at']];
        },$rows);
    }
    public static function thread(string $thread): array
    {
        $thread=self::threadKey($thread);Access::require($thread!=='','Conversación no válida.',400);
        $rows=Store::rows('jobs','user_id=%d AND kind=%s',[get_current_user_id(),'answer'],'ORDER BY id DESC LIMIT 100');$items=[];
        foreach($rows as $row){
            $payload=json_decode($row['payload'],true)?:[];
            if(self::threadKey((string)($payload['thread']??'legacy'))!==$thread) {continue; }
            $result=json_decode($row['result']??'null',true);
            if($row['status']==='completed'&&is_array($result)) {$result=Knowledge::storedAnswer($result); }
            $items[]=['id'=>(int)$row['id'],'question'=>Access::text($payload['question']??'',2000),'status'=>$row['status'],'error'=>$row['error']??'','result'=>$result,'created_at'=>$row['created_at']];
            if(count($items)>=30) {break; }
        }
        return array_reverse($items);
    }
    private static function history(int $user,string $thread,int $beforeId): array
    {
        $thread=self::threadKey($thread);if($thread==='') {return []; }
        $rows=Store::rows('jobs','user_id=%d AND kind=%s AND status=%s AND id<%d',[$user,'answer','completed',$beforeId],'ORDER BY id DESC LIMIT 40');$turns=[];
        foreach($rows as $row){
            $payload=json_decode($row['payload'],true)?:[];
            if(self::threadKey((string)($payload['thread']??'legacy'))!==$thread) {continue; }
            $result=json_decode($row['result']??'null',true);
            if(!is_array($result)||trim((string)($result['answer']??''))==='') {continue; }
            $turns[]=['question'=>Access::text($payload['question']??'',2000),'answer'=>Access::text($result['answer'],20000)];
            if(count($turns)>=6) {break; }
        }
        return array_reverse($turns);
    }
    public static function threadKey(string $value): string
    {
        $value=preg_replace('/[^a-zA-Z0-9_-]/','',$value)??'';
        return strlen($value)>=6&&strlen($value)<=80?$value:'';
    }
    public static function retry(int $id): array
    {
        $row=self::get($id); Access::require($row['status']==='error','Sólo se pueden reintentar trabajos fallidos.',400);
        Store::update('jobs',['status'=>'pending','attempts'=>0,'error'=>null,'locked_at'=>null],['id'=>$id]); self::wake(); return self::get($id);
    }
    private static function authorizeJob(array $row): void
    {
        $kind=(string)$row['kind'];$user=(int)$row['user_id'];
        if (!in_array($kind,['microevents','resource_notifications','discovery'],true) || $user!==0) { Access::require(Access::member(),'La cuenta ya no tiene acceso.'); }
        if ($kind==='microevents' && $user!==0) { Access::require(Access::canPublish(),'Permiso de publicación revocado.'); }
        if ($kind==='social') { Access::require(current_user_can('ascla_moderate'),'Permiso de moderación revocado.'); }
        if (in_array($kind,['multimedia','video_metadata'],true)) { Access::require(Access::canPublish(),'Permiso de publicación revocado.'); }
    }

    private static function executeJob(array $row,array $payload): array
    {
        return match($row['kind']) {
            'answer'=>Knowledge::answer($payload['question']??'',self::history((int)$row['user_id'],(string)($payload['thread']??'legacy'),(int)$row['id'])),
            'multimedia'=>Knowledge::multimedia((int)($payload['resource_id']??0)),
            'microevents'=>MicroEvents::create(),
            'social'=>self::social(),
            'resource_notifications'=>Discovery::resource((int)($payload['resource_id']??0)),
            'discovery'=>Discovery::networking(),
            'video_metadata'=>Knowledge::videoMetadata((int)($payload['resource_id']??0)),
        };
    }

    private static function completeJob(array $row,array $result): void
    {
        Store::update('jobs',['status'=>'completed','result'=>wp_json_encode($result),'error'=>null,'locked_at'=>null],['id'=>$row['id']]);
        Notifications::send((int)$row['user_id'],'job','Tu trabajo en segundo plano ha finalizado.','',['type'=>'job','id'=>(int)$row['id']]);
        Audit::record('job_completed',(int)$row['id'],$row['kind']);
    }

    private static function failJob(array $row,\Throwable $error): void
    {
        $safe=$error instanceof \ASCLA\Core\Rest\ApiException||$error instanceof \RuntimeException?$error->getMessage():'No se pudo completar el trabajo. Revise la configuración o reintente.';
        Store::update('jobs',['status'=>'error','error'=>substr(sanitize_text_field($safe),0,255),'locked_at'=>null],['id'=>$row['id']]);
        Audit::record('job_failed',(int)$row['id'],$row['kind']);
        Notifications::send((int)$row['user_id'],'job_error','Una tarea necesita revisión.','',['type'=>'job','id'=>(int)$row['id']]);
    }

    private static function processRow(array $row,string $table,int $original): void
    {
        global $wpdb;
        $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='processing', attempts=attempts+1, locked_at=UTC_TIMESTAMP() WHERE id=%d AND status='pending'",$row['id']));
        if ($claimed!==1) { return; }
        try {
            wp_set_current_user((int)$row['user_id']);$payload=json_decode($row['payload'],true)?:[];
            self::authorizeJob($row);
            self::completeJob($row,self::executeJob($row,$payload));
        } catch (\Throwable $error) {
            self::failJob($row,$error);
        } finally {
            wp_set_current_user($original);
        }
    }

    public static function run(): void
    {
        global $wpdb;$table=Store::table('jobs');
        $wpdb->query("UPDATE $table SET status='error', error='Trabajo interrumpido. Reintente desde administración.' WHERE status='processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE");
        $rows=Store::rows('jobs',"status='pending' AND attempts<3",[],'ORDER BY id ASC LIMIT 2');$original=get_current_user_id();
        foreach ($rows as $row) { self::processRow($row,$table,$original); }
        // WordPress deduplicates identical single events; use a continuation hook for backlog.
        if (Store::count('jobs','status=%s AND attempts<3',['pending'])) { self::wake(5); }
    }

    /** A dedicated one-shot wakeup avoids WordPress deduplicating against the hourly safety event. */
    private static function wake(int $delay=1): void
    {
        Store::lock('queue-wakeup',static function()use($delay){
            $at=time()+$delay;$next=wp_next_scheduled('ascla_jobs_continue');
            if($next && $next>time() && $next<=$at) {return; }
            // CLI may still expose the currently executing tick. Replace it before scheduling its successor.
            if($next) {wp_unschedule_event($next,'ascla_jobs_continue'); }
            wp_schedule_single_event($at,'ascla_jobs_continue');
        });
    }
    private static function social(): array
    {
        $posts=(new \ASCLA\Core\Integrations\MockSocialProvider())->posts('demo'); $items=[];
        foreach ($posts as $post) {
            $result=Knowledge::provider()->generate('social',['text'=>$post['text']]);
            if (!empty($result['relevant'])&&empty($result['commercial'])) {
                $item=Content::save('hub',['title'=>'Conversación sobre gobernanza · DEMO','body'=>$post['text'],'status'=>'draft']);
                $meta=(array)get_post_meta($item['id'],'_ascla',true); $meta['generated']=true; $meta['reviewed']=false; $meta['social_mode']=self::DEMO_MODE; update_post_meta($item['id'],'_ascla',$meta);
                $items[]=['draft_id'=>$item['id'],'suggested_reply'=>$result['suggested_reply']??'','mode'=>self::DEMO_MODE];
            }
        }
        return ['items'=>$items,'rejected'=>count($posts)-count($items),'mode'=>self::DEMO_MODE,'sent_externally'=>false];
    }
}
