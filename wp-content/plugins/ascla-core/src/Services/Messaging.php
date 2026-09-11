<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;
final class Messaging
{
    private const NOT_FOUND='Conversación no encontrada.';
    public static function blocked(int $a,int $b): bool
    {
        return Store::count('relations',"kind='block' AND ((user_id=%d AND target_id=%d) OR (user_id=%d AND target_id=%d))",[$a,$b,$b,$a])>0;
    }
    public static function start(int $target): array
    {
        $me=get_current_user_id();
        Access::require($target>0 && $target!==$me && Access::member($target) && !self::blocked($me,$target),'No se puede iniciar esta conversación.',400);
        Connections::requireConnected($me,$target);
        Profiles::visible($target);
        $ids=[$me,$target]; sort($ids); $pair=implode(':',$ids);
        return Connections::lockPair($me,$target,static function () use($ids,$pair,$me,$target) {
            Connections::requireConnected($me,$target);
            Access::require(!self::blocked($me,$target),'No puede enviar mensajes a este miembro.',403);
            $existing=Store::rows('conversations','pair_key=%s',[$pair],'LIMIT 1');
            if ($existing) { return $existing[0]; }
            $id=Store::insert('conversations',['pair_key'=>$pair,'updated_at'=>current_time('mysql',true)]);
            foreach ($ids as $uid) { Store::insert('participants',['conversation_id'=>$id,'user_id'=>$uid,'last_read'=>0]); }
            return Store::one('conversations',$id);
        });
    }
    private static function participant(int $id): array
    {
        $rows=Store::rows('participants','conversation_id=%d AND user_id=%d',[$id,get_current_user_id()],'LIMIT 1');
        Access::require((bool)$rows,self::NOT_FOUND,404);
        $others=Store::rows('participants','conversation_id=%d AND user_id<>%d',[$id,get_current_user_id()],'LIMIT 2');
        Access::require(count($others)===1,self::NOT_FOUND,404);
        Connections::requireConnected(get_current_user_id(),(int)$others[0]['user_id']);
        return $rows[0]+['other_id'=>(int)$others[0]['user_id']];
    }
    public static function conversations(string $query=''): array
    {
        global $wpdb; $p=Store::table('participants'); $c=Store::table('conversations');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT c.*, p.last_read, peer.user_id AS other_id FROM $c c INNER JOIN $p p ON p.conversation_id=c.id INNER JOIN $p peer ON peer.conversation_id=c.id AND peer.user_id<>p.user_id WHERE p.user_id=%d ORDER BY c.updated_at DESC LIMIT 100",get_current_user_id()),ARRAY_A);
        $out=[]; $states=Connections::statesFor(get_current_user_id(),array_column($rows,'other_id'));
        foreach ($rows as $row) {
            $state=$states[(int)$row['other_id']]; if (!$state['can_read_messages']) continue;
            $row=self::decorate($row,$state);
            if (!$row) { continue; }
            if (!$query || mb_stripos($row['other']['name'].' '.$row['preview'],$query)!==false) { $out[]=$row; }
        }
        return $out;
    }
    private static function decorate(array $row,?array $state=null): ?array
    {
        $other=isset($row['other_id'])?['user_id'=>(int)$row['other_id']]:Store::rows('participants','conversation_id=%d AND user_id<>%d',[$row['id'],get_current_user_id()],'LIMIT 1')[0]??null;
        if (!$other) { return null; }
        $state=$state??Connections::between(get_current_user_id(),(int)$other['user_id']);
        if (!$state['can_read_messages']) return null;
        $row['other']=Profiles::card((int)$other['user_id']); unset($row['other_id']);
        $row['unread']=Store::count('messages','conversation_id=%d AND id>%d AND sender_id<>%d',[$row['id'],$row['last_read'],get_current_user_id()]);
        $last=Store::rows('messages','conversation_id=%d',[$row['id']],'ORDER BY id DESC LIMIT 1')[0]??null; $row['preview']=$last?mb_substr($last['body'],0,100):'Conversación nueva';
        $row['blocked']=$state['blocked']; $row['blocked_by_me']=$state['blocked_by_me']; $row['connection']=$state;
        return $row;
    }
    public static function conversation(int $id): array
    {
        $participant=self::participant($id); $row=Store::one('conversations',$id);
        Access::require((bool)$row,self::NOT_FOUND,404);
        $result=self::decorate($row+['last_read'=>$participant['last_read']]);
        Access::require((bool)$result,self::NOT_FOUND,404); return $result;
    }
    public static function messages(int $id,int $before=0,?int $after=null): array
    {
        self::participant($id);
        Access::require($before>=0 && ($after===null || $after>=0) && !($before && $after!==null),'Cursor no válido.',400);
        $args=[$id]; $where='conversation_id=%d';
        if ($before>0) { $where.=' AND id<%d'; $args[]=$before; }
        if ($after!==null) { $where.=' AND id>%d'; $args[]=$after; }
        $rows=Store::rows('messages',$where,$args,$after===null?'ORDER BY id DESC LIMIT 61':'ORDER BY id ASC LIMIT 61');
        $more=count($rows)>60; $rows=array_slice($rows,0,60);
        if ($after===null) { $rows=array_reverse($rows); }
        $last=$rows?(int)end($rows)['id']:($after??0);
        if (!$before && $rows) {
            // Overlapping reads must never move the read cursor backwards.
            global $wpdb; $table=Store::table('participants');
            $wpdb->query($wpdb->prepare("UPDATE $table SET last_read=GREATEST(last_read,%d) WHERE conversation_id=%d AND user_id=%d",$last,$id,get_current_user_id()));
        }
        return ['items'=>$rows,'before'=>$rows?(int)$rows[0]['id']:0,'after'=>$last,'has_more'=>$more];
    }
    public static function send(int $id,string $body): array
    {
        Access::limit('message',20); $participant=self::participant($id); $me=get_current_user_id();
        $body=trim(Access::text($body,5000)); Access::require($body!=='','Escriba un mensaje.',400);
        return Connections::lockPair($me,$participant['other_id'],static function () use($id,$body,$me) {
            $participant=self::participant($id); $other=$participant['other_id'];
            Access::require(!self::blocked($me,$other),'No puede enviar mensajes a este miembro.',403);
            $mid=Store::insert('messages',['conversation_id'=>$id,'sender_id'=>$me,'body'=>$body,'created_at'=>current_time('mysql',true)]);
            Store::update('conversations',['updated_at'=>current_time('mysql',true)],['id'=>$id]);
            Notifications::send($other,'message','Tienes un nuevo mensaje.',Catalog::url('mensajeria',['conversation'=>$id]),['type'=>'conversation','id'=>$id,'actor'=>$me]);
            return Store::one('messages',$mid);
        });
    }
    public static function relation(int $target,string $kind,bool $active): array
    {
        Access::require(in_array($kind,['block','connect'],true)&&$target>0&&$target!==get_current_user_id()&&Access::member($target),'Acción no válida.',400);
        if ($kind==='connect') return $active?Connections::request($target):Connections::cancel($target);
        $me=get_current_user_id();
        return Connections::lockPair($me,$target,static function () use($me,$target,$active) {
            $where=['user_id'=>$me,'target_id'=>$target,'kind'=>'block'];
            if ($active) {
                if (!Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where))) Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]);
            } else Store::delete('relations',$where);
            return ['active'=>$active];
        });
    }
}
