<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\ContentDateRange;

/** Own forum contributions, with current context visibility checked before totals and paging. */
final class CommentHistory
{
    public static function listing(array $filter=[]): array
    {
        Access::require(Access::member());ContentDateRange::query($filter);
        $page=max(1,min(10000,(int)($filter['page']??1)));$size=20;$offset=($page-1)*$size;
        $text=Access::text($filter['q']??'',150);$items=[];$total=0;$cursor=0;
        do {
            global $wpdb;$wpdb->last_error='';
            $rows=get_comments(['user_id'=>get_current_user_id(),'status'=>['approve','hold'],'type'=>'comment',
                'number'=>200,'offset'=>$cursor,'orderby'=>'comment_ID','order'=>'DESC']);
            if ($wpdb->last_error!=='') { throw new \ASCLA\Core\Repositories\RepositoryException('No se pudo consultar el historial.'); }
            foreach ($rows as $comment) {
                $post=self::context($comment);
                if (!$post || !self::matches($comment,$post,$filter,$text)) { continue; }
                if ($total>=$offset && count($items)<$size) {
                    $items[]=['id'=>(int)$comment->comment_ID,'post_id'=>(int)$post->ID,'title'=>$post->post_title,
                        'body'=>$comment->comment_content,'date'=>$comment->comment_date_gmt,'status'=>(string)$comment->comment_approved==='1'?'publish':'pending'];
                }
                $total++;
            }
            $cursor+=200;
        } while (count($rows)===200);
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size))];
    }
    private static function context(\WP_Comment $comment): ?\WP_Post
    {
        $post=get_post((int)$comment->comment_post_ID);
        if (!$post || !in_array($post->post_type,['ascla_topic','ascla_forum'],true) || !Content::canRead($post)) { return null; }
        if ($post->post_parent) {
            $forum=get_post((int)$post->post_parent);
            if (!$forum || $forum->post_type!=='ascla_forum' || !Content::canRead($forum)) { return null; }
        }
        return $post;
    }
    private static function matches(\WP_Comment $comment,\WP_Post $post,array $filter,string $text): bool
    {
        $date=substr($comment->comment_date,0,10);
        if ((!empty($filter['after']) && $date<$filter['after']) || (!empty($filter['before']) && $date>$filter['before'])) { return false; }
        return $text==='' || preg_match('/'.preg_quote($text,'/').'/iu',wp_strip_all_tags($comment->comment_content.' '.$post->post_title))===1;
    }
}
