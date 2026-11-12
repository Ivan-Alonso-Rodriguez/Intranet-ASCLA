<?php
namespace ASCLA\Core\Database;

use ASCLA\Core\Repositories\{Store,WordPressWrites,RepositoryException};
use ASCLA\Core\Services\VideoEvidence;

final class VideoEvidenceMigration
{
    private const OPTION='ascla_video_evidence_index';
    private const VERSION=2;
    public static function ready(): bool { return (int)get_option(self::OPTION,0)>=self::VERSION; }

    public static function run(): void
    {
        if (self::ready()) { return; }
        Store::tryLock(self::OPTION,static function(){
            if (self::ready()) { return; }
            global $wpdb;
            $last=0;
            do {
                $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='ascla_resource' AND ID>%d ORDER BY ID LIMIT 200",$last));
                if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudo preparar la búsqueda de videos.'); }
                foreach ($ids as $id) {
                    $last=(int)$id;
                    Store::atomic('content:'.$last,static function()use($last){
                        wp_cache_delete($last,'post_meta');
                        VideoEvidence::index($last,(array)get_post_meta($last,'_ascla',true));
                    });
                }
            } while (count($ids)===200);
            WordPressWrites::option(self::OPTION,self::VERSION);
        });
    }
}
