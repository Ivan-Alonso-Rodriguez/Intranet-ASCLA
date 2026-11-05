<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** Administrative selection and RSVP details; never included in public listings. */
final class MicroInvitations
{
    public static function apply(array &$item,int $id,array $meta): void
    {
        if (empty($meta['micro']) || !current_user_can('ascla_manage')) { return; }
        $ids=array_values(array_filter(array_unique(array_map('absint',(array)($meta['invitees']??[])))));
        $registrations=[];
        foreach (Store::rows('registrations','event_id=%d',[$id],'ORDER BY id ASC') as $row) {
            $registrations[(int)$row['user_id']]=(string)$row['status'];
        }
        $created=!empty($meta['invited']);
        $unsent=$created || !empty($meta['cancelled'])?'not_invited':'selected';
        $items=[];
        foreach ($ids as $uid) {
            $user=get_userdata($uid);
            $items[]=['id'=>$uid,'name'=>$user?(string)$user->display_name:'','available'=>(bool)$user,
                'status'=>$registrations[$uid]??$unsent];
        }
        $item['micro_invitations']=['created'=>$created,'items'=>$items];
    }
}
