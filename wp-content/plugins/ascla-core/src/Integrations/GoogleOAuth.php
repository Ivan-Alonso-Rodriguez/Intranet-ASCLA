<?php
namespace ASCLA\Core\Integrations;
use ASCLA\Core\Services\{Access,Settings,Audit,Content};
final class GoogleOAuth
{
    public static function boot(): void { add_action('admin_post_ascla_google_callback',[self::class,'callback']); }
    public static function connect(string $service): array
    {
        Access::require(in_array($service,['calendar','youtube'],true),'Servicio inválido.',400);
        if ($service==='youtube') { Access::require(current_user_can('ascla_moderate')); }
        $settings=Settings::get(); Access::require($settings['google_client_id']!==''&&Secrets::get('google_client_secret')!=='','Integración Google Calendar / YouTube no configurada.',400);
        $state=bin2hex(random_bytes(32)); set_transient('ascla_oauth_'.hash('sha256',$state),['user'=>get_current_user_id(),'service'=>$service,'session'=>hash('sha256',wp_get_session_token())],600);
        return ['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$settings['google_client_id'],'redirect_uri'=>admin_url('admin-post.php?action=ascla_google_callback'),'response_type'=>'code','scope'=>$service==='youtube'?'https://www.googleapis.com/auth/youtube.force-ssl':'https://www.googleapis.com/auth/calendar.events','access_type'=>'offline','prompt'=>'consent','state'=>$state], '', '&', PHP_QUERY_RFC3986)];
    }
    public static function callback(): void
    {
        try {
            Access::require(Access::member()); $state=sanitize_text_field(wp_unslash($_GET['state']??'')); $key='ascla_oauth_'.hash('sha256',$state); $stored=get_transient($key); delete_transient($key);
            Access::require($stored && (int)$stored['user']===get_current_user_id() && hash_equals($stored['session'],hash('sha256',wp_get_session_token())),'Estado OAuth inválido.');
            $code=sanitize_text_field(wp_unslash($_GET['code']??'')); Access::require($code!=='','Autorización cancelada.',400);
            $tokens=self::tokenRequest(['code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>admin_url('admin-post.php?action=ascla_google_callback')]);
            self::saveTokens(get_current_user_id(),$stored['service'],$tokens); Audit::record('oauth_connected',0,$stored['service']);
            wp_safe_redirect(\ASCLA\Core\Domain\Catalog::url('perfil',['google'=>'connected'])); exit;
        } catch (\Throwable $e) { wp_die('No se pudo conectar Google. Vuelva al perfil e intente de nuevo.','ASCLA',['response'=>400]); }
    }
    private static function tokenRequest(array $params): array
    {
        $params['client_id']=Settings::get()['google_client_id']; $params['client_secret']=Secrets::get('google_client_secret');
        $response=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>20,'redirection'=>0,'limit_response_size'=>65536,'body'=>$params]);
        if (is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) { throw new \RuntimeException('Google OAuth expirado o configuración inválida. Vuelva a conectar.'); }
        $tokens=json_decode(wp_remote_retrieve_body($response),true);
        if (empty($tokens['access_token'])) { throw new \RuntimeException('Google no devolvió una credencial válida.'); }
        return $tokens;
    }
    private static function saveTokens(int $user,string $service,array $tokens): void
    {
        $name='google_'.$service.'_'.$user; $old=json_decode(Secrets::get($name),true)?:[];
        $tokens['refresh_token']=$tokens['refresh_token']??$old['refresh_token']??''; $tokens['expires_at']=time()+(int)($tokens['expires_in']??3600);
        Secrets::set($name,wp_json_encode($tokens));
    }
    public static function accessToken(int $user,string $service='youtube'): string
    {
        $tokens=json_decode(Secrets::get('google_'.$service.'_'.$user),true)?:[];
        if (!$tokens) { throw new \RuntimeException('Integración Google '.ucfirst($service).' no configurada.'); }
        if (($tokens['expires_at']??0)<time()+60) {
            if (empty($tokens['refresh_token'])) { throw new \RuntimeException('Google OAuth expirado. Vuelva a conectar.'); }
            $new=self::tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$tokens['refresh_token']]); self::saveTokens($user,$service,$new); $tokens=$new;
        }
        return $tokens['access_token'];
    }
    public static function disconnect(string $service): array
    {
        Access::require(in_array($service,['calendar','youtube'],true),'Servicio inválido.',400);
        Secrets::remove('google_'.$service.'_'.get_current_user_id()); Audit::record('oauth_disconnected',0,$service); return ['ok'=>true];
    }
    public static function calendar(int $id,string $operation): array
    {
        Access::require(in_array($operation,['save','cancel'],true),'Operación inválida.',400);
        $post=Content::get($id); Access::require($post->post_type==='ascla_event'&&$post->post_status==='publish','Evento no disponible.',400);
        $token=self::accessToken(get_current_user_id(),'calendar'); $meta=(array)get_post_meta($id,'_ascla',true);
        $key='_ascla_google_event_'.$id; $remote=get_user_meta(get_current_user_id(),$key,true);
        if ($operation==='cancel'&&!$remote) { return ['ok'=>true]; }
        $url='https://www.googleapis.com/calendar/v3/calendars/primary/events'.($remote?'/'.rawurlencode($remote):'');
        $body=['summary'=>$post->post_title,'description'=>'Evento privado ASCLA. '.(!empty($meta['chatham'])?'Regla de Chatham House.':''),'start'=>['dateTime'=>$meta['start']],'end'=>['dateTime'=>$meta['end']],'location'=>$meta['location']??'','visibility'=>'private'];
        $response=wp_remote_request($url,['method'=>$operation==='cancel'?'DELETE':($remote?'PATCH':'POST'),'timeout'=>25,'redirection'=>0,'limit_response_size'=>262144,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>$operation==='cancel'?'':wp_json_encode($body)]);
        $code=wp_remote_retrieve_response_code($response);
        if (is_wp_error($response)||!in_array($code,[200,201,204],true)) { throw new \RuntimeException('Google Calendar no pudo completar la operación.'); }
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if ($operation==='cancel') { delete_user_meta(get_current_user_id(),$key); }
        elseif (!empty($data['id'])) { update_user_meta(get_current_user_id(),$key,sanitize_text_field($data['id'])); }
        Audit::record('calendar_'.$operation,$id); return ['ok'=>true];
    }
}
