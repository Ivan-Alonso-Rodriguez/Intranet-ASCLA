<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\{Store,WordPressWrites};

/** Institutional catalogue lifecycle is independent from social publications. */
final class Allies
{
    private const STATUSES=['publish','ascla_hidden','draft','pending','ascla_rejected','private'];
    public static function publicationStatus(string $requested): string
    {
        return match($requested) {'publish'=>'publish','inactive'=>'ascla_hidden','draft'=>'draft',default=>'pending'};
    }
    public static function state(\WP_Post $post,?array $meta=null): string
    {
        $meta??=(array)get_post_meta($post->ID,'_ascla',true);
        return match(true) {
            $post->post_status==='publish'=>'active',
            !empty($meta['publish_at']) && $post->post_status==='ascla_hidden'=>'scheduled',
            $post->post_status==='ascla_hidden'=>'inactive',
            $post->post_status==='draft'=>'draft',
            default=>'pending',
        };
    }

    public static function listing(array $filter): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        $state=Access::text($filter['state']??'',20);$q=Access::text($filter['q']??'',150);
        Access::require(in_array($state,['','active','inactive','draft','scheduled','pending'],true),'Estado de aliado no válido.',400);
        $statuses=match($state) {'active'=>['publish'],'inactive','scheduled'=>['ascla_hidden'],'draft'=>['draft'],'pending'=>['pending','ascla_rejected','private'],default=>self::STATUSES};
        $page=max(1,min(10000,(int)($filter['page']??1)));
        // This authorized registry deliberately includes drafts belonging to every administrator.
        $args=['post_type'=>'ascla_ally','post_status'=>$statuses,'posts_per_page'=>18,'paged'=>$page,'s'=>$q,'ascla_ally_search'=>true,'orderby'=>['modified'=>'DESC','ID'=>'DESC']];
        if (in_array($state,['inactive','scheduled'],true)) {
            $args['post__in']=self::idsForState($state)?:[0];
        }
        $query=new \WP_Query($args);
        global $wpdb;
        if ($wpdb->last_error!=='') { throw new \ASCLA\Core\Repositories\RepositoryException('No se pudo consultar el registro de aliados.'); }
        return ['items'=>array_map([Content::class,'serialize'],$query->posts),'total'=>(int)$query->found_posts,'page'=>$page,'pages'=>(int)$query->max_num_pages];
    }

    /** Compatibility with legacy scheduled/hidden records without a timestamp index. */
    private static function idsForState(string $state): array
    {
        $ids=get_posts(['post_type'=>'ascla_ally','post_status'=>'ascla_hidden','numberposts'=>-1,'fields'=>'ids']);$result=[];
        foreach(array_chunk($ids,200) as $batch) {
            _prime_post_caches(array_map('intval',$batch),false,true);
            foreach($batch as $id) { $post=get_post($id);if($post && self::state($post)===$state) { $result[]=(int)$id; } }
        }
        return $result;
    }

    public static function metadata(array &$data,array $input): void
    {
        foreach(['contact_name','contact_email','contact_phone','conditions'] as $key) {
            if (array_key_exists($key,$input)) { $data[$key]=trim(Access::text($input[$key],FormRules::TEXT[$key])); }
        }
        Access::require(empty($data['contact_email']) || (bool)is_email($data['contact_email']),'Correo de contacto no válido.',400);
        $data['chatham']=false;
    }

    public static function recordState(int $id,?string $before): void
    {
        $post=get_post($id);
        Audit::changes('ally_state_changed',$id,['state'=>$before],['state'=>self::state($post)],['kind'=>'ally']);
    }

    public static function history(int $id): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        $post=Content::get($id);Access::require($post->post_type==='ascla_ally','Aliado no encontrado.',404);
        $result=[];
        foreach(Store::rows('audit','object_id=%d AND action=%s',[$id,'ally_state_changed'],'ORDER BY id DESC LIMIT 20') as $row) {
            $detail=json_decode($row['detail'],true)?:[];
            $result[]=['from'=>$detail['state']['before']??null,'to'=>$detail['state']['after']??null,'date'=>$row['created_at'],'actor'=>Profiles::publicName((int)$row['actor_id'])];
        }
        return $result;
    }

    public static function changeStatus(int $id,string $state): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        Access::require(in_array($state,['active','inactive'],true),'Estado de aliado no válido.',400);
        return Store::atomic('content:'.$id,static function()use($id,$state){
            clean_post_cache($id);$post=Content::get($id);
            Access::require($post->post_type==='ascla_ally','Aliado no encontrado.',404);
            $before=self::state($post);
            if ($before===$state) { return ['id'=>$id,'state'=>$state,'changed'=>false]; }
            if ($state==='active') { Access::require(Access::hasText($post->post_title) && Access::hasText($post->post_content),'Complete nombre y descripción antes de activar el aliado.',400); }
            $meta=(array)get_post_meta($id,'_ascla',true);
            unset($meta['publish_at'],$meta['publish_actor_id'],$meta['schedule_error']);
            WordPressWrites::meta('post',$id,'_ascla',$meta);
            $status=$state==='active'?'publish':'ascla_hidden';WordPressWrites::post(['ID'=>$id,'post_status'=>$status]);
            Access::require(get_post_status($id)===$status,'No se pudo cambiar el estado del aliado.',500);
            ContentSchedule::sync($id);self::recordState($id,$before);
            return ['id'=>$id,'state'=>$state,'changed'=>true];
        });
    }
}
