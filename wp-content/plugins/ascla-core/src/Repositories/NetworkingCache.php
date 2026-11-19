<?php
namespace ASCLA\Core\Repositories;
use ASCLA\Core\Frontend\Language;

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
        if (($entry['locale']??'')===Language::current() && ($entry['hash']??'')===$hash && is_array($entry['result']??null)) { return $entry['result']; }
        $localized=$entry['locales'][Language::current()]??[];
        return ($localized['locale']??'')===Language::current() && ($localized['hash']??'')===$hash && is_array($localized['result']??null)?$localized['result']:null;
    }

    public static function put(int $viewer,int $target,string $task,string $hash,array $result): void
    {
        // Serialize only the short metadata write, including different pairs for the same viewer.
        Store::lock('network-cache:'.$viewer,static function()use($viewer,$target,$task,$hash,$result){
            wp_cache_delete($viewer,'user_meta');
            $entries=get_user_meta($viewer,self::META,true);
            if(!is_array($entries)){$entries=[];}
            $key=$task.':'.$target;$previous=$entries[$key]??[];unset($entries[$key]);
            $locales=array_intersect_key((array)($previous['locales']??[]),Language::SUPPORTED);
            $value=['hash'=>$hash,'locale'=>Language::current(),'updated_at'=>time(),'result'=>$result];
            $locales[Language::current()]=$value;
            $entries[$key]=$value+['locales'=>$locales];
            update_user_meta($viewer,self::META,array_slice($entries,-self::LIMIT,null,true));
        });
    }
}
