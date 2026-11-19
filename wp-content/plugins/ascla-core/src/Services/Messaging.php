<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Repositories\Transaction;
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Frontend\Language;

final class Messaging
{
    private const PAIR_FILTER='pair_key=%s';
    private const SINGLE_ROW='LIMIT 1';
    private const CANNOT_MESSAGE='No puede enviar mensajes a este miembro.';
    public const CONVERSATION_FILTER='conversation_id=%d';
    public const GROUP_LIMIT=50;
    private const LATEST_ROW='ORDER BY id DESC LIMIT 1';
    private const NOT_FOUND='Conversación no encontrada.';

    public static function blocked(int $a,int $b): bool
    {
        $rows=\ASCLA\Core\Repositories\RelationReads::pair($a,$b);
        if ($rows!==null) { return (bool)array_filter($rows,static fn($row)=>$row['kind']==='block'); }
        return Store::count('relations',"kind='block' AND ((user_id=%d AND target_id=%d) OR (user_id=%d AND target_id=%d))",[$a,$b,$b,$a])>0;
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
            $conversation=DirectConversations::ensureDirect($me,$target,$me);
            ConversationVisibility::show((int)$conversation['id']);
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
        $conversation=DirectConversations::ensureDirect($requester,$target,$requester);
        $id=(int)$conversation['id'];
        $mid=Store::insert('messages',['conversation_id'=>$id,'sender_id'=>$requester,'body'=>$body,'created_at'=>current_time('mysql',true)]);
        Store::update('conversations',['updated_at'=>current_time('mysql',true)],['id'=>$id]);
        return ['conversation_id'=>$id,'message'=>Store::one('messages',$mid)];
    }

    public static function requestSummary(int $a,int $b): array
    {
        $pair=DirectConversations::pairKey($a,$b);
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
        Access::require(Access::hasText($title),'Escriba un nombre para el grupo.',400);
        $ids=array_values(array_filter(FormRules::ids($users),static fn($id)=>$id!==$me));
        Access::require(count($ids)>=2,'Selecciona al menos dos asociados para crear un grupo.',400);
        Access::require(count($ids)<self::GROUP_LIMIT,'Un grupo puede tener como máximo 50 participantes, incluido su creador.',400);
        if ($photoId>0) { Media::requireOwned($photoId,$me,true); }
        GroupMembership::requireGroupConnections($me,$ids);
        $key='group:'.wp_generate_uuid4();
        return Store::atomic('group-create:'.$me.':'.hash('sha256',$key),static function () use($me,$ids,$title,$description,$key,$photoId) {
            $id=Store::insert('conversations',[
                'pair_key'=>$key,'kind'=>'group','title'=>$title,'description'=>$description,'created_by'=>$me,'photo_id'=>$photoId,'updated_at'=>current_time('mysql',true),
            ]);
            foreach (array_merge([$me],$ids) as $uid) { Store::insert('participants',['conversation_id'=>$id,'user_id'=>$uid,'last_read'=>0]); }
            if ($photoId>0) { Media::commit($photoId,$me); }
            GroupMembership::notifyNewGroupMembers($id,$me,$title,$ids);
            Audit::record('group_conversation_created',$id,'members:'.(count($ids)+1));
            return self::conversation($id);
        });
    }



    public static function addGroupMembers(int $id,array $users): array
    {
        $creator=get_current_user_id();
        Access::require(Access::member($creator) && current_user_can('ascla_write'));
        GroupMembership::requireGroupCreator($id,$creator);
        Access::limit('group_chat',10,300);
        $ids=array_values(array_diff(FormRules::ids($users),[$creator]));
        Access::require($ids && count($ids)<self::GROUP_LIMIT,'Selecciona entre 1 y 49 conexiones.',400);
        $locks=array_map(static fn($user)=>Connections::lockKey($creator,$user),$ids);
        $locks[]='conversation:'.$id;
        return Store::locks($locks,static fn()=>Transaction::run(static fn()=>GroupMembership::addGroupMembersLocked($id,$creator,$ids)));
    }



    public static function removeGroupMember(int $id,int $user): array
    {
        $creator=get_current_user_id();
        Access::require(Access::member($creator) && current_user_can('ascla_write'));
        return Store::atomic('conversation:'.$id,static function () use($id,$user,$creator) {
            $group=GroupMembership::requireGroupCreator($id,$creator);
            Access::require($user!==$creator,'No puedes expulsarte de tu propio grupo.',400);
            $members=array_map('intval',array_column($group['members'],'user_id'));
            if (in_array($user,$members,true)) {
                Store::delete('participants',['conversation_id'=>$id,'user_id'=>$user]);
                GroupMembership::recordGroupActivity($id,'member_removed',$user);
                Audit::record('group_conversation_updated',$id,'member_removed_'.$user);
            }
            return self::conversation($id);
        });
    }

    public static function leaveGroup(int $id): array
    {
        $member=get_current_user_id();
        Access::require(Access::member($member));
        return Store::atomic('conversation:'.$id,static function () use($id,$member) {
            $group=ConversationRead::participant($id);
            Access::require($group['kind']==='group',self::NOT_FOUND,404);
            if ((int)$group['conversation']['created_by']===$member) {
                GroupMembership::transferGroup($id,$group['members'],$member);
            }
            Store::delete('participants',['conversation_id'=>$id,'user_id'=>$member]);
            ConversationVisibility::show($id);
            GroupMembership::recordGroupActivity($id,'member_left',$member);
            Audit::record('group_conversation_updated',$id,'member_left_'.$member);
            return ['id'=>$id,'left'=>true];
        });
    }



    public static function messageText(array $message): string
    {
        return match ($message['kind']) {
            'member_left'=>sprintf(Language::text('%s salió del grupo.','%s left the group.'),Profiles::publicName((int)$message['sender_id'])),
            'member_removed'=>sprintf(Language::text('Se expulsó a %s del grupo.','%s was removed from the group.'),Profiles::publicName((int)$message['sender_id'])),
            default=>(string)$message['body'],
        };
    }

    public static function groupSuccessors(array $participants,int $creator): array
    {
        return array_values(array_filter(
            array_map('intval',array_column($participants,'user_id')),
            static fn(int $user): bool=>$user!==$creator && Access::member($user) && user_can($user,'ascla_write')
        ));
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
            if (ConversationVisibility::hidden((int)$row['id'],get_current_user_id())) { continue; }
            $decorated=MessagingDecorator::decorate($row);
            if (!$decorated) { continue; }
            $haystack=$decorated['kind']==='group'
                ? $decorated['title'].' '.($decorated['description']??'').' '.implode(' ',array_column($decorated['members'],'name')).' '.$decorated['preview']
                : ($decorated['other']['name']??'').' '.$decorated['preview'];
            if (!$query || mb_stripos($haystack,$query)!==false) { $out[]=$decorated; }
        }
        return $out;
    }


    public static function conversation(int $id): array
    {
        $participant=ConversationRead::participant($id);
        $row=$participant['conversation'];
        $result=MessagingDecorator::decorate($row+['last_read'=>$participant['last_read']]);
        Access::require((bool)$result,self::NOT_FOUND,404);
        return $result;
    }



    public static function messages(int $id,int $before=0,?int $after=null): array
    {
        $participant=ConversationRead::participant($id);
        Access::require($before>=0 && ($after===null || $after>=0) && !($before && $after!==null),'Cursor no válido.',400);
        $boundary=(int)($participant['joined_after']??0);
        $args=[$id,$boundary]; $where=self::CONVERSATION_FILTER.' AND id>%d';
        if ($before>0) { $where.=' AND id<%d'; $args[]=$before; }
        if ($after!==null) { $where.=' AND id>%d'; $args[]=$after; }
        $rows=Store::rows('messages',$where,$args,$after===null?'ORDER BY id DESC LIMIT 61':'ORDER BY id ASC LIMIT 61');
        $more=count($rows)>60; $rows=array_slice($rows,0,60);
        if ($after===null) { $rows=array_reverse($rows); }
        $last=$rows?(int)end($rows)['id']:($after??0);
        $me=get_current_user_id();
        $readState=ConversationRead::readState($id,$me);
        $rows=ConversationRead::decorateMessages($rows,$readState,$me);
        return ['items'=>$rows,'before'=>$rows?(int)$rows[0]['id']:0,'after'=>max($last,$boundary),'has_more'=>$more,'read_state'=>$readState];
    }

    public static function send(int $id,string $body): array
    {
        Access::limit('message',20);
        $participant=ConversationRead::participant($id); $me=get_current_user_id();
        $body=trim(Access::text($body,5000)); Access::require(Access::hasText($body),'Escriba un mensaje.',400);
        if ($participant['kind']==='group') {
            return Store::atomic('conversation:'.$id,static function () use($id,$body,$me) {
                $participant=ConversationRead::participant($id);
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

    public static function requireVisibleMessage(int $conversationId,int $messageId): void
    {
        $participant=ConversationRead::participant($conversationId);
        $message=Store::one('messages',$messageId);
        $boundary=(int)($participant['joined_after']??0);
        Access::require($message && $messageId>$boundary && (int)$message['conversation_id']===$conversationId,'Mensaje no encontrado.',404);
    }

    public static function removeMessage(int $conversationId,int $messageId): array
    {
        self::requireVisibleMessage($conversationId,$messageId);
        throw new \ASCLA\Core\Rest\ApiException('Los mensajes enviados no se pueden editar ni eliminar.',403);
    }

    public static function updateGroup(int $conversationId,string $title,string $description='',int $photoId=0): array
    {
        $me=get_current_user_id();
        Access::require(Access::member($me) && current_user_can('ascla_write'));
        $title=trim(Access::text($title,120));
        $description=trim(Access::text($description,240));
        Access::require(Access::hasText($title),'Escriba un nombre para el grupo.',400);
        return Store::atomic('conversation:'.$conversationId,static function () use($conversationId,$me,$title,$description,$photoId) {
            $group=GroupMembership::requireGroupCreator($conversationId,$me);
            $newPhoto=$photoId>0 && $photoId!==(int)$group['conversation']['photo_id'];
            if ($newPhoto) { Media::requireOwned($photoId,$me,true); }
            Store::update('conversations',[
                'title'=>$title,
                'description'=>$description,
                'photo_id'=>$photoId,
                'updated_at'=>current_time('mysql',true),
            ],['id'=>$conversationId]);
            if ($newPhoto) { Media::commit($photoId,$me); }
            Audit::record('group_conversation_updated',$conversationId,'creator:'.$me);
            return self::conversation($conversationId);
        });
    }

    public static function removeGroup(int $conversationId): array
    {
        $me=get_current_user_id();
        Access::require(Access::member($me) && current_user_can('ascla_write'));
        return Store::atomic('conversation:'.$conversationId,static function () use($conversationId,$me) {
            GroupMembership::requireGroupCreator($conversationId,$me);
            Notifications::removeConversationNotices($conversationId);
            Store::delete('messages',['conversation_id'=>$conversationId]);
            Store::delete('participants',['conversation_id'=>$conversationId]);
            Store::delete('conversations',['id'=>$conversationId]);
            ConversationVisibility::forget($conversationId);
            Audit::record('group_conversation_deleted',$conversationId,'');
            return ['id'=>$conversationId,'deleted'=>true];
        });
    }

    public static function relation(int $target,string $kind,bool $active,string $message=''): array
    {
        Access::require(in_array($kind,['block','connect'],true)&&$target>0&&$target!==get_current_user_id()&&Access::member($target),'Acción no válida.',400);
        if ($kind==='connect') { return $active?Connections::request($target,$message):Connections::remove($target); }
        $me=get_current_user_id();
        return Connections::lockPair($me,$target,static function () use($me,$target,$active) {
            $where=['user_id'=>$me,'target_id'=>$target,'kind'=>'block'];
            if ($active) {
                ConnectionPolicy::blocked($me,$target);
                if (!Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',array_values($where))) { Store::insert('relations',$where+['created_at'=>current_time('mysql',true)]); }
            } else { Store::delete('relations',$where); }
            return ['active'=>$active];
        });
    }
}

final class MessagingDecorator
{
    public static function decorate(array $row): ?array
    {
        $me=get_current_user_id();
        $row['kind']=(string)($row['kind']??'direct');$row['title']=(string)($row['title']??'');$row['description']=(string)($row['description']??'');
        $row['created_by']=(int)($row['created_by']??0);$row['photo_id']=(int)($row['photo_id']??0);
        $participants=Store::rows('participants',Messaging::CONVERSATION_FILTER,[(int)$row['id']],'ORDER BY id ASC');
        $viewer=array_values(array_filter($participants,static fn($participant)=>(int)$participant['user_id']===$me))[0]??null;
        if (!$viewer) { return null; }
        $boundary=(int)($viewer['joined_after']??0);
        $last=Store::rows('messages',Messaging::CONVERSATION_FILTER.' AND id>%d',[(int)$row['id'],$boundary],'ORDER BY id DESC LIMIT 1')[0]??null;
        $row['preview']=$last?mb_substr(Messaging::messageText($last),0,100):'Conversación nueva';
        $row['unread']=Store::count('messages','conversation_id=%d AND id>%d AND sender_id<>%d',[(int)$row['id'],max($boundary,(int)($row['last_read']??0)),$me]);
        return $row['kind']==='group'?self::group($row,$participants,$me):self::direct($row,$participants,$me);
    }

    private static function group(array $value,array $members,int $current): array
    {
        $value['members']=array_values(array_map(static fn($participant)=>Profiles::card((int)$participant['user_id']),$members));
        $value['member_count']=count($value['members']);$value['title']=$value['title']!==''?$value['title']:'Grupo ASCLA';
        $media=$value['photo_id']?Store::one('media',$value['photo_id']):null;
        $value['photo_url']=$media && str_starts_with((string)$media['mime'],'image/')?Media::url($value['photo_id']):'';
        $ownsGroup=$value['created_by']===$current;
        $canManageMembers=$ownsGroup && current_user_can('ascla_write');
        $value['can_edit_group']=$canManageMembers;
        $value['can_add_group_members']=$canManageMembers && $value['member_count']<Messaging::GROUP_LIMIT;
        $value['can_remove_group_members']=$canManageMembers && $value['member_count']>1;
        $value['member_limit']=Messaging::GROUP_LIMIT;
        $value['can_leave_group']=!$ownsGroup || (bool)Messaging::groupSuccessors($members,$current);
        $value['can_delete_group']=$canManageMembers;$value['blocked']=false;$value['blocked_by_me']=false;
        $value['can_message']=true;$value['connection']=null;$value['conversation_request']=null;
        return $value;
    }

    private static function direct(array $value,array $members,int $current): ?array
    {
        $others=array_values(array_filter($members,static fn($participant)=>(int)$participant['user_id']!==$current));
        $other=count($others)===1?(int)$others[0]['user_id']:0;
        if (!$other || !Access::member($other)) { return null; }
        $connection=Connections::between($current,$other);$permission=ConversationRequests::between($current,$other);
        if ($connection['blocked'] || ($connection['state']!=='connected' && !ConnectionPolicy::canReadHistory($current,$other) && !in_array($permission['state'],['allowed','incoming_pending','outgoing_pending'],true))) { return null; }
        $value['other']=Profiles::card($other);$value['connection']=$connection;$value['conversation_request']=$permission;
        $value['blocked']=$connection['blocked'];$value['blocked_by_me']=$connection['blocked_by_me'];$value['can_message']=$permission['can_message'];
        return $value;
    }
}

