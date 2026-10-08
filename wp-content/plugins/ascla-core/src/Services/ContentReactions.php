<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** One predefined reaction per member; legacy likes remain valid without a migration. */
final class ContentReactions
{
    public const TYPES=['like','useful','celebrate'];

    public static function summary(int $id,bool $comment=false): array
    {
        global $wpdb;
        $kind=$comment?'comment_like':'like';
        $rows=$wpdb->get_results($wpdb->prepare('SELECT reason,COUNT(*) AS total,SUM(user_id=%d) AS mine FROM '.Store::table('relations').' WHERE target_id=%d AND kind=%s GROUP BY reason',get_current_user_id(),$id,$kind),ARRAY_A)?:[];
        $counts=array_fill_keys(self::TYPES,0);$mine='';
        foreach ($rows as $row) {
            $type=in_array($row['reason'],self::TYPES,true)?$row['reason']:'like';
            $counts[$type]+=(int)$row['total'];
            if ((int)$row['mine']>0) { $mine=$type; }
        }
        return ['reaction'=>$mine,'reaction_counts'=>$counts,'reaction_total'=>array_sum($counts)];
    }

    public static function set(int $id,bool $active,string $reaction='like',bool $comment=false): array
    {
        Access::require(in_array($reaction,self::TYPES,true),'Selecciona una reacción válida.',400);
        $target=$comment?get_comment($id):null;
        if ($comment) { Access::require($target && (string)$target->comment_approved==='1','Comentario no encontrado.',404); }
        $post=Content::get($comment?(int)$target->comment_post_ID:$id);
        Access::require($post->post_status==='publish','El contenido aún no está publicado.',400);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$id,'kind'=>$comment?'comment_like':'like'];
        $result=Store::lock('content-reaction:'.implode(':',$where),static function()use($id,$active,$reaction,$comment,$where){
            $existing=Store::rows('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where),'LIMIT 1')[0]??null;
            if (!$active) { Store::delete('relations',$where); }
            elseif ($existing) { Store::update('relations',['reason'=>$reaction],['id'=>(int)$existing['id']]); }
            else { Store::insert('relations',$where+['reason'=>$reaction,'created_at'=>current_time('mysql',true)]); }
            return self::summary($id,$comment)+['first'=>$active && !$existing];
        });
        if (!$comment && $result['first']) {
            Notifications::send((int)$post->post_author,'reaction','Tu publicación recibió una reacción.',\ASCLA\Core\Domain\Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$post->ID]),['type'=>'post','id'=>$post->ID,'actor'=>get_current_user_id()]);
        }
        unset($result['first']);
        KnowledgeRecommendations::invalidateActivity(get_current_user_id());
        return $result+['active'=>$result['reaction']!=='','likes'=>$result['reaction_total']];
    }
}
