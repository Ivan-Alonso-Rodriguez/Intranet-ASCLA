<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** RN-010, RN-024 and RN-054: consent and cooldown survive relation removal. */
final class ConnectionPolicy
{
    private const PAIR="((user_id=%d AND target_id=%d) OR (user_id=%d AND target_id=%d))";

    public static function retryAt(int $sender,int $target): int
    {
        $rows=Store::rows('relations',"kind='connection_cooldown' AND user_id=%d AND target_id=%d",[$sender,$target],'ORDER BY created_at DESC LIMIT 1');
        return $rows?max(0,(int)strtotime($rows[0]['created_at'].' UTC')+30*DAY_IN_SECONDS):0;
    }

    public static function cooldown(int $sender,int $target,string $reason='rejected'): void
    {
        $where=['user_id'=>$sender,'target_id'=>$target,'kind'=>'connection_cooldown'];
        Store::delete('relations',$where);
        Store::insert('relations',$where+['reason'=>$reason,'created_at'=>current_time('mysql',true)]);
    }

    public static function visibleRetryAt(int $sender,int $target): ?string
    {
        $row=Store::rows('relations',"kind='connection_cooldown' AND user_id=%d AND target_id=%d",[$sender,$target],'LIMIT 1')[0]??null;
        $retry=$row?strtotime($row['created_at'].' UTC')+30*DAY_IN_SECONDS:0;
        return $retry>time() && ($row['reason']??'')==='disconnected'?gmdate('c',$retry):null;
    }

    public static function canReadHistory(int $a,int $b): bool
    {
        if (!Access::member($a) || !Access::member($b) || Messaging::blocked($a,$b)) { return false; }
        if (Store::count('relations',"kind='history_revoked' AND ".self::PAIR,[$a,$b,$b,$a])) { return false; }
        if (Store::count('relations',"kind='connection_history' AND ".self::PAIR,[$a,$b,$b,$a])) { return true; }
        // Previous releases recorded the disconnection in audit but did not retain a relation marker.
        return Store::count('audit',"action='connection_removed' AND ((actor_id=%d AND detail=%s) OR (actor_id=%d AND detail=%s))",[$a,'profile-'.$b,$b,'profile-'.$a])>0;
    }

    public static function disconnected(int $a,int $b): void
    {
        foreach (Store::rows('relations',"kind='history_revoked' AND ".self::PAIR,[$a,$b,$b,$a],'') as $row) { Store::delete('relations',['id'=>(int)$row['id']]); }
        self::cooldown($a,$b,'disconnected');
        self::cooldown($b,$a,'disconnected');
        self::forgetPermissions($a,$b);
        if (!self::canReadHistory($a,$b)) {
            Store::insert('relations',['user_id'=>$a,'target_id'=>$b,'kind'=>'connection_history','created_at'=>current_time('mysql',true)]);
        }
    }

    public static function blocked(int $a,int $b): void
    {
        $rows=Store::rows('relations',"kind IN ('connect','connected','connection_history') AND ".self::PAIR,[$a,$b,$b,$a],'');
        foreach ($rows as $row) { Store::delete('relations',['id'=>(int)$row['id']]); }
        self::forgetPermissions($a,$b);
        if (!Store::count('relations',"kind='history_revoked' AND ".self::PAIR,[$a,$b,$b,$a])) { Store::insert('relations',['user_id'=>$a,'target_id'=>$b,'kind'=>'history_revoked','created_at'=>current_time('mysql',true)]); }
        Notifications::removeProfileNotices($a,['connection'],$b);
        Notifications::removeProfileNotices($b,['connection'],$a);
        Audit::record('member_blocked',$b);
    }

    private static function forgetPermissions(int $a,int $b): void
    {
        $rows=Store::rows('relations',"kind IN ('conversation_request','conversation_allowed') AND ".self::PAIR,[$a,$b,$b,$a],'');
        foreach ($rows as $row) { Store::delete('relations',['id'=>(int)$row['id']]); }
    }
}
