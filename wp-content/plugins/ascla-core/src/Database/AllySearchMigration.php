<?php
namespace ASCLA\Core\Database;

use ASCLA\Core\Repositories\{Store,WordPressWrites,RepositoryException,AllySearch};

/** Backfill only a derived search index; preserve original ally data and history. */
final class AllySearchMigration
{
    public const OPTION='ascla_ally_search_index';
    public static function ready(): bool { return (int)get_option(self::OPTION,0)===1; }
    public static function run(): void
    {
        if(self::ready()) { return; }
        Store::atomic('ally_search_index',static function(){
            if(self::ready()) { return; }
            global $wpdb;$last=0;
            do {
                $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='ascla_ally' AND ID>%d ORDER BY ID LIMIT 200",$last));
                if($wpdb->last_error!=='') { throw new RepositoryException('No se pudo actualizar el índice de aliados.'); }
                foreach($ids as $id) {
                    $meta=(array)get_post_meta((int)$id,'_ascla',true);
                    if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo actualizar el índice de aliados.'); }
                    WordPressWrites::meta('post',(int)$id,'_ascla_alliance_type',AllySearch::value($meta));$last=(int)$id;
                }
            }while(count($ids)===200);
            WordPressWrites::option(self::OPTION,1);
        });
    }
}
