<?php
namespace ASCLA\Core\Integrations;
final class YouTubeVideoProvider implements VideoProviderInterface
{
    private const BEARER='Bearer ';
    public static function videoId(string $url): string
    {
        $p=wp_parse_url($url); $host=strtolower($p['host']??''); $id='';
        if ($host==='youtu.be') { $id=trim($p['path']??'','/'); }
        elseif (in_array($host,['youtube.com','www.youtube.com','m.youtube.com','www.youtube-nocookie.com'],true)) {
            parse_str($p['query']??'',$q); $id=$q['v']??'';
            if (!$id&&preg_match('~^/(?:embed|shorts|live)/([\w-]{11})/?$~',$p['path']??'',$m)) { $id=$m[1]; }
        }
        return is_string($id)&&preg_match('/^[A-Za-z0-9_-]{11}$/',$id)?$id:'';
    }
    public static function thumbnail(string $videoId): string
    {
        return preg_match('/^[A-Za-z0-9_-]{11}$/',$videoId)?'https://i.ytimg.com/vi/'.$videoId.'/hqdefault.jpg':'';
    }
    public function metadata(string $videoId): array
    {
        if (!self::thumbnail($videoId)) { throw new IntegrationException('ID de video no válido.'); }
        $token=GoogleOAuth::accessToken(get_current_user_id());
        $response=wp_remote_get('https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['part'=>'snippet,contentDetails','id'=>$videoId]),['headers'=>['Authorization'=>self::BEARER.$token],'timeout'=>25,'redirection'=>0,'limit_response_size'=>1048576]);
        if (is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) { throw new IntegrationException('No se pudieron consultar los datos de YouTube. Revise permisos y conexión.'); }
        $data=json_decode(wp_remote_retrieve_body($response),true); $item=$data['items'][0]??null;
        if (!$item) { throw new IntegrationException('Video no disponible para esta cuenta.'); }
        try {
            $interval=new \DateInterval($item['contentDetails']['duration']??'PT0S');
            $duration=$interval->d*86400+$interval->h*3600+$interval->i*60+$interval->s;
        } catch (\Exception $e) { throw new IntegrationException('YouTube devolvió una duración no válida.'); }
        return ['mode'=>'API REAL YouTube','duration_seconds'=>$duration,'thumbnail_url'=>self::thumbnail($videoId),'source_title'=>sanitize_text_field($item['snippet']['title']??'')];
    }
    private static function publicDurationFromBody(string $body): int
    {
        $duration=0;
        if (preg_match('/"lengthSeconds"\s*:\s*"?(\d{1,7})"?/',$body,$match)) { $duration=(int)$match[1]; }
        elseif (preg_match('/"approxDurationMs"\s*:\s*"?(\d{1,12})"?/',$body,$match)) { $duration=(int)round(((int)$match[1])/1000); }
        return $duration>0 && $duration<=604800?$duration:0;
    }

    private static function publicDurationFromUrl(string $url): int
    {
        $response=wp_safe_remote_get($url,['timeout'=>7,'redirection'=>2,'limit_response_size'=>4194304,'headers'=>['User-Agent'=>'Mozilla/5.0 (compatible; ASCLA/'.(defined('ASCLA_VERSION')?ASCLA_VERSION:'1').')','Accept-Language'=>'es,en;q=0.8']]);
        return is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200?0:self::publicDurationFromBody(wp_remote_retrieve_body($response));
    }

    private static function publicDuration(string $videoId): int
    {
        $urls=[
            'https://www.youtube.com/watch?v='.rawurlencode($videoId).'&hl=es&bpctr=9999999999&has_verified=1',
            'https://www.youtube.com/embed/'.rawurlencode($videoId).'?hl=es',
            'https://www.youtube-nocookie.com/embed/'.rawurlencode($videoId).'?hl=es',
        ];
        $duration=0;
        foreach ($urls as $url) {
            $duration=self::publicDurationFromUrl($url);
            if ($duration>0) { break; }
        }
        return $duration;
    }

    /** Best-effort public metadata fallback. It reads YouTube's own player metadata and never infers duration from captions. */
    public static function publicMetadata(string $videoId): array
    {
        if (!self::thumbnail($videoId)) { throw new IntegrationException('ID de video no válido.'); }
        $key='ascla_yt_public_'.hash('sha256',$videoId);$cached=get_transient($key);
        if (is_array($cached) && (int)($cached['duration_seconds']??0)>0) { return $cached; }
        $duration=self::publicDuration($videoId);
        if ($duration<=0) { throw new IntegrationException('YouTube no expuso una duración verificable para este video.'); }
        $data=['mode'=>'Metadatos públicos de YouTube','duration_seconds'=>$duration,'thumbnail_url'=>self::thumbnail($videoId),'source_title'=>''];
        set_transient($key,$data,DAY_IN_SECONDS);
        return $data;
    }
    public function transcript(string $videoId): array
    {
        $token=GoogleOAuth::accessToken(get_current_user_id());
        $response=wp_remote_get('https://www.googleapis.com/youtube/v3/captions?'.http_build_query(['part'=>'snippet','videoId'=>$videoId]),['headers'=>['Authorization'=>self::BEARER.$token],'timeout'=>25,'redirection'=>0,'limit_response_size'=>1048576]);
        if (is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) { throw new IntegrationException('YouTube: permisos insuficientes o API no configurada. Puede cargar una transcripción autorizada manualmente.'); }
        $data=json_decode(wp_remote_retrieve_body($response),true); $caption=$data['items'][0]['id']??'';
        if (!$caption) { throw new IntegrationException('No se encontraron subtítulos accesibles para este video.'); }
        $response=wp_remote_get('https://www.googleapis.com/youtube/v3/captions/'.rawurlencode($caption).'?tfmt=srt',['headers'=>['Authorization'=>self::BEARER.$token],'timeout'=>30,'redirection'=>0,'limit_response_size'=>524288]);
        if (is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) { throw new IntegrationException('No tiene permisos para descargar estos subtítulos.'); }
        return ['mode'=>'API REAL YouTube','text'=>wp_strip_all_tags(wp_remote_retrieve_body($response))];
    }
}
