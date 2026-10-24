<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Integrations\SupadataVideoProvider;
use ASCLA\Core\Jobs\DeferredJob;
use ASCLA\Core\Repositories\Store;

final class VideoTranscripts
{
    public const MAX_CHARACTERS=2000000;
    public static function obtain(int $id): array
    {
        Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador pueden consultar transcripciones.',403);
        $post=Content::get($id);$meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_resource','Seleccione un recurso.',400);
        if (trim((string)($meta['transcript']??''))!=='') { return self::result($id,$meta); }
        Access::require(!empty($meta['video_id']),'El recurso no tiene video de YouTube ni transcripción autorizada.',400);
        Access::require(Settings::get()['youtube_mode']==='supadata','No hay una transcripción verificable. Activa Supadata en Ajustes o añade una transcripción autorizada manualmente.',409);
        $videoId=(string)$meta['video_id'];
        try { $video=(new SupadataVideoProvider())->transcript($videoId); }
        catch (\Throwable $error) {
            self::update($id,$videoId,static function(array $current)use($error){
                $current['transcript_status']=$error instanceof DeferredJob?'pending':'unavailable';
                $current['transcript_error']=$error->getMessage();
                $current['transcript_checked_at']=current_datetime()->format(DATE_ATOM);
                return $current;
            });
            throw $error;
        }
        $meta=self::update($id,$videoId,static function(array $current)use($video,$videoId){
            $current['transcript']=$video['text'];$current['transcript_status']='supadata';
            $current['transcript_video_id']=$videoId;$current['transcript_mode']=$video['mode'];
            $current['transcript_language']=$video['language'];
            $current['transcript_checked_at']=current_datetime()->format(DATE_ATOM);
            unset($current['transcript_error']);return $current;
        });
        return self::result($id,$meta);
    }
    /** Do not overwrite a manual edit, changed video or deletion while the HTTP request was in flight. */
    private static function update(int $id,string $videoId,callable $change): array
    {
        return Store::lock('content:'.$id,static function()use($id,$videoId,$change){
            $post=get_post($id);$current=(array)get_post_meta($id,'_ascla',true);
            Access::require($post&&$post->post_status!=='trash'&&($current['video_id']??'')===$videoId,'El recurso o su video cambió durante la consulta. Vuelve a abrirlo.',409);
            if (trim((string)($current['transcript']??''))!=='') { return $current; }
            $current=$change($current);update_post_meta($id,'_ascla',wp_slash($current));return $current;
        });
    }
    private static function result(int $id,array $meta): array
    {
        return ['resource_id'=>$id,'mode'=>$meta['transcript_mode']??'Transcripción manual autorizada','characters'=>mb_strlen((string)($meta['transcript']??'')),'message'=>'La transcripción completa está guardada en el recurso.'];
    }
}
