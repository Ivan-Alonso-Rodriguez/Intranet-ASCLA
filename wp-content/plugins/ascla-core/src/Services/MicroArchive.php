<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\{Store,WordPressWrites};

/** Retire terminal encounters from the main administration list, preserving their records. */
final class MicroArchive
{
    public static function canArchive(\WP_Post $post,array $meta): bool
    {
        $state=(string)get_post_meta($post->ID,'_ascla_micro_state',true);
        return Access::member() && current_user_can('ascla_manage') && $post->post_type==='ascla_event'
            && !empty($meta['micro']) && (!empty($meta['cancelled']) || in_array($state,['denied','finished'],true));
    }

    public static function set(int $id,bool $archived): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'),'Solo administración puede archivar microeventos.',403);
        return Store::atomic('event:'.$id,static function()use($id,$archived){
            clean_post_cache($id);$post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
            Access::require(self::canArchive($post,$meta),'Cancela o deniega la propuesta antes de archivarla.',409);
            if ((bool)get_post_meta($id,'_ascla_micro_archived',true)!==$archived) {
                WordPressWrites::meta('post',$id,'_ascla_micro_archived',$archived);
                WordPressWrites::meta('post',$id,'_ascla_micro_archive',['archived'=>$archived,'actor'=>get_current_user_id(),'at'=>gmdate('c')]);
                Audit::record($archived?'microevent_archived':'microevent_restored',$id);
            }
            return Content::serialize($post);
        });
    }
}
