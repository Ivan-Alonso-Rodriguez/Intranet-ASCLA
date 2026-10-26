<?php
namespace ASCLA\Core\Database;

use ASCLA\Core\Repositories\{Store,WordPressWrites,RepositoryException};

/** Backfill the event modality search index without changing event content or states. */
final class EventFiltersMigration
{
    public const OPTION='ascla_event_filter_index';
    public static function ready(): bool { return (int)get_option(self::OPTION,0)===1; }
    public static function run(): void
    {
        if (self::ready()) { return; }
        Store::atomic('event_filter_index',static function(){
            if (self::ready()) { return; }
            global $wpdb;$last=0;
            do {
                $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='ascla_event' AND ID>%d ORDER BY ID LIMIT 200",$last));
                if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo actualizar el índice de eventos.'); }
                foreach ($ids as $id) {
                    $meta=(array)get_post_meta((int)$id,'_ascla',true);
                    WordPressWrites::meta('post',(int)$id,'_ascla_modality',$meta['modality']??'Virtual');$last=(int)$id;
                }
            } while (count($ids)===200);
            WordPressWrites::option(self::OPTION,1);
        });
    }
}
