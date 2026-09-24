<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** Report lifecycle uses the same lock as reporting, so a reopened report cannot be deleted. */
final class Reports
{
    private static function get(int $id): array
    {
        Access::require(Access::member() && current_user_can('ascla_moderate'));
        $report=Store::one('relations',$id);
        Access::require($report && in_array($report['kind'],['report','comment_report'],true),'Reporte no encontrado.',404);
        $comment=$report['kind']==='comment_report'?get_comment((int)$report['target_id']):null;
        $postId=(int)$report['target_id'];
        if($report['kind']==='comment_report'){$postId=$comment?(int)$comment->comment_post_ID:0;}
        $post=$postId>0?get_post($postId):null;
        Access::require(current_user_can('ascla_manage') || ($post && Content::canRead($post)),'Reporte no encontrado.',404);
        return $report;
    }
    private static function locked(int $id,callable $callback): array
    {
        $r=self::get($id);
        $key=($r['kind']==='comment_report'?'comment-report:':'report:').$r['user_id'].':'.$r['target_id'].':'.$r['kind'];
        return Store::lock($key,static fn()=>$callback(self::get($id)));
    }
    public static function review(int $id): array
    {
        return self::locked($id,static function($r)use($id){
            $when=current_time('mysql',true);
            Store::update('relations',['reviewed_at'=>$when,'reviewed_by'=>get_current_user_id()],['id'=>$id]);
            Audit::record('report_reviewed',$id,$r['kind']);
            return ['id'=>$id,'reviewed'=>true,'reviewed_at'=>$when];
        });
    }
    public static function remove(int $id): array
    {
        return self::locked($id,static function($r)use($id){
            Access::require(!empty($r['reviewed_at']),'Revisa el reporte antes de eliminarlo.',409);
            global $wpdb;
            Access::require($wpdb->delete(Store::table('relations'),['id'=>$id])===1,'No se pudo eliminar el reporte.',500);
            Audit::record('report_deleted',$id,$r['kind'].'; target='.$r['target_id']);
            return ['id'=>$id,'deleted'=>true];
        });
    }
}
