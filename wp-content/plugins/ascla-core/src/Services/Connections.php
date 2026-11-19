<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;

/** Existing relations: connect = pending request; connected = explicitly accepted pair. */
final class Connections
{
    public const REQUIRED='Debes tener una conexión confirmada con este asociado para poder enviarle mensajes.';
    public static function lockPair(int $a,int $b,callable $callback): mixed
    {
        return Store::atomic(self::lockKey($a,$b),$callback);
    }
    public static function lockKey(int $a,int $b): string
    {
        return 'connection:'.min($a,$b).':'.max($a,$b);
    }
    private static function rows(int $me,array $targets=[]): array
    {
        $where="kind IN ('connect','connected','block') AND (user_id=%d OR target_id=%d)"; $args=[$me,$me];
        if ($targets) {
            $in=implode(',',array_fill(0,count($targets),'%d'));
            $where.=" AND (user_id IN ($in) OR target_id IN ($in))"; $args=array_merge($args,$targets,$targets);
        }
        $rows=Store::rows('relations',$where,$args,'ORDER BY id DESC');
        $connectedIds=array_values(array_unique(array_map('intval',array_column(array_filter($rows,static fn($row)=>$row['kind']==='connected'),'id'))));
        if ($connectedIds) {
            $in=implode(',',array_fill(0,count($connectedIds),'%d'));
            $accepted=[];
            foreach (Store::rows('audit',"action='connection_accepted' AND object_id IN ($in)",$connectedIds,'ORDER BY id DESC') as $entry) {
                $id=(int)$entry['object_id'];
                if (!isset($accepted[$id])) { $accepted[$id]=(string)$entry['created_at']; }
            }
            foreach ($rows as &$row) {
                if ($row['kind']==='connected' && isset($accepted[(int)$row['id']])) { $row['connected_at']=$accepted[(int)$row['id']]; }
            }
            unset($row);
        }
        return $rows;
    }
    public static function statesFor(int $me,array $targets,?array $rows=null): array
    {
        $targets=array_values(array_unique(array_map('intval',$targets)));
        if (!$targets) { return []; }
        return ConnectionStates::build($me,$targets,$rows??self::rows($me,$targets));
    }




    public static function between(int $a,int $b): array { return self::statesFor($a,[$b])[$b]; }
    public static function areConnected(int $a,int $b): bool { return self::between($a,$b)['can_read_messages']; }
    public static function requireConnected(int $a,int $b): void { Access::require(self::areConnected($a,$b),self::REQUIRED,403); }
    public static function profile(int $id): array
    {
        $me=get_current_user_id();
        $profile=Profiles::visible($id)+['connection'=>self::between($me,$id),'conversation'=>ConversationRequests::between($me,$id)];
        return self::suggestions([$profile],$me)[0];
    }
    public static function attach(array $profiles): array
    {
        $me=get_current_user_id(); $ids=array_column($profiles,'id');
        $states=self::statesFor($me,$ids); $conversations=ConversationRequests::statesFor($me,$ids);
        return self::suggestions(array_map(static fn($p)=>$p+['connection'=>$states[$p['id']],'conversation'=>$conversations[$p['id']]],$profiles),$me);
    }
    private static function suggestions(array $profiles,int $me): array
    {
        $targets=current_user_can('ascla_write')?array_column(array_filter($profiles,static fn($p)=>!empty($p['connection']['can_message'])),'id'):[];
        $available=IntroSuggestions::availableFor($me,$targets);
        return array_map(static function($profile) use($available) {
            $profile['connection']['can_suggest_intro']=!empty($available[$profile['id']]);return $profile;
        },$profiles);
    }
    public static function listing(): array
    {
        $me=get_current_user_id(); $rows=self::rows($me); $ids=[];
        foreach($rows as $row) { $ids[]=(int)((int)$row['user_id']===$me?$row['target_id']:$row['user_id']); }
        $out=['incoming'=>[],'outgoing'=>[],'connected'=>[],'blocked'=>[]];
        $conversationStates=ConversationRequests::statesFor($me,$ids);
        foreach(self::statesFor($me,$ids,$rows) as $id=>$state) {
            if (!Access::networkingMember($me) || !Access::networkingMember($id)) { continue; }
            if ($state['blocked_by_me'] && Access::member($id)) { $out['blocked'][]=Profiles::card($id)+['connection'=>$state]; }
            if (!Access::networkingMember($me) || !Access::networkingMember($id) || $state['state']==='none' || $state['blocked']) { continue; }
            $key=['incoming_pending'=>'incoming','outgoing_pending'=>'outgoing','connected'=>'connected'][$state['state']];
            $out[$key][]=Profiles::card($id)+['connection'=>$state,'conversation'=>$conversationStates[$id]??ConversationRequests::between($me,$id)];
        }
        return $out;
    }
    private static function writer(): int
    {
        Access::require(Access::networkingMember() && current_user_can('ascla_write')); return get_current_user_id();
    }
    public static function request(int $target,string $message=''): array
    {
        $me=self::writer(); Access::require($target>0 && $target!==$me && Access::networkingMember($target),'Asociado no válido.',400);
        Access::require(mb_strlen($message)<=1000,'El mensaje de presentación admite hasta 1000 caracteres.',400);
        $message=trim(Access::text($message,1000));
        return self::lockPair($me,$target,static function () use($me,$target,$message) {
            $state=self::between($me,$target);
            Access::require($state['state']==='none','Ya existe una solicitud o conexión entre estos asociados.',409);
            Access::require(!$state['blocked'],'No se puede solicitar esta conexión.',403);
            Access::require(ConnectionPolicy::retryAt($me,$target)<=time(),'No es posible enviar la solicitud en este momento.',409);
            Matching::between($me,$target,false);
            $id=Store::insert('relations',['user_id'=>$me,'target_id'=>$target,'kind'=>'connect','detail'=>$message,'created_at'=>current_time('mysql',true)]);
            Notifications::send($target,'connection',Profiles::publicName($me).' quiere conectar contigo.',Catalog::url('perfil',['member'=>$me]),['type'=>'profile','id'=>$me]);
            Audit::record('connection_requested',$id); return self::between($me,$target);
        });
    }
    public static function respond(int $id,string $decision): array
    {
        $me=self::writer();
        Access::require(in_array($decision,['accept','reject'],true),'Decisión no válida.',400);
        $request=Store::one('relations',$id);
        Access::require($request && $request['kind']==='connect' && (int)$request['target_id']===$me,'Solicitud pendiente no encontrada.',404);
        $sender=(int)$request['user_id'];
        return self::lockPair($me,$sender,static fn()=>self::resolveRequest($id,$me,$sender,$decision));
    }

    private static function resolveRequest(int $id,int $me,int $sender,string $decision): array
    {
        $row=Store::one('relations',$id);
        Access::require($row && $row['kind']==='connect' && (int)$row['target_id']===$me,'Esta solicitud ya fue resuelta.',409);
        if ($decision==='accept') {
            Access::require($sender!==$me && Access::networkingMember($sender) && !self::between($me,$sender)['blocked'],'No se puede confirmar esta conexión.',403);
            if (!self::areConnected($me,$sender)) { Store::update('relations',['kind'=>'connected','created_at'=>current_time('mysql',true)],['id'=>$id]); }
        }
        foreach (self::rows($me,[$sender]) as $pending) {
            if ($pending['kind']==='connect') { Store::delete('relations',['id'=>(int)$pending['id']]); }
        }
        if ($decision==='reject') { ConnectionPolicy::cooldown($sender,$me); }
        self::readRequests($me,$sender);
        Audit::record($decision==='accept'?'connection_accepted':'connection_rejected',$id);
        if ($decision==='accept') {
            Notifications::once($sender,'connection-accepted:'.$id,'connection_accepted',Profiles::publicName($me).' aceptó tu solicitud de conexión.',Catalog::url('perfil',['member'=>$me]),['type'=>'profile','id'=>$me]);
        }
        return self::between($me,$sender);
    }
    private static function readRequests(int $me,int $sender): void
    {
        foreach(Store::rows('notifications',"user_id=%d AND kind='connection' AND read_at IS NULL",[$me],'') as $row) {
            $context=json_decode($row['context']??'null',true); parse_str((string)wp_parse_url($row['url'],PHP_URL_QUERY),$query);
            if ((int)($context['id']??$query['member']??0)===$sender) { Notifications::read((int)$row['id']); }
        }
    }
    public static function remove(int $target): array
    {
        $me=self::writer(); Access::require($target>0 && $target!==$me && Access::member($target),'Asociado no válido.',400);
        return self::lockPair($me,$target,static function () use($me,$target) {
            $state=self::between($me,$target);
            Access::require(in_array($state['state'],['outgoing_pending','connected'],true),'No hay una solicitud enviada ni una conexión que puedas eliminar.',409);
            if ($state['state']==='outgoing_pending') {
                $requestId=(int)$state['request_id'];
                if ($requestId) { Store::delete('relations',['id'=>$requestId,'user_id'=>$me,'target_id'=>$target,'kind'=>'connect']); }
                Notifications::removeProfileNotices($target,['connection'],$me);
                Audit::record('connection_cancelled',$requestId,'profile-'.$target);
            } else {
                $connectionId=(int)$state['connection_id'];
                foreach (self::rows($me,[$target]) as $row) {
                    if ($row['kind']==='connected') { Store::delete('relations',['id'=>(int)$row['id']]); }
                }
                ConnectionPolicy::disconnected($me,$target);
                Audit::record('connection_removed',$connectionId,'profile-'.$target);
            }
            return self::between($me,$target);
        });
    }
    public static function cancel(int $target): array { return self::remove($target); }
}
