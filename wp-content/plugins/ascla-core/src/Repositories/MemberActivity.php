<?php
namespace ASCLA\Core\Repositories;

/** Only community records. Private messages, contacts and audit payloads never enter this query. */
final class MemberActivity
{
    public static function batch(int $user,string $from,string $until,int $offset): array
    {
        global $wpdb;
        $relations=Store::table('relations');$registrations=Store::table('registrations');$attendance=Store::table('attendance');
        $queries=[
            "SELECT 'publications' kind,p.ID record_id,p.ID post_id,p.post_date_gmt occurred_at FROM {$wpdb->posts} p WHERE p.post_author=%d",
            "SELECT 'comments',c.comment_ID,c.comment_post_ID,c.comment_date_gmt FROM {$wpdb->comments} c WHERE c.user_id=%d AND c.comment_approved='1'",
            "SELECT 'likes',r.id,r.target_id,r.created_at FROM $relations r WHERE r.user_id=%d AND r.kind='like'",
            "SELECT 'likes',r.id,c.comment_post_ID,r.created_at FROM $relations r JOIN {$wpdb->comments} c ON c.comment_ID=r.target_id AND c.comment_approved='1' WHERE r.user_id=%d AND r.kind='comment_like'",
            "SELECT 'registrations',r.id,r.event_id,r.created_at FROM $registrations r WHERE r.user_id=%d AND r.status='accepted'",
            "SELECT 'attendances',a.id,a.event_id,a.updated_at FROM $attendance a WHERE a.user_id=%d AND a.status='present'",
        ];
        $union=implode(' UNION ALL ',array_map(static fn($query)=>$wpdb->prepare($query,$user),$queries));
        $sql="SELECT a.* FROM ($union) a JOIN {$wpdb->posts} target ON target.ID=a.post_id
            WHERE target.post_status='publish' AND target.post_type IN ('ascla_hub','ascla_forum','ascla_topic','ascla_resource','ascla_gallery','ascla_event','ascla_ally')
            AND a.occurred_at>=%s AND a.occurred_at<=%s
            ORDER BY a.occurred_at DESC,a.kind ASC,a.record_id DESC LIMIT 200 OFFSET %d";
        $rows=$wpdb->get_results($wpdb->prepare($sql,$from,$until,$offset),ARRAY_A);
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo consultar la actividad. Reintente la operación.'); }
        return $rows?:[];
    }

    public static function formInterests(int $user): array
    {
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT term_id FROM '.Store::table('user_interests')." WHERE user_id=%d AND source='forms' ORDER BY term_id",$user));
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudieron consultar los intereses. Reintente la operación.'); }
        return array_map('intval',$ids?:[]);
    }
}
