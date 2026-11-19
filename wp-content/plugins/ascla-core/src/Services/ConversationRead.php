<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;

final class ConversationRead
{
    private const SINGLE_ROW='LIMIT 1';
    private const PARTICIPANT_FILTER='conversation_id=%d AND user_id=%d';
    private const CONVERSATION_FILTER='conversation_id=%d';
    private const ORDER_BY_ID_ASC='ORDER BY id ASC';
    private const NOT_FOUND='Conversación no encontrada.';
    public static function participant(int $id): array
    {
        $conversation=Store::one('conversations',$id);
        Access::require((bool)$conversation,self::NOT_FOUND,404);
        $me=get_current_user_id();
        $rows=Store::rows('participants',self::PARTICIPANT_FILTER,[$id,$me],self::SINGLE_ROW);
        Access::require((bool)$rows,self::NOT_FOUND,404);
        $members=Store::rows('participants',self::CONVERSATION_FILTER,[$id],self::ORDER_BY_ID_ASC);
        $kind=(string)($conversation['kind']??'direct');
        if ($kind==='group') {
            return $rows[0]+['conversation'=>$conversation,'members'=>$members,'kind'=>'group'];
        }
        $others=array_values(array_filter($members,static fn($member)=>(int)$member['user_id']!==$me));
        Access::require(count($others)===1,self::NOT_FOUND,404);
        $other=(int)$others[0]['user_id'];
        $permission=ConversationRequests::between($me,$other);
        $connected=Connections::areConnected($me,$other);
        Access::require(!Messaging::blocked($me,$other) && ($connected || ConnectionPolicy::canReadHistory($me,$other) || in_array($permission['state'],['allowed','incoming_pending','outgoing_pending'],true)),self::NOT_FOUND,404);
        return $rows[0]+['conversation'=>$conversation,'members'=>$members,'kind'=>'direct','other_id'=>$other,'permission'=>$permission];
    }
    public static function readState(int $id,int $me): array
    {
        $rows=Store::rows('participants','conversation_id=%d AND user_id<>%d',[$id,$me],self::ORDER_BY_ID_ASC);
        $out=[];
        foreach ($rows as $row) {
            $uid=(int)$row['user_id'];
            $card=Profiles::card($uid);
            $out[]=['user_id'=>$uid,'name'=>$card['name'],'last_read'=>(int)$row['last_read'],'joined_after'=>(int)($row['joined_after']??0)];
        }
        return $out;
    }
    public static function decorateMessages(array $rows,array $readState,int $me): array
    {
        foreach ($rows as &$message) {
            $message['sender_id']=(int)$message['sender_id'];
            $message['can_delete']=false;
            if ($message['kind']!=='message') {
                $message['body']=Messaging::messageText($message);
                continue;
            }
            $message['sender']=Profiles::card($message['sender_id']);
            if ($message['sender_id']===$me) {
                $recipients=array_filter($readState,static fn($state)=>$state['joined_after']<(int)$message['id']);
                $read=count(array_filter($recipients,static fn($state)=>$state['last_read']>=(int)$message['id']));
                $message['read_count']=$read;
                $message['read_total']=count($recipients);
            }
        }
        unset($message);
        return $rows;
    }
}
