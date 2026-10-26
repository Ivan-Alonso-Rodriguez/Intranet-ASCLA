<?php
namespace ASCLA\Core\Integrations;

/** Bounded Calendar HTTP requests and provider error handling. */
final class GoogleCalendarTransport
{
    public static function createPersonal(string $token,array $body,int $user,int $post): array
    {
        // A retry after a lost HTTP response must address the same Google event.
        $generation=(int)get_post_meta($post,'_ascla_event_reactivation_count',true);
        $suffix=$generation?'|reactivation:'.$generation:'';
        $body['id']=substr(hash('sha256',home_url('/').'|'.$user.'|'.$post.$suffix),0,32);
        $url='https://www.googleapis.com/calendar/v3/calendars/primary/events';
        $data=self::request($token,'POST',$url,$body,false,[200,201,409]);
        if (!empty($data['_conflict'])) {
            $remote=$body['id'];unset($body['id']);
            $data=self::request($token,'PATCH',$url.'/'.rawurlencode($remote),$body,false,[200]);
        }
        return $data;
    }
    public static function request(string $token,string $method,string $url,?array $body,bool $sendUpdates,array $allowed): array
    {
        if ($sendUpdates) { $url.=(str_contains($url,'?')?'&':'?').'sendUpdates=all'; }
        $args=[
            'method'=>$method,
            'timeout'=>25,
            'redirection'=>0,
            'limit_response_size'=>262144,
            'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],
        ];
        if ($body!==null) {
            $args['headers']['Content-Type']='application/json; charset=UTF-8';
            $args['body']=wp_json_encode($body);
        }
        $response=wp_remote_request($url,$args);
        if (is_wp_error($response)) { throw new IntegrationException('No se pudo conectar con Google Calendar. Inténtalo nuevamente.'); }
        return self::calendarResponse($response,$allowed);
    }
    private static function calendarResponse(array $response,array $allowed): array
    {
        $code=wp_remote_retrieve_response_code($response);
        if ($code===409 && in_array(409,$allowed,true)) { return ['_conflict'=>true]; }
        if (($code===404 || $code===410) && in_array($code,$allowed,true)) { return ['_missing'=>true]; }
        self::requireCalendarCode($code,$allowed);
        $raw=wp_remote_retrieve_body($response);
        $data=$raw===''?[]:json_decode($raw,true);
        return is_array($data)?$data:[];
    }
    private static function requireCalendarCode(int $code,array $allowed): void
    {
        if (in_array($code,$allowed,true)) { return; }
        throw new IntegrationException(match($code) {
            401=>'La autorización de Google Calendar expiró. Desconecta y vuelve a conectar tu cuenta.',
            403=>'Google Calendar rechazó la operación. Revisa que la API de Calendar esté habilitada y vuelve a conectar tu cuenta.',
            default=>'Google Calendar no pudo completar la operación. Revisa fechas, permisos y configuración OAuth.',
        });
    }
}
