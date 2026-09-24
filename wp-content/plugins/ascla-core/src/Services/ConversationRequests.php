<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\Store;

/** Consent flow for direct messaging between members who are not connected. */
final class ConversationRequests
{
    public const REQUIRED='Debes tener una conexión confirmada o una solicitud de conversación aceptada para poder enviar más mensajes.';

    private static function rows(int $me,array $targets=[]): array
    {
        $where="kind IN ('conversation_request','conversation_allowed') AND (user_id=%d OR target_id=%d)";
        $args=[$me,$me];
        if ($targets) {
            $in=implode(',',array_fill(0,count($targets),'%d'));
            $where.=" AND (user_id IN ($in) OR target_id IN ($in))";
            $args=array_merge($args,$targets,$targets);
        }
        return Store::rows('relations',$where,$args,'ORDER BY id DESC');
    }

    public static function statesFor(int $me,array $targets,?array $rows=null): array
    {
        $targets=array_values(array_unique(array_filter(array_map('intval',$targets),static fn($id)=>$id>0)));
        if (!$targets) { return []; }
        $states=[];
        $mine=Access::member($me)?Profiles::raw($me):[];
        foreach ($targets as $id) {
            $states[$id]=[
                'state'=>'none','request_id'=>0,'allowed_id'=>0,'blocked'=>false,'blocked_by_me'=>false,
                'can_request'=>false,'can_message'=>false,
            ];
        }
        foreach ($rows??self::rows($me,$targets) as $row) {
            $outgoing=(int)$row['user_id']===$me;
            $id=(int)($outgoing?$row['target_id']:$row['user_id']);
            if (!isset($states[$id])) { continue; }
            $state=&$states[$id];
            if ($row['kind']==='conversation_allowed') {
                $state['state']='allowed';
                $state['allowed_id']=(int)$row['id'];
                $state['request_id']=0;
            } elseif ($state['state']!=='allowed' && ($state['state']==='none' || !$outgoing)) {
                $state['state']=$outgoing?'outgoing_pending':'incoming_pending';
                $state['request_id']=(int)$row['id'];
            }
            unset($state);
        }
        foreach ($states as $id=>&$state) {
            $valid=$id!==$me && Access::member($me) && Access::member($id);
            if ($valid) {
                $blockRows=Store::rows('relations',"kind='block' AND ((user_id=%d AND target_id=%d) OR (user_id=%d AND target_id=%d))",[$me,$id,$id,$me],'');
                $state['blocked']=(bool)$blockRows;
                foreach ($blockRows as $block) { if ((int)$block['user_id']===$me) { $state['blocked_by_me']=true; } }
            }
            $connected=$valid && Connections::areConnected($me,$id);
            $state['can_message']=$valid && !$state['blocked'] && ($connected || $state['state']==='allowed');
            if ($valid && !$connected && !$state['blocked'] && $state['state']==='none') {
                $other=Profiles::raw($id);
                $state['can_request']=!empty($mine['networking']) && !empty($other['networking']) && !empty($other['directory']);
            }
        }
        unset($state);
        return $states;
    }

    public static function between(int $a,int $b): array
    {
        $state=self::statesFor($a,[$b])[$b];
        if ($state['state']!=='none') { $state+=Messaging::requestSummary($a,$b); }
        return $state;
    }

    public static function isAuthorized(int $a,int $b): bool
    {
        if (Connections::areConnected($a,$b)) { return true; }
        $state=self::between($a,$b);
        return $state['state']==='allowed';
    }

    public static function requireAuthorized(int $a,int $b): void
    {
        Access::require(self::isAuthorized($a,$b),self::REQUIRED,403);
    }

    private static function writer(): int
    {
        Access::require(Access::member() && current_user_can('ascla_write'));
        return get_current_user_id();
    }

    public static function request(int $target,string $body): array
    {
        $me=self::writer();
        $body=trim(Access::text($body,5000));
        Access::require($body!=='','Escribe el mensaje que quieres enviar con la solicitud.',400);
        Access::require($target>0 && $target!==$me && Access::member($target),'Asociado no válido.',400);
        return Connections::lockPair($me,$target,static function () use($me,$target,$body) {
            Access::require(!Connections::areConnected($me,$target),'Ya existe una conexión confirmada; puedes iniciar la conversación directamente.',409);
            $state=self::between($me,$target);
            Access::require($state['state']==='none','Ya existe una solicitud o autorización de conversación entre estos asociados.',409);
            Access::require($state['can_request'],'No se puede solicitar esta conversación.',403);
            Profiles::visible($target);
            $created=current_time('mysql',true);
            $id=Store::insert('relations',[
                'user_id'=>$me,'target_id'=>$target,'kind'=>'conversation_request','created_at'=>$created,
            ]);
            try {
                $message=Messaging::createRequestMessage($me,$target,$body);
            } catch (\Throwable $error) {
                Store::delete('relations',['id'=>$id]);
                throw $error;
            }
            $conversationId=(int)$message['conversation_id'];
            Notifications::send(
                $target,
                'conversation_request',
                Profiles::publicName($me).' te envió una solicitud de conversación: “'.Access::excerpt($body,90).'”',
                Catalog::url('mensajeria',['conversation'=>$conversationId]),
                ['type'=>'conversation','id'=>$conversationId,'actor'=>$me]
            );
            Audit::record('conversation_requested',$id,'conversation:'.$conversationId);
            return self::between($me,$target)+Messaging::requestSummary($me,$target);
        });
    }

    public static function respond(int $id,string $decision): array
    {
        $me=self::writer();
        Access::require(in_array($decision,['accept','reject'],true),'Decisión no válida.',400);
        $request=Store::one('relations',$id);
        Access::require($request && $request['kind']==='conversation_request' && (int)$request['target_id']===$me,'Solicitud de conversación pendiente no encontrada.',404);
        $sender=(int)$request['user_id'];
        return Connections::lockPair($me,$sender,static function () use($id,$me,$sender,$decision) {
            $row=Store::one('relations',$id);
            Access::require($row && $row['kind']==='conversation_request' && (int)$row['target_id']===$me,'Esta solicitud ya fue resuelta.',409);
            if ($decision==='accept') {
                Access::require($sender!==$me && Access::member($sender) && !Messaging::blocked($me,$sender),'No se puede autorizar esta conversación.',403);
                Store::update('relations',['kind'=>'conversation_allowed'],['id'=>$id]);
            } else {
                Notifications::removeConversationRequestNotices($me,$sender);
                Store::delete('relations',['id'=>$id]);
                Messaging::discardPendingRequest($sender,$me,(string)$row['created_at']);
            }
            if ($decision==='accept') { self::readRequests($me,$sender); }
            Audit::record($decision==='accept'?'conversation_request_accepted':'conversation_request_rejected',$id);
            if ($decision==='accept') {
                $summary=Messaging::requestSummary($me,$sender);
                Notifications::once(
                    $sender,
                    'conversation-accepted:'.$id,
                    'conversation_accepted',
                    Profiles::publicName($me).' aceptó tu solicitud de conversación.',
                    Catalog::url('mensajeria',['conversation'=>(int)$summary['conversation_id']]),
                    ['type'=>'conversation','id'=>(int)$summary['conversation_id'],'actor'=>$me]
                );
            }
            return self::between($me,$sender)+Messaging::requestSummary($me,$sender);
        });
    }

    public static function cancel(int $target): array
    {
        $me=self::writer();
        Access::require($target>0 && $target!==$me && Access::member($target),'Asociado no válido.',400);
        return Connections::lockPair($me,$target,static function () use($me,$target) {
            $state=self::between($me,$target);
            Access::require($state['state']==='outgoing_pending','No hay una solicitud de conversación enviada que puedas cancelar.',409);
            $id=(int)$state['request_id'];
            $row=Store::one('relations',$id);
            Store::delete('relations',['id'=>$id,'user_id'=>$me,'target_id'=>$target,'kind'=>'conversation_request']);
            if ($row) { Messaging::discardPendingRequest($me,$target,(string)$row['created_at']); }
            Notifications::removeProfileNotices($target,['conversation_request'],$me);
            Notifications::removeConversationRequestNotices($target,$me);
            Audit::record('conversation_request_cancelled',$id,'profile-'.$target);
            return self::between($me,$target)+Messaging::requestSummary($me,$target);
        });
    }

    public static function listing(): array
    {
        $me=get_current_user_id();
        $rows=self::rows($me); $ids=[];
        foreach ($rows as $row) { $ids[]=(int)((int)$row['user_id']===$me?$row['target_id']:$row['user_id']); }
        $out=['incoming'=>[],'outgoing'=>[],'allowed'=>[]];
        $states=self::statesFor($me,$ids,$rows);
        $connections=Connections::statesFor($me,$ids);
        foreach ($states as $id=>$state) {
            if (!Access::member($id) || $state['state']==='none') { continue; }
            $key=['incoming_pending'=>'incoming','outgoing_pending'=>'outgoing','allowed'=>'allowed'][$state['state']]??null;
            if (!$key) { continue; }
            $state+=Messaging::requestSummary($me,$id);
            $out[$key][]=Profiles::card($id)+['connection'=>$connections[$id]??Connections::between($me,$id),'conversation'=>$state];
        }
        return $out;
    }

    private static function readRequests(int $me,int $sender): void
    {
        foreach (Store::rows('notifications',"user_id=%d AND kind='conversation_request' AND read_at IS NULL",[$me],'') as $row) {
            $context=json_decode($row['context']??'null',true);
            if (is_array($context) && ($context['type']??'')==='conversation') {
                $summary=Messaging::requestSummary($me,$sender);
                if ((int)($context['id']??0)===(int)$summary['conversation_id']) { Notifications::read((int)$row['id']); }
                continue;
            }
            parse_str((string)wp_parse_url($row['url']??'',PHP_URL_QUERY),$query);
            if ((int)($context['id']??$query['member']??0)===$sender) { Notifications::read((int)$row['id']); }
        }
    }
}
