<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\EntityRedactor;
use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Repositories\{Store,WordPressWrites};

/** Refresh discussion prompts from the saved event without replacing editorial changes. */
final class MicroProposalText
{
    private const META='_ascla_micro_text';

    private static function input(\WP_Post $post,array $meta): array
    {
        $topics=wp_get_object_terms($post->ID,'ascla_interest',['fields'=>'names']);
        return [
            'title'=>$post->post_title,'description'=>$post->post_content,
            'start'=>$meta['start']??'','end'=>$meta['end']??'',
            'modality'=>$meta['modality']??'Virtual','location'=>$meta['location']??'',
            'agenda'=>$meta['agenda']??'','capacity'=>$meta['capacity']??0,
            'topics'=>is_wp_error($topics)?[]:$topics,
            'group_size'=>count((array)($meta['invitees']??[])),
        ];
    }

    public static function signature(\WP_Post $post,array $meta): string
    {
        return hash('sha256',wp_json_encode([self::input($post,$meta),$meta['invitees']??[]]));
    }

    public static function markCurrent(int $id): void
    {
        $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
        WordPressWrites::meta('post',$id,self::META,['signature'=>self::signature($post,$meta),'job_id'=>0]);
    }

    public static function schedule(int $id,bool $force=false): array
    {
        Access::require(Access::member() && Access::canPublish());
        return Store::atomic('event:'.$id,static function()use($id,$force){
            $post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
            Access::require($post->post_type==='ascla_event' && !empty($meta['micro']),'Selecciona un microevento.',400);
            Access::require(Content::canEdit($post),'No puede editar este contenido.',403);
            if (!empty($meta['cancelled'])) { return []; }
            $signature=self::signature($post,$meta);
            $state=(array)get_post_meta($id,self::META,true);
            $job=Store::one('jobs',(int)($state['job_id']??0));
            if (($state['signature']??'')===$signature && (!$force || ($job && in_array($job['status'],['pending','processing'],true)))) {
                return self::status($id);
            }
            $created=Queue::enqueue('microevent_copy',['event_id'=>$id,'signature'=>$signature]);
            WordPressWrites::meta('post',$id,self::META,['signature'=>$signature,'job_id'=>$created['id']]);
            return self::status($id);
        });
    }

    public static function current(int $id): bool
    {
        $state=(array)get_post_meta($id,self::META,true);
        if (!$state) { return true; }
        $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
        if (!$post || self::signature($post,$meta)!==($state['signature']??'')) { return false; }
        $jobId=(int)($state['job_id']??0);
        return !$jobId || !empty($state['ready']);
    }

    public static function status(int $id): array
    {
        if (!Access::canPublish()) { return []; }
        $state=(array)get_post_meta($id,self::META,true);
        $job=Store::one('jobs',(int)($state['job_id']??0));
        if ($job && (int)$job['user_id']!==get_current_user_id() && !current_user_can('ascla_manage')) { return []; }
        return $job?['job_id'=>(int)$job['id'],'status'=>$job['status'],'error'=>(string)($job['error']??'')]:[];
    }

    public static function run(int $id,int $job,string $signature): array
    {
        Access::require(Access::member() && Access::canPublish());
        $post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
        $state=(array)get_post_meta($id,self::META,true);
        if (empty($meta['micro']) || !empty($meta['cancelled']) || (int)($state['job_id']??0)!==$job || self::signature($post,$meta)!==$signature) {
            return ['event_id'=>$id,'skipped'=>true];
        }
        $event=self::input($post,$meta);
        if (!empty($meta['chatham'])) {
            $identities=preg_split('/[\n,;]+/u',(string)($meta['identities']??''))?:[];
            $event=EntityRedactor::tree($event,$identities,[$event['title']]);
        }
        $result=NetworkingAI::generate('microevent_copy',['event'=>$event]);
        Access::require(empty($result['fallback']),'No se pudieron actualizar los textos de la propuesta. Puedes volver a intentarlo.',502);
        return Store::atomic('event:'.$id,static function()use($id,$job,$signature,$result){
            clean_post_cache($id);
            $post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
            $state=(array)get_post_meta($id,self::META,true);
            if (!empty($meta['cancelled']) || (int)($state['job_id']??0)!==$job || self::signature($post,$meta)!==$signature) {
                return ['event_id'=>$id,'skipped'=>true];
            }
            $copy=EntityRedactor::cleanTree($result);
            if (!empty($meta['chatham'])) {
                $copy=EntityRedactor::tree($copy,preg_split('/[\n,;]+/u',(string)($meta['identities']??''))?:[],[$post->post_title]);
            }
            $duration=(strtotime($meta['end']??'')-strtotime($meta['start']??''))/60;
            $meta['agenda_ai']=[
                'title'=>$post->post_title,'theme'=>$post->post_title,
                'objective'=>$copy['objective'],'icebreaker'=>$copy['icebreaker'],
                'closing_question'=>$copy['closing_question'],'mode'=>$copy['mode']??'ASCLA',
                'duration_minutes'=>$duration>0?(int)$duration:(int)($meta['agenda_ai']['duration_minutes']??0),
                'agenda'=>[],'fallback'=>false,
            ];
            WordPressWrites::meta('post',$id,'_ascla',$meta,true);
            WordPressWrites::meta('post',$id,self::META,array_replace($state,['ready'=>true]));
            Audit::record('microevent_text_refreshed',$id);
            return ['event_id'=>$id,'refreshed'=>true];
        });
    }
}
