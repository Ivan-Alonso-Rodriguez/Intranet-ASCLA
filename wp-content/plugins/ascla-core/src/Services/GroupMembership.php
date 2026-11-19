<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;

final class GroupMembership
{
    private const CONVERSATION_FILTER='conversation_id=%d';
    private const GROUP_LIMIT=50;
    private const LATEST_ROW='ORDER BY id DESC LIMIT 1';
    private const NOT_FOUND='Conversación no encontrada.';
    public static function requireGroupConnections(int $creator,array $ids): void
    {
        $states=Connections::statesFor($creator,$ids);
        foreach ($ids as $id) {
            Access::require(Access::member($id),'Uno de los participantes ya no está disponible.',400);
            Access::require(!$states[$id]['blocked'],'No puedes añadir al grupo a un asociado bloqueado.',403);
            Access::require($states[$id]['can_read_messages'],'Solo puedes añadir al grupo conexiones confirmadas.',403);
        }
    }
    public static function notifyNewGroupMembers(int $conversation,int $creator,string $title,array $ids): void
    {
        $label=Profiles::publicName($creator).' te añadió al grupo '.$title.'.';
        $url=Catalog::url('mensajeria',['conversation'=>$conversation]);
        $context=['type'=>'conversation','id'=>$conversation,'actor'=>$creator];
        foreach ($ids as $id) { Notifications::send($id,'conversation_group',$label,$url,$context); }
    }
    public static function requireGroupCreator(int $id,int $creator): array
    {
        $participant=ConversationRead::participant($id);
        Access::require($participant['kind']==='group',self::NOT_FOUND,404);
        Access::require((int)$participant['conversation']['created_by']===$creator,'Solo el responsable del grupo puede gestionarlo.',403);
        return $participant;
    }
    public static function addGroupMembersLocked(int $id,int $creator,array $ids): array
    {
        $group=self::requireGroupCreator($id,$creator);
        $members=array_map('intval',array_column($group['members'],'user_id'));
        $new=array_values(array_diff($ids,$members));
        if (!$new) { return Messaging::conversation($id); }
        Access::require(count($members)+count($new)<=self::GROUP_LIMIT,'Un grupo puede tener como máximo 50 participantes, incluido su creador.',409);
        self::requireGroupConnections($creator,$new);
        $last=Store::rows('messages',self::CONVERSATION_FILTER,[$id],self::LATEST_ROW)[0]??null;
        $boundary=(int)($last['id']??0);
        foreach ($new as $user) {
            Store::insert('participants',['conversation_id'=>$id,'user_id'=>$user,'last_read'=>$boundary,'joined_after'=>$boundary]);
        }
        Store::update('conversations',['updated_at'=>current_time('mysql',true)],['id'=>$id]);
        self::notifyNewGroupMembers($id,$creator,(string)$group['conversation']['title'],$new);
        Audit::record('group_conversation_updated',$id,'members_added:'.count($new));
        return Messaging::conversation($id);
    }
    public static function transferGroup(int $id,array $participants,int $creator): void
    {
        $candidates=Messaging::groupSuccessors($participants,$creator);
        Access::require((bool)$candidates,'Se necesita otro integrante activo que pueda gestionar el grupo para que puedas salir.',409);
        $successor=$candidates[random_int(0,count($candidates)-1)];
        Store::update('conversations',['created_by'=>$successor],['id'=>$id]);
        Notifications::send($successor,'conversation_group_owner','Ahora eres responsable de un grupo de Mensajería.',
            Catalog::url('mensajeria',['conversation'=>$id]),['type'=>'conversation','id'=>$id,'actor'=>$creator]);
        Audit::record('group_conversation_updated',$id,'owner_transferred_'.$successor);
    }
    public static function recordGroupActivity(int $id,string $kind,int $member): void
    {
        $time=current_time('mysql',true);
        Store::insert('messages',[
            'conversation_id'=>$id,'sender_id'=>$member,'kind'=>$kind,'body'=>'','created_at'=>$time,
        ]);
        Store::update('conversations',['updated_at'=>$time],['id'=>$id]);
    }
}
