<?php
namespace ASCLA\Core\Services;

final class ConnectionStates
{
    public static function build(int $me,array $targets,array $rows): array
    {
        $states=self::emptyStates($targets);
        self::applyRelationRows($states,$rows,$me);
        self::applyStatePermissions($states,$me);
        return $states;
    }
    private static function emptyStates(array $targets): array
    {
        $states=[];
        foreach ($targets as $id) {
            $states[$id]=['state'=>'none','request_id'=>0,'connection_id'=>0,'connected_at'=>'','blocked'=>false,'blocked_by_me'=>false,'can_message'=>false,'can_read_messages'=>false,'can_request'=>false,'networking_available'=>false,'initial_message'=>''];
        }
        return $states;
    }
    private static function applyRelationRow(array &$states,array $row,int $me): void
    {
        $outgoing=(int)$row['user_id']===$me;
        $id=(int)($outgoing?$row['target_id']:$row['user_id']);
        if (!isset($states[$id])) { return; }
        $state=&$states[$id];
        if ($row['kind']==='block') {
            $state['blocked']=true;
            if ($outgoing) { $state['blocked_by_me']=true; }
        } elseif ($row['kind']==='connected') {
            $state['state']='connected';$state['connection_id']=(int)$row['id'];$state['request_id']=0;$state['connected_at']=(string)($row['connected_at']??$row['created_at']??'');
        } elseif ($state['state']!=='connected' && ($state['state']==='none' || !$outgoing)) {
            $state['state']=$outgoing?'outgoing_pending':'incoming_pending';$state['request_id']=(int)$row['id'];$state['initial_message']=(string)($row['detail']??'');
        }
        unset($state);
    }
    private static function applyRelationRows(array &$states,array $rows,int $me): void
    {
        foreach ($rows as $row) { self::applyRelationRow($states,$row,$me); }
    }
    private static function applyStatePermissions(array &$states,int $me): void
    {
        $mine=Access::member($me)?Profiles::raw($me):[];
        foreach ($states as $id=>&$state) {
            $valid=$id>0 && $id!==$me && Access::networkingMember($me) && Access::networkingMember($id);
            $state['can_read_messages']=$valid && !$state['blocked'] && $state['state']==='connected';
            $retry=ConnectionPolicy::retryAt($me,$id);
            $state['retry_at']=ConnectionPolicy::visibleRetryAt($me,$id);
            $state['can_message']=$state['can_read_messages'] && !$state['blocked'];
            if ($valid && !$state['blocked'] && $state['state']==='none') {
                $other=Profiles::raw($id);
                $state['networking_available']=!empty($mine['networking']) && !empty($other['networking']) && !empty($other['directory']);
                $state['can_request']=$state['networking_available'] && $retry<=time();
            }
        }
        unset($state);
    }
}
