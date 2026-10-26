<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Repositories\{Store,Transaction};
use ASCLA\Core\Integrations\Secrets;

/** One automatic pipeline per published source; private state is separate from editorial content. */
final class VideoPreparation
{
    private const META='_ascla_video_preparation';
    public static function configurationKey(): string
    {
        $s=Settings::get();$provider=$s['ai_provider'];
        $key=match($provider){'gemini'=>'ai_key','openai'=>'openai_key','deepseek'=>'deepseek_key',default=>''};
        return hash('sha256',wp_json_encode([$s['youtube_mode'],$s['supadata_generate'],$s['supadata_language'],Secrets::get('supadata_key'),$provider,$s['ai_model'],$s['openai_model'],$s['deepseek_model'],$key?Secrets::get($key):'']));
    }
    public static function retryConfigured(): void
    {
        foreach (Store::rows('jobs','kind=%s AND status=%s',['multimedia','error']) as $row) {
            $payload=json_decode($row['payload'],true)?:[];$id=(int)($payload['resource_id']??0);
            if (empty($payload['automatic'])||str_contains((string)$row['error'],'consumo')) { continue; }
            $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);$state=(array)get_post_meta($id,self::META,true);
            if (!$post||$post->post_status!=='publish'||!self::configured($meta)||(int)($state['job_id']??0)!==(int)$row['id']||self::signature($meta)!==($payload['source_signature']??'')) { continue; }
            Queue::retry((int)$row['id']);self::update($id,(int)$row['id'],['status'=>'pending','error'=>'']);
        }
    }
    public static function signature(array $meta): string
    {
        $manual=($meta['transcript_status']??'manual')==='supadata'?'':(string)($meta['transcript']??'');
        return hash('sha256',wp_json_encode([$meta['video_id']??'',$manual,!empty($meta['chatham']),$meta['identities']??'']));
    }
    private static function configured(array $meta): bool
    {
        $settings=Settings::get();
        return $settings['youtube_mode']==='supadata'||($settings['ai_provider']!=='mock'&&!empty($meta['transcript']));
    }
    public static function published(string $new,string $old,\WP_Post $post): void
    {
        if ($new==='publish'&&$old!=='publish'&&$post->post_type==='ascla_resource') {
            Transaction::afterCommit(static fn()=>self::schedule($post->ID));
        }
    }
    public static function schedule(int $id): void
    {
        $actor=self::automaticActor($id);
        if (!$actor||!Access::member($actor)||(!user_can($actor,'ascla_manage')&&!user_can($actor,'ascla_publish'))) { return; }
        Store::atomic('content:'.$id,static function()use($id,$actor){
            $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
            if (!$post||$post->post_type!=='ascla_resource'||$post->post_status!=='publish'||empty($meta['video_id'])||!self::configured($meta)) { return; }
            $signature=self::signature($meta);$state=(array)get_post_meta($id,self::META,true);
            if (($state['signature']??'')===$signature&&in_array($state['status']??'',['pending','processing','completed','error'],true)) { return; }
            $job=Queue::enqueue('multimedia',['resource_id'=>$id,'automatic'=>true,'source_signature'=>$signature],$actor);
            update_post_meta($id,self::META,['signature'=>$signature,'job_id'=>$job['id'],'status'=>'pending','error'=>'']);
        });
    }
    private static function automaticActor(int $id): int
    {
        $actor=get_current_user_id();
        if ($actor) { return $actor; }
        $candidates=array_merge([(int)get_post_field('post_author',$id)],array_map('intval',get_users(['capability'=>'ascla_configure','fields'=>'ID'])));
        foreach ($candidates as $candidate) {
            if (Access::member($candidate)&&(user_can($candidate,'ascla_manage')||user_can($candidate,'ascla_publish'))) { return $candidate; }
        }
        return 0;
    }
    public static function run(int $id,int $job,string $signature): array
    {
        $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);$state=(array)get_post_meta($id,self::META,true);
        if (!$post||$post->post_status!=='publish'||self::signature($meta)!==$signature||(int)($state['job_id']??0)!==$job) {
            self::update($id,$job,['status'=>'paused']);return ['resource_id'=>$id,'skipped'=>true];
        }
        self::update($id,$job,['status'=>'processing','error'=>'']);
        $result=Knowledge::multimedia($id,null,$signature);
        self::update($id,$job,['status'=>'completed','error'=>'']);return $result;
    }
    /** Explicit editorial refresh reuses the source and keeps the previous material until success. */
    public static function regenerate(int $id): array
    {
        Access::require(Access::member()&&Access::canPublish(),'Solo un Ejecutivo o un administrador pueden gestionar recursos.',403);
        return Store::atomic('content:'.$id,static function()use($id){
            $post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
            Access::require($post->post_type==='ascla_resource'&&$post->post_status==='publish','Selecciona un recurso publicado.',400);
            Access::require(!empty($meta['video_id'])||!empty($meta['transcript']),'El recurso no tiene video de YouTube ni transcripción autorizada.',400);
            $signature=self::signature($meta);$state=(array)get_post_meta($id,self::META,true);
            $active=Store::one('jobs',(int)($state['job_id']??0));
            if (($state['signature']??'')===$signature&&$active&&in_array($active['status'],['pending','processing'],true)) {
                return ['id'=>(int)$active['id'],'status'=>$active['status']];
            }
            $job=Queue::enqueue('multimedia',['resource_id'=>$id,'automatic'=>true,'source_signature'=>$signature]);
            update_post_meta($id,self::META,['signature'=>$signature,'job_id'=>$job['id'],'status'=>'pending','error'=>'']);
            return $job;
        });
    }
    public static function update(int $id,int $job,array $changes): bool
    {
        return Store::lock('content:'.$id,static function()use($id,$job,$changes){
            $state=(array)get_post_meta($id,self::META,true);
            if ((int)($state['job_id']??0)!==$job) { return false; }
            update_post_meta($id,self::META,array_merge($state,$changes));return true;
        });
    }
    public static function status(int $id): array
    {
        if (!Access::canPublish()) { return []; }
        $state=(array)get_post_meta($id,self::META,true);
        return $state?['status'=>$state['status']??'','job_id'=>(int)($state['job_id']??0),'error'=>(string)($state['error']??'')]:[];
    }
    public static function failed(array $row,array $payload,string $message): void
    {
        $id=(int)($payload['resource_id']??0);
        if (!self::update($id,(int)$row['id'],['status'=>'error','error'=>$message])) { return; }
        $recipients=array_unique(array_merge([(int)$row['user_id']],array_map('intval',get_users(['capability'=>'ascla_configure','fields'=>'ID']))));
        foreach ($recipients as $user) { Notifications::send($user,'job_error','No se pudo preparar el resumen del video. '.$message,'',['type'=>'job','id'=>(int)$row['id']]); }
    }
}
