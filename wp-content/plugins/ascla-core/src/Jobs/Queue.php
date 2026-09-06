<?php
namespace ASCLA\Core\Jobs;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\{Access,Audit,Knowledge,MicroEvents,Notifications,Content,Discovery};
final class Queue
{
    public static function boot(): void { add_action('ascla_jobs',[self::class,'run']); add_action('ascla_jobs_continue',[self::class,'run']); add_action('ascla_monthly',[MicroEvents::class,'monthly']); add_action('ascla_discovery',static fn()=>self::enqueue('discovery',[],0)); }
    public static function enqueue(string $kind,array $payload,int $user=-1): array
    {
        Access::require(in_array($kind,['multimedia','answer','microevents','social','resource_notifications','discovery','video_metadata'],true),'Trabajo no válido.',400);
        $id=Store::insert('jobs',['kind'=>$kind,'user_id'=>$user<0?get_current_user_id():$user,'payload'=>wp_json_encode($payload),'status'=>'pending','created_at'=>current_time('mysql',true)]);
        wp_schedule_single_event(time()+1,'ascla_jobs'); return ['id'=>$id,'status'=>'pending'];
    }
    public static function get(int $id): array
    {
        $row=Store::one('jobs',$id); Access::require($row && ((int)$row['user_id']===get_current_user_id()||current_user_can('ascla_moderate')),'Trabajo no encontrado.',404);
        unset($row['payload']); $row['result']=json_decode($row['result']??'null',true); return $row;
    }
    public static function retry(int $id): array
    {
        $row=self::get($id); Access::require($row['status']==='error','Sólo se pueden reintentar trabajos fallidos.',400);
        Store::update('jobs',['status'=>'pending','attempts'=>0,'error'=>null,'locked_at'=>null],['id'=>$id]); wp_schedule_single_event(time()+1,'ascla_jobs'); return self::get($id);
    }
    public static function run(): void
    {
        global $wpdb; $table=Store::table('jobs');
        $wpdb->query("UPDATE $table SET status='error', error='Trabajo interrumpido. Reintente desde administración.' WHERE status='processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE");
        $rows=Store::rows('jobs',"status='pending' AND attempts<3",[],'ORDER BY id ASC LIMIT 2'); $original=get_current_user_id();
        foreach ($rows as $row) {
            $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='processing', attempts=attempts+1, locked_at=UTC_TIMESTAMP() WHERE id=%d AND status='pending'",$row['id']));
            if ($claimed!==1) { continue; }
            try {
                wp_set_current_user((int)$row['user_id']); $p=json_decode($row['payload'],true)?:[];
                if (!in_array($row['kind'],['microevents','resource_notifications','discovery'],true) || (int)$row['user_id']!==0) { Access::require(Access::member(),'La cuenta ya no tiene acceso.'); }
                if ($row['kind']==='microevents' && (int)$row['user_id']!==0) { Access::require(current_user_can('ascla_manage'),'Permiso de administración revocado.'); }
                if (in_array($row['kind'],['multimedia','social','video_metadata'],true)) { Access::require(current_user_can('ascla_moderate'),'Permiso de moderación revocado.'); }
                $result=match($row['kind']) {
                    'answer'=>Knowledge::answer($p['question']??''),
                    'multimedia'=>Knowledge::multimedia((int)($p['resource_id']??0)),
                    'microevents'=>MicroEvents::create(),
                    'social'=>self::social(),
                    'resource_notifications'=>Discovery::resource((int)($p['resource_id']??0)),
                    'discovery'=>Discovery::networking(),
                    'video_metadata'=>Knowledge::videoMetadata((int)($p['resource_id']??0)),
                };
                Store::update('jobs',['status'=>'completed','result'=>wp_json_encode($result),'error'=>null,'locked_at'=>null],['id'=>$row['id']]);
                Notifications::send((int)$row['user_id'],'job','Tu trabajo en segundo plano ha finalizado.'); Audit::record('job_completed',(int)$row['id'],$row['kind']);
            } catch (\Throwable $e) {
                $safe=$e instanceof \ASCLA\Core\Rest\ApiException||get_class($e)===\RuntimeException::class?$e->getMessage():'No se pudo completar el trabajo. Revise la configuración o reintente.';
                Store::update('jobs',['status'=>'error','error'=>substr(sanitize_text_field($safe),0,255),'locked_at'=>null],['id'=>$row['id']]); Audit::record('job_failed',(int)$row['id'],$row['kind']);
            } finally { wp_set_current_user($original); }
        }
        // WordPress deduplicates identical single events; use a continuation hook for backlog.
        if (Store::count('jobs','status=%s AND attempts<3',['pending']) && !wp_next_scheduled('ascla_jobs_continue')) {
            wp_schedule_single_event(time()+5,'ascla_jobs_continue');
        }
    }
    private static function social(): array
    {
        $posts=(new \ASCLA\Core\Integrations\MockSocialProvider())->posts('demo'); $items=[];
        foreach ($posts as $post) {
            $result=Knowledge::provider()->generate('social',['text'=>$post['text']]);
            if (!empty($result['relevant'])&&empty($result['commercial'])) {
                $item=Content::save('hub',['title'=>'Conversación sobre gobernanza · DEMO','body'=>$post['text'],'status'=>'draft']);
                $meta=(array)get_post_meta($item['id'],'_ascla',true); $meta['generated']=true; $meta['reviewed']=false; $meta['social_mode']='DEMO MODE'; update_post_meta($item['id'],'_ascla',$meta);
                $items[]=['draft_id'=>$item['id'],'suggested_reply'=>$result['suggested_reply']??'','mode'=>'DEMO MODE'];
            }
        }
        return ['items'=>$items,'rejected'=>count($posts)-count($items),'mode'=>'DEMO MODE','sent_externally'=>false];
    }
}
