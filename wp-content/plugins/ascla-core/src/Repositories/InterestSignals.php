<?php
namespace ASCLA\Core\Repositories;

/** Current, positive community actions; never message bodies or free-text searches. */
final class InterestSignals
{
    private const SQL_DATE_FORMAT='Y-m-d H:i:s';
    public static function recent(int $user,array $baseline=[]): array
    {
        global $wpdb;
        $r=Store::table('relations');$g=Store::table('registrations');$a=Store::table('attendance');
        $relations=(int)($baseline['relations']??0);$comments=(int)($baseline['comments']??0);
        $registrations=(int)($baseline['registrations']??0);$attendance=(int)($baseline['attendance']??0);
        $parts=[
            "SELECT r.target_id post_id,r.created_at occurred_at,CASE r.kind WHEN 'like' THEN 'like' ELSE 'read' END kind FROM $r r WHERE r.user_id=%d AND r.id>$relations AND r.kind IN ('like','interest_read')",
            "SELECT c.comment_post_ID,r.created_at,'like' FROM $r r JOIN {$wpdb->comments} c ON c.comment_ID=r.target_id AND c.comment_approved='1' WHERE r.user_id=%d AND r.id>$relations AND r.kind='comment_like'",
            "SELECT c.comment_post_ID,c.comment_date_gmt,'comment' FROM {$wpdb->comments} c WHERE c.user_id=%d AND c.comment_ID>$comments AND c.comment_approved='1'",
            "SELECT event_id,created_at,'registration' FROM $g WHERE user_id=%d AND id>$registrations AND status='accepted'",
            "SELECT event_id,updated_at,'attendance' FROM $a WHERE user_id=%d AND id>$attendance AND status='present'",
        ];
        $union=implode(' UNION ALL ',array_map(static fn($sql)=>$wpdb->prepare($sql,$user),$parts));
        // Group repeated comments/reads before the bound; one source cannot amplify itself by repetition.
        $sql="SELECT post_id,kind,MAX(occurred_at) occurred_at FROM ($union) signals WHERE occurred_at>=%s AND occurred_at<=%s GROUP BY post_id,kind ORDER BY occurred_at DESC,post_id DESC,kind ASC LIMIT 400";
        $rows=$wpdb->get_results($wpdb->prepare($sql,gmdate(self::SQL_DATE_FORMAT,time()-90*DAY_IN_SECONDS),gmdate(self::SQL_DATE_FORMAT)),ARRAY_A);
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudieron consultar los intereses. Reintente la operación.'); }
        return $rows?:[];
    }

    public static function pruneReads(int $user): void
    {
        foreach (Store::rows('relations',"user_id=%d AND kind='interest_read'",[$user],'ORDER BY created_at DESC,id DESC LIMIT 10000 OFFSET 250') as $row) {
            Store::delete('relations',['id'=>(int)$row['id']]);
        }
        foreach (Store::rows('relations',"user_id=%d AND kind='interest_read' AND created_at<%s",[$user,gmdate(self::SQL_DATE_FORMAT,time()-90*DAY_IN_SECONDS)],'') as $row) {
            Store::delete('relations',['id'=>(int)$row['id']]);
        }
    }
}
