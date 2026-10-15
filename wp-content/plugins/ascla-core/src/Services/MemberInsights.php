<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\MemberActivity;

/** Community evidence and separately labelled topic estimates, never private correspondence. */
final class MemberInsights
{
    private const KINDS=['publications','comments','likes','registrations','attendances'];

    public static function get(int $id,array $filter=[]): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        $user=get_userdata($id);
        Access::require($user instanceof \WP_User,'Usuario no encontrado.',404);
        $days=$filter['days']??'90';$kind=Access::text($filter['activity']??'',24);
        Access::require((is_string($days)||is_int($days)) && in_array((string)$days,['0','30','90'],true),'Período no válido.',400);
        Access::require($kind==='' || in_array($kind,self::KINDS,true),'Actividad no válida.',400);
        $page=$filter['page']??1;
        Access::require((is_string($page)||is_int($page)) && filter_var($page,FILTER_VALIDATE_INT)!==false && (int)$page>0 && (int)$page<=10000,'Página no válida.',400);
        $from=(int)$days?gmdate('Y-m-d H:i:s',time()-(int)$days*DAY_IN_SECONDS):'1970-01-01 00:00:00';$until=gmdate('Y-m-d H:i:s');
        $activity=self::activity($id,$from,$until,$kind,(int)$page);
        $profile=(array)get_user_meta($id,'_ascla_profile',true);$card=Profiles::card($id);
        $formInterests=Profiles::labels(['interests'=>MemberActivity::formInterests($id)])['interests'];
        return ['user'=>['id'=>$id,'name'=>Profiles::publicName($id),'login'=>$user->user_login,'email'=>$user->user_email,'photo_url'=>$card['photo_url'],
                'registered'=>$user->user_registered,'access_enabled'=>user_can($id,'ascla_access'),...Membership::view($id)],
            'interests'=>Profiles::labels($profile),'form_interests'=>$formInterests,'learned_interests'=>LearnedInterests::get($id),
            'filters'=>['days'=>(int)$days,'activity'=>$kind],'from'=>$from,'until'=>$until,...$activity];
    }

    private static function activity(int $id,string $from,string $until,string $kind,int $page): array
    {
        $summary=array_fill_keys(self::KINDS,0);$items=[];$total=0;$topics=[];$titles=[];$last=null;
        foreach(self::records($id,$from,$until) as $row) {
            $postId=(int)$row['post_id'];$summary[$row['kind']]++;
            $last??=$row['occurred_at'];
            if ($row['kind']==='likes') { self::likedTopics($postId,$topics); }
            if ($kind!=='' && $row['kind']!==$kind) { continue; }
            $total++;
            if ($total<=($page-1)*20 || count($items)>=20) { continue; }
            $post=get_post($postId);$titles[$postId]??=\ASCLA\Core\Domain\MicroeventTitle::display($post->post_title,(array)get_post_meta($postId,'_ascla',true));
            $items[]=['kind'=>$row['kind'],'date'=>$row['occurred_at'],'target'=>['id'=>$postId,'title'=>$titles[$postId],
                'type'=>substr($post->post_type,6),'url'=>Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$postId])]];
        }
        $topics=array_values($topics);usort($topics,static fn($a,$b)=>($b['likes']<=>$a['likes'])?:strcmp($a['name'],$b['name']));
        return ['summary'=>$summary,'last_activity'=>$last,'liked_topics'=>array_slice($topics,0,8),'items'=>$items,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/20)];
    }

    /** Scan this member in bounded batches so privacy exclusions happen before totals and pages. */
    private static function records(int $id,string $from,string $until): \Generator
    {
        $eligible=[];
        for($offset=0;;$offset+=200) {
            $batch=MemberActivity::batch($id,$from,$until,$offset);
            if (!$batch) { break; }
            _prime_post_caches(array_values(array_unique(array_map('intval',array_column($batch,'post_id')))),true,true);
            foreach($batch as $row) {
                $postId=(int)$row['post_id'];
                if (!array_key_exists($postId,$eligible)) { $eligible[$postId]=self::attributable($postId); }
                if ($eligible[$postId]) { yield $row; }
            }
            if (count($batch)<200) { break; }
        }
    }

    /** Never reveal an identity-to-content association protected by Chatham House, including derived resources. */
    private static function attributable(int $id,array $visited=[]): bool
    {
        if (isset($visited[$id])) { return false; }
        $visited[$id]=true;$post=get_post($id);
        if (!$post || $post->post_status!=='publish' || !Content::canRead($post)) { return false; }
        $meta=(array)get_post_meta($id,'_ascla',true);
        if (!empty($meta['chatham']) || !empty($meta['micro'])) { return false; }
        return !$post->post_parent || self::attributable((int)$post->post_parent,$visited);
    }

    private static function likedTopics(int $post,array &$topics): void
    {
        $terms=get_the_terms($post,'ascla_interest');
        if (!$terms || is_wp_error($terms)) { return; }
        foreach($terms as $term) {
            $id=(int)$term->term_id;$topics[$id]??=['id'=>$id,'name'=>$term->name,'likes'=>0];$topics[$id]['likes']++;
        }
    }
}
