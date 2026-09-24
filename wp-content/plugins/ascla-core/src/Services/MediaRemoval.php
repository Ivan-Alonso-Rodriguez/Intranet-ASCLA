<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** Internal cleanup used by Media::remove so the public service stays focused. */
final class MediaRemoval
{
    private static function clearPostReferences(int $id): void
    {
        global $wpdb;
        $posts=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value LIKE %s",'_ascla','%media_ids%'));
        foreach ($posts as $postId) {
            $meta=(array)get_post_meta($postId,'_ascla',true);$ids=array_map('intval',(array)($meta['media_ids']??[]));
            if (!in_array($id,$ids,true)) { continue; }
            $meta['media_ids']=array_values(array_diff($ids,[$id]));update_post_meta($postId,'_ascla',$meta);
        }
    }
    private static function clearProfileReference(array $file,int $id): void
    {
        $profile=(array)get_user_meta($file['user_id'],'_ascla_profile',true);
        if ((int)($profile['photo_id']??0)!==$id) { return; }
        $profile['photo_id']=0;$profile['revision']=(int)($profile['revision']??0)+1;update_user_meta($file['user_id'],'_ascla_profile',$profile);
    }
    private static function deleteUnusedMaster(array $file): bool
    {
        $originalId=(int)($file['original_id']??0);
        if ($originalId<=0 || Store::count('media','original_id=%d',[$originalId])!==0) { return false; }
        $source=Store::one('media',$originalId);
        if (!$source || (int)$source['user_id']!==(int)$file['user_id'] || (int)$source['post_id']>0) { return false; }
        Store::delete('media',['id'=>$originalId]);return true;
    }
    public static function run(int $id,array $file): array
    {
        global $wpdb;
        self::clearPostReferences($id);self::clearProfileReference($file,$id);
        $conversations=Store::table('conversations');$wpdb->query($wpdb->prepare("UPDATE $conversations SET photo_id=0 WHERE photo_id=%d",$id));
        Store::delete('media',['id'=>$id]);$deletedSource=self::deleteUnusedMaster($file);
        Audit::record('media_deleted',$id,$deletedSource?'master_removed':'');
        return ['id'=>$id,'deleted'=>true,'master_deleted'=>$deletedSource];
    }
}
