<?php
namespace ASCLA\Core\Integrations;
final class YouTubeVideoProvider implements VideoProviderInterface
{
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
    public function transcript(string $videoId): array
    {
        $token=GoogleOAuth::accessToken(get_current_user_id());
        $response=wp_remote_get('https://www.googleapis.com/youtube/v3/captions?'.http_build_query(['part'=>'snippet','videoId'=>$videoId]),['headers'=>['Authorization'=>'Bearer '.$token],'timeout'=>25,'redirection'=>0,'limit_response_size'=>1048576]);
        if (is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) { throw new \RuntimeException('YouTube: permisos insuficientes o API no configurada. Puede cargar una transcripción autorizada manualmente.'); }
        $data=json_decode(wp_remote_retrieve_body($response),true); $caption=$data['items'][0]['id']??'';
        if (!$caption) { throw new \RuntimeException('No se encontraron subtítulos accesibles para este video.'); }
        $response=wp_remote_get('https://www.googleapis.com/youtube/v3/captions/'.rawurlencode($caption).'?tfmt=srt',['headers'=>['Authorization'=>'Bearer '.$token],'timeout'=>30,'redirection'=>0,'limit_response_size'=>524288]);
        if (is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) { throw new \RuntimeException('No tiene permisos para descargar estos subtítulos.'); }
        return ['mode'=>'API REAL YouTube','text'=>wp_strip_all_tags(wp_remote_retrieve_body($response))];
    }
}
