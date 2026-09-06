<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;
final class Messaging
{
    public static function blocked(int $a,int $b): bool
    {
        return Store::count('relations',"kind='block' AND ((user_id=%d AND target_id=%d) OR (user_id=%d AND target_id=%d))",[$a,$b,$b,$a])>0;
    }
    public static function start(int $target): array
    {
        $me=get_current_user_id();
        Access::require($target!==$me && Access::member($target) && !self::blocked($me,$target),'No se puede iniciar esta conversación.',400);
        Profiles::visible($target);
        $ids=[$me,$target]; sort($ids); $pair=implode(':',$ids);
        return Store::lock('conversation:'.$pair,static function () use($ids,$pair) {
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
        Access::require((bool)$rows,'Conversación no encontrada.',404); return $rows[0];
    }
    public static function conversations(string $query=''): array
    {
        global $wpdb; $p=Store::table('participants'); $c=Store::table('conversations');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT c.*, p.last_read FROM $c c INNER JOIN $p p ON p.conversation_id=c.id WHERE p.user_id=%d ORDER BY c.updated_at DESC LIMIT 100",get_current_user_id()),ARRAY_A);
        $out=[];
        foreach ($rows as $row) {
            $other=Store::rows('participants','conversation_id=%d AND user_id<>%d',[$row['id'],get_current_user_id()],'LIMIT 1')[0]??null;
            if (!$other) { continue; }
            $user=get_userdata($other['user_id']); $row['other']=['id'=>(int)$other['user_id'],'name'=>$user?$user->display_name:'Miembro no disponible'];
            $row['unread']=Store::count('messages','conversation_id=%d AND id>%d AND sender_id<>%d',[$row['id'],$row['last_read'],get_current_user_id()]);
            $last=Store::rows('messages','conversation_id=%d',[$row['id']],'ORDER BY id DESC LIMIT 1')[0]??null; $row['preview']=$last?mb_substr($last['body'],0,100):'Conversación nueva';
            $row['blocked']=self::blocked(get_current_user_id(),(int)$other['user_id']);
            if (!$query || mb_stripos($row['other']['name'].' '.$row['preview'],$query)!==false) { $out[]=$row; }
        }
        return $out;
    }
    public static function messages(int $id,int $before=0): array
    {
        self::participant($id);
        $args=[$id]; $where='conversation_id=%d';
        if ($before>0) { $where.=' AND id<%d'; $args[]=$before; }
        $rows=Store::rows('messages',$where,$args,'ORDER BY id DESC LIMIT 60');
        if (!$before && $rows) { Store::update('participants',['last_read'=>(int)$rows[0]['id']],['conversation_id'=>$id,'user_id'=>get_current_user_id()]); }
        return ['items'=>array_reverse($rows),'before'=>$rows?(int)end($rows)['id']:0];
    }
    public static function send(int $id,string $body): array
    {
        Access::limit('message',20); self::participant($id);
        $body=trim(Access::text($body,5000)); Access::require($body!=='','Escriba un mensaje.',400);
        $other=Store::rows('participants','conversation_id=%d AND user_id<>%d',[$id,get_current_user_id()],'LIMIT 1')[0]??null;
        Access::require($other && Access::member((int)$other['user_id'])&&!self::blocked(get_current_user_id(),(int)$other['user_id']),'No puede enviar mensajes a este miembro.',403);
        $mid=Store::insert('messages',['conversation_id'=>$id,'sender_id'=>get_current_user_id(),'body'=>$body,'created_at'=>current_time('mysql',true)]);
        Store::update('conversations',['updated_at'=>current_time('mysql',true)],['id'=>$id]);
        Notifications::send((int)$other['user_id'],'message','Tienes un nuevo mensaje.',Catalog::url('mensajeria',['conversation'=>$id]));
        return Store::one('messages',$mid);
    }
    public static function relation(int $target,string $kind,bool $active): array
    {
        Access::require(in_array($kind,['block','connect'],true)&&$target!==get_current_user_id()&&Access::member($target),'Acción no válida.',400);
        $where=['user_id'=>get_current_user_id(),'target_id'=>$target,'kind'=>$kind];
        if ($active) {
            if ($kind==='connect') { Matching::between(get_current_user_id(),$target); }
            Store::lock('relation:'.implode(':',$where),static function () use($where,$kind,$target) {
                if (!Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where))) {
                    Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]);
                    if ($kind==='connect') { Notifications::send($target,'connection',wp_get_current_user()->display_name.' quiere conectar contigo.',Catalog::url('perfil',['member'=>get_current_user_id()])); }
                }
            });
        } else { Store::delete('relations',$where); }
        return ['active'=>$active];
    }
}
