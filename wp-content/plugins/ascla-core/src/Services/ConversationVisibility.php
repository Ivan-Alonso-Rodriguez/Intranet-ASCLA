<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** A private inbox preference; hiding never deletes shared history. */
final class ConversationVisibility
{
    public static function hidden(int $conversationId,int $userId): bool
    {
        $lastHidden=get_user_meta($userId,'_ascla_hidden_chat_'.$conversationId,true);
        if ($lastHidden==='') { return false; }
        return Store::count('messages','conversation_id=%d AND id>%d AND sender_id<>%d',[$conversationId,(int)$lastHidden,$userId])===0;
    }

    public static function hide(int $id): array
    {
        Messaging::conversation($id);
        $last=Store::rows('messages','conversation_id=%d',[$id],'ORDER BY id DESC LIMIT 1')[0]??[];
        update_user_meta(get_current_user_id(),'_ascla_hidden_chat_'.$id,(int)($last['id']??0));
        return ['id'=>$id,'hidden'=>true];
    }

    public static function show(int $id): void
    {
        delete_user_meta(get_current_user_id(),'_ascla_hidden_chat_'.$id);
    }

    public static function read(int $id,int $last): array
    {
        Messaging::conversation($id);
        $message=Store::one('messages',$last);
        Access::require($message && (int)$message['conversation_id']===$id,'Mensaje no encontrado.',404);
        global $wpdb;
        $table=Store::table('participants');
        $wpdb->query($wpdb->prepare("UPDATE $table SET last_read=GREATEST(last_read,%d) WHERE conversation_id=%d AND user_id=%d",$last,$id,get_current_user_id()));
        return ['read'=>true];
    }
}
