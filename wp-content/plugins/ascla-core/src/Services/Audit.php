<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Audit
{
    public static function record(string $action,int $id=0,string $detail=''): void
    {
        // Callers pass only bounded classification codes, never request bodies or exceptions.
        Store::insert('audit',['actor_id'=>get_current_user_id(),'action'=>sanitize_key($action),'object_id'=>$id,'detail'=>substr(sanitize_key($detail),0,255),'created_at'=>current_time('mysql',true)]);
    }
    /** Administrative diff only; credentials and private profile text are excluded. */
    public static function changes(string $action,int $id,array $before,array $after): void
    {
        $diff=[];
        foreach (['roles','capabilities','membership_status','membership_until'] as $field) {
            if (($before[$field]??null)!==($after[$field]??null)) { $diff[$field]=['before'=>$before[$field]??null,'after'=>$after[$field]??null]; }
        }
        if (!$diff) { return; }
        Store::insert('audit',['actor_id'=>get_current_user_id(),'action'=>sanitize_key($action),'object_id'=>$id,
            'detail'=>wp_json_encode($diff,JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql',true)]);
    }
}
