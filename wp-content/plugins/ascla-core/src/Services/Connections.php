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
        $ids=[$a,$b]; sort($ids); return Store::lock('connection:'.implode(':',$ids),$callback);
    }
    private static function rows(int $me,array $targets=[]): array
    {
        $where="kind IN ('connect','connected','block') AND (user_id=%d OR target_id=%d)"; $args=[$me,$me];
        if ($targets) {
            $in=implode(',',array_fill(0,count($targets),'%d'));
            $where.=" AND (user_id IN ($in) OR target_id IN ($in))"; $args=array_merge($args,$targets,$targets);
        }
        return Store::rows('relations',$where,$args,'ORDER BY id DESC');
    }
    public static function statesFor(int $me,array $targets,?array $rows=null): array
    {
        $targets=array_values(array_unique(array_map('intval',$targets))); if (!$targets) return [];
        $states=[]; $mine=Access::member($me)?Profiles::raw($me):[];
        foreach ($targets as $id) $states[$id]=['state'=>'none','request_id'=>0,'connection_id'=>0,'blocked'=>false,'blocked_by_me'=>false,'can_message'=>false,'can_read_messages'=>false,'can_request'=>false];
        foreach ($rows??self::rows($me,$targets) as $row) {
            $outgoing=(int)$row['user_id']===$me; $id=(int)($outgoing?$row['target_id']:$row['user_id']);
            if (!isset($states[$id])) continue;
            $s=&$states[$id];
            if ($row['kind']==='block') { $s['blocked']=true; if ($outgoing) $s['blocked_by_me']=true; }
            elseif ($row['kind']==='connected') { $s['state']='connected'; $s['connection_id']=(int)$row['id']; $s['request_id']=0; }
            elseif ($s['state']!=='connected' && ($s['state']==='none' || !$outgoing)) { $s['state']=$outgoing?'outgoing_pending':'incoming_pending'; $s['request_id']=(int)$row['id']; }
            unset($s);
        }
        foreach ($states as $id=>&$s) {
            $valid=$id>0 && $id!==$me && Access::member($me) && Access::member($id);
            $s['can_read_messages']=$valid && $s['state']==='connected';
            $s['can_message']=$s['can_read_messages'] && !$s['blocked'];
            if ($valid && !$s['blocked'] && $s['state']==='none') {
                $other=Profiles::raw($id); $s['can_request']=!empty($mine['networking']) && !empty($other['networking']) && !empty($other['directory']);
            }
        }
        unset($s); return $states;
    }
    public static function between(int $a,int $b): array { return self::statesFor($a,[$b])[$b]; }
    public static function areConnected(int $a,int $b): bool { return self::between($a,$b)['can_read_messages']; }
    public static function requireConnected(int $a,int $b): void { Access::require(self::areConnected($a,$b),self::REQUIRED,403); }
    public static function profile(int $id): array { return Profiles::visible($id)+['connection'=>self::between(get_current_user_id(),$id)]; }
    public static function attach(array $profiles): array
    {
        $states=self::statesFor(get_current_user_id(),array_column($profiles,'id'));
        return array_map(static fn($p)=>$p+['connection'=>$states[$p['id']]],$profiles);
    }
    public static function listing(): array
    {
        $me=get_current_user_id(); $rows=self::rows($me); $ids=[];
        foreach($rows as $row) $ids[]=(int)((int)$row['user_id']===$me?$row['target_id']:$row['user_id']);
        $out=['incoming'=>[],'outgoing'=>[],'connected'=>[]];
        foreach(self::statesFor($me,$ids,$rows) as $id=>$state) {
            if (!Access::member($id) || $state['state']==='none') continue;
            $key=['incoming_pending'=>'incoming','outgoing_pending'=>'outgoing','connected'=>'connected'][$state['state']];
            $out[$key][]=Profiles::card($id)+['connection'=>$state];
        }
        return $out;
    }
    private static function writer(): int
    {
        Access::require(Access::member() && current_user_can('ascla_write')); return get_current_user_id();
    }
    public static function request(int $target): array
    {
        $me=self::writer(); Access::require($target>0 && $target!==$me && Access::member($target),'Asociado no válido.',400);
        return self::lockPair($me,$target,static function () use($me,$target) {
            $state=self::between($me,$target);
            Access::require($state['state']==='none','Ya existe una solicitud o conexión entre estos asociados.',409);
            Access::require(!$state['blocked'],'No se puede solicitar esta conexión.',403);
            Matching::between($me,$target,false);
            $id=Store::insert('relations',['user_id'=>$me,'target_id'=>$target,'kind'=>'connect','created_at'=>current_time('mysql',true)]);
            Notifications::send($target,'connection',Profiles::publicName($me).' quiere conectar contigo.',Catalog::url('perfil',['member'=>$me]),['type'=>'profile','id'=>$me]);
            Audit::record('connection_requested',$id); return self::between($me,$target);
        });
    }
    public static function respond(int $id,string $decision): array
    {
        $me=self::writer(); Access::require(in_array($decision,['accept','reject'],true),'Decisión no válida.',400);
        $request=Store::one('relations',$id);
        Access::require($request && $request['kind']==='connect' && (int)$request['target_id']===$me,'Solicitud pendiente no encontrada.',404);
        $sender=(int)$request['user_id'];
        return self::lockPair($me,$sender,static function () use($id,$me,$sender,$decision) {
            $row=Store::one('relations',$id);
            Access::require($row && $row['kind']==='connect' && (int)$row['target_id']===$me,'Esta solicitud ya fue resuelta.',409);
            if ($decision==='accept') {
                Access::require($sender!==$me && Access::member($sender) && !self::between($me,$sender)['blocked'],'No se puede confirmar esta conexión.',403);
                if (!self::areConnected($me,$sender)) Store::update('relations',['kind'=>'connected'],['id'=>$id]);
            }
            // Remove reciprocal legacy requests too; neither is implicit consent.
            foreach(self::rows($me,[$sender]) as $pending) if ($pending['kind']==='connect') Store::delete('relations',['id'=>(int)$pending['id']]);
            self::readRequests($me,$sender);
            Audit::record($decision==='accept'?'connection_accepted':'connection_rejected',$id);
            if ($decision==='accept') Notifications::once($sender,'connection-accepted:'.$id,'connection_accepted',Profiles::publicName($me).' aceptó tu solicitud de conexión.',Catalog::url('perfil',['member'=>$me]),['type'=>'profile','id'=>$me]);
            return self::between($me,$sender);
        });
    }
    private static function readRequests(int $me,int $sender): void
    {
        foreach(Store::rows('notifications',"user_id=%d AND kind='connection' AND read_at IS NULL",[$me],'') as $row) {
            $context=json_decode($row['context']??'null',true); parse_str((string)wp_parse_url($row['url'],PHP_URL_QUERY),$query);
            if ((int)($context['id']??$query['member']??0)===$sender) Notifications::read((int)$row['id']);
        }
    }
    public static function cancel(int $target): array
    {
        $me=self::writer(); return self::lockPair($me,$target,static function () use($me,$target) {
            $state=self::between($me,$target); Access::require(in_array($state['state'],['none','outgoing_pending'],true),'Solo puedes cancelar tu propia solicitud pendiente.',409);
            if ($state['request_id']) Store::delete('relations',['id'=>$state['request_id'],'user_id'=>$me,'kind'=>'connect']);
            return self::between($me,$target);
        });
    }
}
