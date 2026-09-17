<?php
namespace ASCLA\Core\Repositories;

/** Bounded, private cache of successful prose for directed member pairs. */
final class NetworkingCache
{
    private const META='_ascla_network_ai';
    private const LIMIT=20;

    public static function get(int $viewer,int $target,string $task,string $hash,bool $fresh=false): ?array
    {
        if($fresh){wp_cache_delete($viewer,'user_meta');}
        $entries=get_user_meta($viewer,self::META,true);
        $entry=is_array($entries)?($entries[$task.':'.$target]??[]):[];
        return ($entry['hash']??'')===$hash && is_array($entry['result']??null)?$entry['result']:null;
    }

    public static function put(int $viewer,int $target,string $task,string $hash,array $result): void
    {
        // Serialize only the short metadata write, including different pairs for the same viewer.
        Store::lock('network-cache:'.$viewer,static function()use($viewer,$target,$task,$hash,$result){
            wp_cache_delete($viewer,'user_meta');
            $entries=get_user_meta($viewer,self::META,true);
            if(!is_array($entries)){$entries=[];}
            $key=$task.':'.$target;unset($entries[$key]);
            $entries[$key]=['hash'=>$hash,'updated_at'=>time(),'result'=>$result];
            update_user_meta($viewer,self::META,array_slice($entries,-self::LIMIT,null,true));
        });
    }
}
