<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** Published and retired editorial records retain their IDs, media and audit trail. */
final class EditorialRegistry
{
    public static function listing(string $type,array $filter): array
    {
        self::authorize($type);
        $state=Access::text($filter['status']??'',30);
        Access::require(in_array($state,['','publish','ascla_hidden','draft','pending','ascla_rejected'],true),'Estado editorial no válido.',400);
        return Content::listing($type,[
            'q'=>Access::text($filter['q']??'',150),'status'=>$state,
            'page'=>$filter['page']??1,'mine'=>!current_user_can('ascla_manage'),
        ]);
    }

    private static function authorize(string $type): void
    {
        Access::require(Access::member() && Access::canPublish());
        Access::require(in_array($type,['gallery','resource'],true),'Registro no válido.',400);
    }

    public static function recordState(int $id,?string $before,string $reason=''): void
    {
        $post=get_post($id);
        if (!$post || !in_array($post->post_type,['ascla_gallery','ascla_resource'],true) || $before===$post->post_status) { return; }
        Audit::changes('editorial_state_changed',$id,['state'=>$before],['state'=>$post->post_status,'reason'=>$reason],['kind'=>substr($post->post_type,6)]);
    }

    public static function history(string $type,int $id): array
    {
        self::authorize($type);
        $post=Content::get($id);
        Access::require($post->post_type==='ascla_'.$type && Content::canEdit($post),'Registro no encontrado.',404);
        $rows=Store::rows('audit','object_id=%d AND action=%s',[$id,'editorial_state_changed'],'ORDER BY id DESC LIMIT 50');
        return array_map(static function($row) {
            $detail=json_decode($row['detail'],true)?:[];
            return ['from'=>$detail['state']['before']??null,'to'=>$detail['state']['after']??null,'reason'=>$detail['reason']['after']??'',
                'date'=>$row['created_at'],'actor'=>Profiles::publicName((int)$row['actor_id'])];
        },$rows);
    }
}
