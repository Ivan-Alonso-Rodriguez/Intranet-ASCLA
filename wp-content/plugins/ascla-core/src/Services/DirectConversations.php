<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;

final class DirectConversations
{
    private const PAIR_FILTER='pair_key=%s';
    private const SINGLE_ROW='LIMIT 1';
    private const PARTICIPANT_FILTER='conversation_id=%d AND user_id=%d';
    public static function pairKey(int $a,int $b): string
    {
        $ids=[$a,$b]; sort($ids); return implode(':',$ids);
    }
    public static function ensureDirect(int $a,int $b,int $creator): array
    {
        $pair=self::pairKey($a,$b);
        $existing=Store::rows('conversations',self::PAIR_FILTER,[$pair],self::SINGLE_ROW);
        if ($existing) {
            $conversation=$existing[0];
            Access::require(($conversation['kind']??'direct')==='direct','No se puede reutilizar esta conversación.',409);
        } else {
            $id=Store::insert('conversations',[
                'pair_key'=>$pair,'kind'=>'direct','title'=>'','created_by'=>$creator,'photo_id'=>0,'updated_at'=>current_time('mysql',true),
            ]);
            $conversation=Store::one('conversations',$id);
        }
        foreach ([$a,$b] as $uid) {
            if (!Store::count('participants',self::PARTICIPANT_FILTER,[(int)$conversation['id'],$uid])) {
                Store::insert('participants',['conversation_id'=>(int)$conversation['id'],'user_id'=>$uid,'last_read'=>0]);
            }
        }
        return $conversation;
    }
}
