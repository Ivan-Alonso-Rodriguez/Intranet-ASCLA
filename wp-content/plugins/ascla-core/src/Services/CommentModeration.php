<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** State, reason and audit are one decision; comment text is never copied to audit. */
final class CommentModeration
{
    public static function decide(int $id,string $decision,string $reason): array
    {
        Access::require(Access::member() && current_user_can('ascla_moderate'));
        Access::require(in_array($decision,['approve','reject'],true),'Decisión no válida.',400);
        $reason=trim(Access::text($reason,1000));
        Access::require($reason!=='','Indique un motivo de moderación.',400);
        return Store::atomic('comment:'.$id,static function()use($id,$decision,$reason){
            $comment=get_comment($id);
            Access::require($comment && in_array((string)$comment->comment_approved,['0','1'],true),'Comentario no válido.',404);
            Content::get((int)$comment->comment_post_ID);
            $previous=(array)get_comment_meta($id,'_ascla_moderation',true);
            $before=['state'=>(string)$comment->comment_approved,'reason'=>$previous['reason']??''];
            $status=$decision==='approve'?'approve':'hold';
            $expected=$decision==='approve'?'1':'0';
            if ((string)$comment->comment_approved!==$expected) {
                $saved=wp_set_comment_status($id,$status,true);
                Access::require(!is_wp_error($saved) && $saved===true,'No se pudo guardar la decisión.',500);
            }
            Access::require((string)get_comment($id)->comment_approved===$expected,'No se pudo guardar la decisión.',500);
            $moderation=['decision'=>$decision,'reason'=>$reason,'actor'=>get_current_user_id(),'at'=>gmdate('c')];
            update_comment_meta($id,'_ascla_moderation',$moderation);
            Access::require(get_comment_meta($id,'_ascla_moderation',true)===$moderation,'No se pudo guardar la decisión.',500);
            $after=['state'=>(string)get_comment($id)->comment_approved,'reason'=>$reason];
            Audit::changes('comment_moderation',$id,$before,$after,['kind'=>'comment','post_id'=>(int)$comment->comment_post_ID]);
            return ['ok'=>true,'id'=>$id,'status'=>$after['state']==='1'?'publish':'pending'];
        });
    }
}
