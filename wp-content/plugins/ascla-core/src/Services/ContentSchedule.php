<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Repositories\{Transaction,WordPressWrites};

/** Institutional publication dates are stored in UTC, independently of approval. */
final class ContentSchedule
{
    private const HOOK='ascla_publish_content';
    private const TYPES=['event','gallery','resource','hub','ally'];
    private static int $publishing=0;

    public static function boot(): void
    {
        add_action(self::HOOK,[self::class,'publish'],10,2);
        add_action('before_delete_post',[self::class,'cancel']);
        add_action('wp_trash_post',[self::class,'cancel']);
        add_action('transition_post_status',[self::class,'transition'],20,3);
    }

    public static function prepare(string $type,array $input,string &$status,array &$meta): void
    {
        if (($input['status']??'')!=='scheduled') {
            unset($meta['publish_at'],$meta['publish_actor_id'],$meta['schedule_error']);
            return;
        }
        Access::require(in_array($type,self::TYPES,true) && Access::canPublish(),'No puedes programar este contenido.',403);
        $at=ContentMeta::eventDate($input['publish_at']??'');
        Access::require(strtotime($at)>time(),'La publicación debe programarse para una fecha futura.',400);
        Access::require($type!=='event' || strtotime($at)<strtotime($meta['start']),'Publica el evento antes de su inicio.',400);
        Access::require(empty($meta['cancelled']),'No se puede programar un evento cancelado.',409);
        $meta['publish_at']=$at;$meta['publish_actor_id']=get_current_user_id();
        unset($meta['schedule_error']);
        // A generated resource is explicitly reviewed when its editor schedules it.
        if ($type==='resource' && !empty($meta['generated'])) { $meta['reviewed']=true; }
        $approved=empty($meta['micro']) || (!empty($meta['micro_approved']) && current_user_can('ascla_manage'));
        $status=$approved?'ascla_hidden':'pending';
    }

    public static function moderation(string $decision,array &$meta,string $status): string
    {
        if ($decision!=='approve') {
            unset($meta['publish_at'],$meta['publish_actor_id'],$meta['schedule_error']);
            return $status;
        }
        if (!empty($meta['publish_at'])) {
            Access::require(strtotime($meta['publish_at'])>time(),'Actualiza la fecha de publicación antes de aprobar.',409);
            $meta['publish_actor_id']=get_current_user_id();
            return 'ascla_hidden';
        }
        // Approval is retained; an administrator must choose when to publish.
        return !empty($meta['micro'])?'ascla_hidden':$status;
    }

    public static function cancel(int $id): void
    {
        $at=(int)get_post_meta($id,'_ascla_publish_timestamp',true);
        if ($at) { wp_clear_scheduled_hook(self::HOOK,[$id,$at]); }
        WordPressWrites::deleteMeta('post',$id,'_ascla_publish_timestamp');
    }

    public static function sync(int $id): void
    {
        self::cancel($id);
        $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
        if (!$post || $post->post_status!=='ascla_hidden' || empty($meta['publish_at']) || !empty($meta['schedule_error'])) { return; }
        if (!empty($meta['micro']) && empty($meta['micro_approved'])) { return; }
        $at=strtotime($meta['publish_at']);
        if (!$at) { return; }
        $result=wp_schedule_single_event(max(time()+1,$at),self::HOOK,[$id,$at],true);
        if (is_wp_error($result) || !$result) {
            self::failed($id,$meta,'No se pudo programar la publicación. Vuelve a guardar la fecha.');
            return;
        }
        WordPressWrites::meta('post',$id,'_ascla_publish_timestamp',$at);
    }

    public static function transition(string $new,string $old,\WP_Post $post): void
    {
        unset($old);
        if ($new!=='ascla_hidden' && str_starts_with($post->post_type,'ascla_')) { self::cancel($post->ID); }
    }

    public static function publishing(int $id): bool { return self::$publishing===$id && $id>0; }

    public static function publish(int $id,int $expected): void
    {
        $post=get_post($id);
        if (!$post) { return; }
        $lock=($post->post_type==='ascla_event'?'event:':'content:').$id;
        Store::lock($lock,static function()use($id,$expected){
            try { Transaction::run(static fn()=>self::publishLocked($id,$expected)); }
            catch (\Throwable $error) {
                $reason=$error instanceof \ASCLA\Core\Rest\ApiException?$error->getMessage():'No se pudo publicar el contenido programado. Vuelve a guardar la fecha.';
                Transaction::run(static fn()=>self::failed($id,(array)get_post_meta($id,'_ascla',true),$reason));
            }
        });
    }

    private static function publishLocked(int $id,int $expected): void
    {
        clean_post_cache($id);
        $post=get_post($id);$meta=(array)get_post_meta($id,'_ascla',true);
        if (!$post || $post->post_status!=='ascla_hidden' || strtotime($meta['publish_at']??'')!==$expected) { return; }
        if ($expected>time()) { self::sync($id);return; }
        try {
            self::validate($post,$meta);
            self::$publishing=$id;
            $result=wp_update_post(['ID'=>$id,'post_status'=>'publish'],true);
            Access::require(!is_wp_error($result) && get_post_status($id)==='publish','No se pudo publicar el contenido programado.',500);
            // Publication hooks can add invitations and lifecycle dates; preserve them.
            $meta=(array)get_post_meta($id,'_ascla',true);
            $meta['published_at']=gmdate('c');unset($meta['publish_at'],$meta['schedule_error']);
            WordPressWrites::meta('post',$id,'_ascla',$meta);
            Audit::record('content_scheduled_published',$id,'scheduled_by='.(int)$meta['publish_actor_id']);
        } finally { self::$publishing=0; }
    }

    private static function validate(\WP_Post $post,array $meta): void
    {
        $actor=(int)($meta['publish_actor_id']??0);$type=substr($post->post_type,6);
        Access::require(in_array($type,self::TYPES,true) && $actor>0 && Access::member($actor)
            && (user_can($actor,'ascla_publish') || user_can($actor,'ascla_manage')),'La cuenta que programó la publicación ya no tiene permisos.',403);
        Access::require($type!=='ally' || user_can($actor,'ascla_manage'),'La publicación de aliados requiere administración.',403);
        Access::require(empty($meta['generated']) || !empty($meta['reviewed']),'El contenido necesita revisión humana.',409);
        if ($type!=='event') { return; }
        Access::require(empty($meta['cancelled']) && strtotime($meta['start'])>time(),'El evento fue cancelado o su fecha de inicio ya pasó.',409);
        if (empty($meta['micro'])) { return; }
        Access::require(!empty($meta['micro_approved']),'El microevento necesita aprobación administrativa.',409);
        Access::require(strtotime($meta['registration_deadline'])>time(),'El plazo de inscripción del microevento ha finalizado.',409);
        MicroEvents::validateInvitees($meta);
    }

    private static function failed(int $id,array $meta,string $reason): void
    {
        $meta['schedule_error']=$reason;
        WordPressWrites::meta('post',$id,'_ascla',$meta);self::cancel($id);
        Audit::record('content_schedule_failed',$id,$reason);
    }
}
