<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;

final class Messaging
{
    private const PAIR_FILTER='pair_key=%s';
    private const SINGLE_ROW='LIMIT 1';
    private const PARTICIPANT_FILTER='conversation_id=%d AND user_id=%d';
    private const CANNOT_MESSAGE='No puede enviar mensajes a este miembro.';
    private const CONVERSATION_FILTER='conversation_id=%d';
    private const LATEST_ROW='ORDER BY id DESC LIMIT 1';
    private const ORDER_BY_ID_ASC='ORDER BY id ASC';
    private const NOT_FOUND='Conversación no encontrada.';

    public static function blocked(int $a,int $b): bool
    {
        return Store::count('relations',"kind='block' AND ((user_id=%d AND target_id=%d) OR (user_id=%d AND target_id=%d))",[$a,$b,$b,$a])>0;
    }

    private static function pairKey(int $a,int $b): string
    {
        $ids=[$a,$b]; sort($ids); return implode(':',$ids);
    }

    private static function ensureDirect(int $a,int $b,int $creator): array
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

    public static function start(int $target): array
    {
        $me=get_current_user_id();
        Access::require($target>0 && $target!==$me && Access::member($target),'No se puede iniciar esta conversación.',400);
        ConversationRequests::requireAuthorized($me,$target);
        Access::require(!self::blocked($me,$target),self::CANNOT_MESSAGE,403);
        return Connections::lockPair($me,$target,static function () use($me,$target) {
            ConversationRequests::requireAuthorized($me,$target);
            Access::require(!self::blocked($me,$target),self::CANNOT_MESSAGE,403);
            $conversation=self::ensureDirect($me,$target,$me);
            Audit::record('conversation_started',(int)$conversation['id'],'direct');
            return $conversation;
        });
    }

    /** Creates the first message that travels together with a conversation request. */
    public static function createRequestMessage(int $requester,int $target,string $body): array
    {
        Access::require(get_current_user_id()===$requester && $requester>0 && $target>0 && $requester!==$target,'Solicitud no válida.',400);
        $body=trim(Access::text($body,5000));
        Access::require($body!=='','Escribe el mensaje que quieres enviar con la solicitud.',400);
        $conversation=self::ensureDirect($requester,$target,$requester);
        $id=(int)$conversation['id'];
        $mid=Store::insert('messages',['conversation_id'=>$id,'sender_id'=>$requester,'body'=>$body,'created_at'=>current_time('mysql',true)]);
        Store::update('conversations',['updated_at'=>current_time('mysql',true)],['id'=>$id]);
        return ['conversation_id'=>$id,'message'=>Store::one('messages',$mid)];
    }

    /** Removes only the message(s) created for a still-pending request, preserving older history. */
    public static function discardPendingRequest(int $requester,int $target,string $createdAt): void
    {
        $pair=self::pairKey($requester,$target);
        $rows=Store::rows('conversations',self::PAIR_FILTER,[$pair],self::SINGLE_ROW);
        if (!$rows || ($rows[0]['kind']??'direct')!=='direct') { return; }
        $conversationId=(int)$rows[0]['id'];
        global $wpdb;
        $messages=Store::table('messages');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $messages WHERE conversation_id=%d AND sender_id=%d AND created_at>=%s",
            $conversationId,$requester,$createdAt
        ));
        $last=Store::rows('messages',self::CONVERSATION_FILTER,[$conversationId],self::LATEST_ROW)[0]??null;
        if ($last) {
            Store::update('conversations',['updated_at'=>(string)$last['created_at']],['id'=>$conversationId]);
        } else {
            Store::delete('participants',['conversation_id'=>$conversationId]);
            Store::delete('conversations',['id'=>$conversationId]);
        }
    }

    public static function requestSummary(int $a,int $b): array
    {
        $pair=self::pairKey($a,$b);
        $rows=Store::rows('conversations',self::PAIR_FILTER,[$pair],self::SINGLE_ROW);
        if (!$rows || ($rows[0]['kind']??'direct')!=='direct') { return ['conversation_id'=>0,'initial_message'=>'','message_created_at'=>'']; }
        $conversationId=(int)$rows[0]['id'];
        $last=Store::rows('messages',self::CONVERSATION_FILTER,[$conversationId],self::LATEST_ROW)[0]??null;
        return [
            'conversation_id'=>$conversationId,
            'initial_message'=>$last?mb_substr((string)$last['body'],0,500):'',
            'message_created_at'=>$last?(string)$last['created_at']:'',
        ];
    }

    public static function createGroup(string $title,array $users,int $photoId=0,string $description=''): array
    {
        $me=get_current_user_id();
        Access::require(Access::member($me) && current_user_can('ascla_write'));
        Access::limit('group_chat',10,300);
        $title=trim(Access::text($title,120));
        $description=trim(Access::text($description,240));
        Access::require($title!=='','Escriba un nombre para el grupo.',400);
        $ids=array_values(array_unique(array_filter(array_map('absint',$users),static fn($id)=>$id>0 && $id!==$me)));
        Access::require(count($ids)>=2,'Selecciona al menos dos asociados para crear un grupo.',400);
        Access::require(count($ids)<=49,'Un grupo puede tener como máximo 50 participantes, incluido su creador.',400);
        if ($photoId>0) { Media::requireOwned($photoId,$me,true); }
        foreach ($ids as $id) {
            Access::require(Access::member($id),'Uno de los participantes ya no está disponible.',400);
            Access::require(Connections::areConnected($me,$id),'Solo puedes añadir al grupo conexiones confirmadas.',403);
            Access::require(!self::blocked($me,$id),'No puedes añadir al grupo a un asociado bloqueado.',403);
        }
        $key='group:'.wp_generate_uuid4();
        return Store::lock('group-create:'.$me.':'.hash('sha256',$key),static function () use($me,$ids,$title,$description,$key,$photoId) {
            $id=Store::insert('conversations',[
                'pair_key'=>$key,'kind'=>'group','title'=>$title,'description'=>$description,'created_by'=>$me,'photo_id'=>$photoId,'updated_at'=>current_time('mysql',true),
            ]);
            foreach (array_merge([$me],$ids) as $uid) { Store::insert('participants',['conversation_id'=>$id,'user_id'=>$uid,'last_read'=>0]); }
            if ($photoId>0) { Media::commit($photoId,$me); }
            foreach ($ids as $uid) {
                Notifications::send(
                    $uid,
                    'conversation_group',
                    Profiles::publicName($me).' te añadió al grupo '.$title.'.',
                    Catalog::url('mensajeria',['conversation'=>$id]),
                    ['type'=>'conversation','id'=>$id,'actor'=>$me]
                );
            }
            Audit::record('group_conversation_created',$id,'members:'.(count($ids)+1));
            return self::conversation($id);
        });
    }

    private static function participant(int $id): array
    {
        $conversation=Store::one('conversations',$id);
        Access::require((bool)$conversation,self::NOT_FOUND,404);
        $me=get_current_user_id();
        $rows=Store::rows('participants',self::PARTICIPANT_FILTER,[$id,$me],self::SINGLE_ROW);
        Access::require((bool)$rows,self::NOT_FOUND,404);
        $members=Store::rows('participants',self::CONVERSATION_FILTER,[$id],self::ORDER_BY_ID_ASC);
        $kind=(string)($conversation['kind']??'direct');
        if ($kind==='group') {
            Access::require(count($members)>=3,self::NOT_FOUND,404);
            return $rows[0]+['conversation'=>$conversation,'members'=>$members,'kind'=>'group'];
        }
        $others=array_values(array_filter($members,static fn($member)=>(int)$member['user_id']!==$me));
        Access::require(count($others)===1,self::NOT_FOUND,404);
        $other=(int)$others[0]['user_id'];
        $permission=ConversationRequests::between($me,$other);
        $connected=Connections::areConnected($me,$other);
        Access::require($connected || in_array($permission['state'],['allowed','incoming_pending','outgoing_pending'],true),self::NOT_FOUND,404);
        return $rows[0]+['conversation'=>$conversation,'members'=>$members,'kind'=>'direct','other_id'=>$other,'permission'=>$permission];
    }

    public static function conversations(string $query=''): array
    {
        global $wpdb; $p=Store::table('participants'); $c=Store::table('conversations');
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.last_read FROM $c c INNER JOIN $p p ON p.conversation_id=c.id WHERE p.user_id=%d ORDER BY c.updated_at DESC LIMIT 100",
            get_current_user_id()
        ),ARRAY_A) ?: [];
        $out=[];
        foreach ($rows as $row) {
            $decorated=self::decorate($row);
            if (!$decorated) { continue; }
            $haystack=$decorated['kind']==='group'
                ? $decorated['title'].' '.($decorated['description']??'').' '.implode(' ',array_column($decorated['members'],'name')).' '.$decorated['preview']
                : ($decorated['other']['name']??'').' '.$decorated['preview'];
            if (!$query || mb_stripos($haystack,$query)!==false) { $out[]=$decorated; }
        }
        return $out;
    }

    private static function decorate(array $row): ?array
    {
        $me=get_current_user_id();
        $row['kind']=(string)($row['kind']??'direct');
        $row['title']=(string)($row['title']??'');
        $row['description']=(string)($row['description']??'');
        $row['created_by']=(int)($row['created_by']??0);
        $row['photo_id']=(int)($row['photo_id']??0);
        $participants=Store::rows('participants',self::CONVERSATION_FILTER,[(int)$row['id']],self::ORDER_BY_ID_ASC);
        if (!array_filter($participants,static fn($p)=>(int)$p['user_id']===$me)) { return null; }
        $last=Store::rows('messages',self::CONVERSATION_FILTER,[(int)$row['id']],self::LATEST_ROW)[0]??null;
        $row['preview']=$last?mb_substr((string)$last['body'],0,100):'Conversación nueva';
        $row['unread']=Store::count('messages','conversation_id=%d AND id>%d AND sender_id<>%d',[(int)$row['id'],(int)($row['last_read']??0),$me]);
        if ($row['kind']==='group') {
            $row['members']=array_values(array_map(static fn($p)=>Profiles::card((int)$p['user_id']),$participants));
            $row['member_count']=count($row['members']);
            $row['title']=$row['title']!==''?$row['title']:'Grupo ASCLA';
            $media=$row['photo_id']?Store::one('media',$row['photo_id']):null;
            $row['photo_url']=$media && str_starts_with((string)$media['mime'],'image/')?Media::url($row['photo_id']):'';
            $row['can_delete_group']=$row['created_by']===$me;
            $row['blocked']=false; $row['blocked_by_me']=false; $row['can_message']=true;
            $row['connection']=null; $row['conversation_request']=null;
            return $row;
        }
        $others=array_values(array_filter($participants,static fn($p)=>(int)$p['user_id']!==$me));
        if (count($others)!==1) { return null; }
        $other=(int)$others[0]['user_id'];
        if (!Access::member($other)) { return null; }
        $connection=Connections::between($me,$other);
        $permission=ConversationRequests::between($me,$other);
        if ($connection['state']!=='connected' && !in_array($permission['state'],['allowed','incoming_pending','outgoing_pending'],true)) { return null; }
        $row['other']=Profiles::card($other);
        $row['connection']=$connection;
        $row['conversation_request']=$permission;
        $row['blocked']=$connection['blocked'];
        $row['blocked_by_me']=$connection['blocked_by_me'];
        $row['can_message']=$permission['can_message'];
        return $row;
    }

    public static function conversation(int $id): array
    {
        $participant=self::participant($id);
        $row=$participant['conversation'];
        $result=self::decorate($row+['last_read'=>$participant['last_read']]);
        Access::require((bool)$result,self::NOT_FOUND,404);
        return $result;
    }

    private static function readState(int $id,int $me): array
    {
        $rows=Store::rows('participants','conversation_id=%d AND user_id<>%d',[$id,$me],self::ORDER_BY_ID_ASC);
        $out=[];
        foreach ($rows as $row) {
            $uid=(int)$row['user_id'];
            $card=Profiles::card($uid);
            $out[]=['user_id'=>$uid,'name'=>$card['name'],'last_read'=>(int)$row['last_read']];
        }
        return $out;
    }

    private static function decorateMessages(array $rows,array $readState,int $me): array
    {
        foreach ($rows as &$message) {
            $message['sender_id']=(int)$message['sender_id'];
            $message['can_delete']=$message['sender_id']===$me;
            $message['sender']=Profiles::card($message['sender_id']);
            if ($message['sender_id']===$me) {
                $read=0;
                foreach ($readState as $state) { if ((int)$state['last_read']>=(int)$message['id']) { $read++; } }
                $message['read_count']=$read;
                $message['read_total']=count($readState);
            }
        }
        unset($message);
        return $rows;
    }

    public static function messages(int $id,int $before=0,?int $after=null): array
    {
        self::participant($id);
        Access::require($before>=0 && ($after===null || $after>=0) && !($before && $after!==null),'Cursor no válido.',400);
        $args=[$id]; $where=self::CONVERSATION_FILTER;
        if ($before>0) { $where.=' AND id<%d'; $args[]=$before; }
        if ($after!==null) { $where.=' AND id>%d'; $args[]=$after; }
        $rows=Store::rows('messages',$where,$args,$after===null?'ORDER BY id DESC LIMIT 61':'ORDER BY id ASC LIMIT 61');
        $more=count($rows)>60; $rows=array_slice($rows,0,60);
        if ($after===null) { $rows=array_reverse($rows); }
        $last=$rows?(int)end($rows)['id']:($after??0);
        if (!$before && $rows) {
            global $wpdb; $table=Store::table('participants');
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET last_read=GREATEST(last_read,%d) WHERE conversation_id=%d AND user_id=%d",
                $last,$id,get_current_user_id()
            ));
        }
        $me=get_current_user_id();
        $readState=self::readState($id,$me);
        $rows=self::decorateMessages($rows,$readState,$me);
        return ['items'=>$rows,'before'=>$rows?(int)$rows[0]['id']:0,'after'=>$last,'has_more'=>$more,'read_state'=>$readState];
    }

    public static function send(int $id,string $body): array
    {
        Access::limit('message',20);
        $participant=self::participant($id); $me=get_current_user_id();
        $body=trim(Access::text($body,5000)); Access::require($body!=='','Escriba un mensaje.',400);
        if ($participant['kind']==='group') {
            return Store::lock('conversation:'.$id,static function () use($id,$body,$me) {
                $participant=self::participant($id);
                Access::require($participant['kind']==='group',self::NOT_FOUND,404);
                $mid=Store::insert('messages',['conversation_id'=>$id,'sender_id'=>$me,'body'=>$body,'created_at'=>current_time('mysql',true)]);
                Store::update('conversations',['updated_at'=>current_time('mysql',true)],['id'=>$id]);
                $title=(string)($participant['conversation']['title']??'Grupo ASCLA');
                foreach ($participant['members'] as $member) {
                    $uid=(int)$member['user_id']; if ($uid===$me) { continue; }
                    Notifications::send($uid,'message',Profiles::publicName($me).' escribió en '.$title.'.',Catalog::url('mensajeria',['conversation'=>$id]),['type'=>'conversation','id'=>$id,'actor'=>$me]);
                }
                return Store::one('messages',$mid);
            });
        }
        $other=(int)$participant['other_id'];
        return Connections::lockPair($me,$other,static function () use($id,$body,$me,$other) {
            ConversationRequests::requireAuthorized($me,$other);
            Access::require(!self::blocked($me,$other),self::CANNOT_MESSAGE,403);
            $mid=Store::insert('messages',['conversation_id'=>$id,'sender_id'=>$me,'body'=>$body,'created_at'=>current_time('mysql',true)]);
            Store::update('conversations',['updated_at'=>current_time('mysql',true)],['id'=>$id]);
            Notifications::send($other,'message','Tienes un nuevo mensaje.',Catalog::url('mensajeria',['conversation'=>$id]),['type'=>'conversation','id'=>$id,'actor'=>$me]);
            return Store::one('messages',$mid);
        });
    }

    public static function removeMessage(int $conversationId,int $messageId): array
    {
        self::participant($conversationId);
        $message=Store::one('messages',$messageId);
        Access::require($message && (int)$message['conversation_id']===$conversationId,'Mensaje no encontrado.',404);
        Access::require((int)$message['sender_id']===get_current_user_id(),'Solo puedes eliminar tus propios mensajes.',403);
        Store::delete('messages',['id'=>$messageId,'conversation_id'=>$conversationId,'sender_id'=>get_current_user_id()]);
        $last=Store::rows('messages',self::CONVERSATION_FILTER,[$conversationId],self::LATEST_ROW)[0]??null;
        Store::update('conversations',['updated_at'=>$last?(string)$last['created_at']:current_time('mysql',true)],['id'=>$conversationId]);
        Audit::record('message_deleted',$messageId,'conversation:'.$conversationId);
        return ['id'=>$messageId,'deleted'=>true];
    }

    public static function updateGroup(int $conversationId,string $title,string $description='',int $photoId=0): array
    {
        $me=get_current_user_id();
        Access::require(Access::member($me) && current_user_can('ascla_write'));
        $title=trim(Access::text($title,120));
        $description=trim(Access::text($description,240));
        Access::require($title!=='','Escriba un nombre para el grupo.',400);
        if ($photoId>0) { Media::requireOwned($photoId,$me,true); }
        return Store::lock('conversation:'.$conversationId,static function () use($conversationId,$me,$title,$description,$photoId) {
            $conversation=Store::one('conversations',$conversationId);
            Access::require($conversation && ($conversation['kind']??'direct')==='group',self::NOT_FOUND,404);
            Access::require((int)$conversation['created_by']===$me,'Solo quien creó el grupo puede editarlo.',403);
            Access::require(Store::count('participants',self::PARTICIPANT_FILTER,[$conversationId,$me])>0,self::NOT_FOUND,404);
            Store::update('conversations',[
                'title'=>$title,
                'description'=>$description,
                'photo_id'=>$photoId,
                'updated_at'=>current_time('mysql',true),
            ],['id'=>$conversationId]);
            if ($photoId>0) { Media::commit($photoId,$me); }
            Audit::record('group_conversation_updated',$conversationId,'creator:'.$me);
            return self::conversation($conversationId);
        });
    }

    public static function removeGroup(int $conversationId): array
    {
        $me=get_current_user_id();
        Access::require(Access::member($me) && current_user_can('ascla_write'));
        return Store::lock('conversation:'.$conversationId,static function () use($conversationId,$me) {
            $conversation=Store::one('conversations',$conversationId);
            Access::require($conversation && ($conversation['kind']??'direct')==='group',self::NOT_FOUND,404);
            Access::require((int)$conversation['created_by']===$me,'Solo quien creó el grupo puede eliminarlo.',403);
            Access::require(Store::count('participants',self::PARTICIPANT_FILTER,[$conversationId,$me])>0,self::NOT_FOUND,404);
            Notifications::removeConversationNotices($conversationId);
            Store::delete('messages',['conversation_id'=>$conversationId]);
            Store::delete('participants',['conversation_id'=>$conversationId]);
            Store::delete('conversations',['id'=>$conversationId]);
            Audit::record('group_conversation_deleted',$conversationId,'creator:'.$me);
            return ['id'=>$conversationId,'deleted'=>true];
        });
    }

    public static function relation(int $target,string $kind,bool $active): array
    {
        Access::require(in_array($kind,['block','connect'],true)&&$target>0&&$target!==get_current_user_id()&&Access::member($target),'Acción no válida.',400);
        if ($kind==='connect') { return $active?Connections::request($target):Connections::remove($target); }
        $me=get_current_user_id();
        return Connections::lockPair($me,$target,static function () use($me,$target,$active) {
            $where=['user_id'=>$me,'target_id'=>$target,'kind'=>'block'];
            if ($active) {
                if (!Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where))) { Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]); }
            } else { Store::delete('relations',$where); }
            return ['active'=>$active];
        });
    }
}
