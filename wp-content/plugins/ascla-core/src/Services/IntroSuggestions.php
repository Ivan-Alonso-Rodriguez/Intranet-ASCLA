<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\{Store,WordPressWrites};

/** A first-contact draft belongs to its requester; direct message history belongs to the pair. */
final class IntroSuggestions
{
    private const USED='intro_used';
    private const LEGACY_IMPORTED='_ascla_intro_history_imported';

    private static function importPreviousUse(int $me): void
    {
        if (get_user_meta($me,self::LEGACY_IMPORTED,true)) { return; }
        Store::atomic('intro-history:'.$me,static function() use($me) {
            wp_cache_delete($me,'user_meta');
            if (get_user_meta($me,self::LEGACY_IMPORTED,true)) { return; }
            $entries=get_user_meta($me,'_ascla_network_ai',true);
            foreach (is_array($entries)?$entries:[] as $key=>$entry) {
                if (!preg_match('/^intro:([1-9][0-9]*)$/',(string)$key,$matches) || !is_array($entry)
                    || !is_string($entry['result']['text']??null) || trim($entry['result']['text'])==='') { continue; }
                $target=(int)$matches[1];
                if ($target===$me || Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',[$me,$target,self::USED])) { continue; }
                Store::insert('relations',['user_id'=>$me,'target_id'=>$target,'kind'=>self::USED,'created_at'=>current_time('mysql',true)]);
            }
            WordPressWrites::meta('user',$me,self::LEGACY_IMPORTED,true);
        });
    }

    public static function availableFor(int $me,array $targets): array
    {
        $targets=array_values(array_unique(array_filter(array_map('intval',$targets),static fn($id)=>$id>0 && $id!==$me)));
        if ($me<1 || !$targets) { return []; }
        self::importPreviousUse($me);
        $available=array_fill_keys($targets,true);
        $in=implode(',',array_fill(0,count($targets),'%d'));
        global $wpdb;
        $used=Store::rows('relations','user_id=%d AND kind=%s AND target_id IN ('.$in.')',[$me,self::USED,...$targets]);
        if ($wpdb->last_error!=='') { throw new \ASCLA\Core\Repositories\RepositoryException('No se pudo comprobar el historial de conversación.'); }
        foreach ($used as $row) {
            $available[(int)$row['target_id']]=false;
        }
        $conversations=Store::table('conversations');$messages=Store::table('messages');
        $keys=array_map(static fn($id)=>min($me,$id).':'.max($me,$id),$targets);
        $placeholders=implode(',',array_fill(0,count($keys),'%s'));
        $rows=$wpdb->get_col($wpdb->prepare("SELECT c.pair_key FROM {$conversations} c WHERE c.kind='direct' AND c.pair_key IN ({$placeholders}) AND EXISTS (SELECT 1 FROM {$messages} m WHERE m.conversation_id=c.id)",...$keys));
        if ($wpdb->last_error!=='') { throw new \ASCLA\Core\Repositories\RepositoryException('No se pudo comprobar el historial de conversación.'); }
        foreach ($rows as $key) {
            [$a,$b]=array_map('intval',explode(':',$key));$available[$a===$me?$b:$a]=false;
        }
        return $available;
    }

    public static function claim(int $me,int $target): int
    {
        self::importPreviousUse($me);
        return Connections::lockPair($me,$target,static function() use($me,$target) {
            Access::require(!empty(self::availableFor($me,[$target])[$target]),'El mensaje sugerido solo está disponible antes de la primera conversación y una vez por persona.',409);
            return Store::insert('relations',['user_id'=>$me,'target_id'=>$target,'kind'=>self::USED,'created_at'=>current_time('mysql',true)]);
        });
    }

    public static function release(int $claim,int $me): void
    {
        Store::delete('relations',['id'=>$claim,'user_id'=>$me,'kind'=>self::USED]);
    }
}
