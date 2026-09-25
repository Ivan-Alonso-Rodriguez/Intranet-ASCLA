<?php
namespace ASCLA\Core\Integrations;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\{Access,Settings,Audit,Content};

final class GoogleOAuth
{
    private const ORGANIZER_USER_META='_ascla_google_organizer_user';
    private const ORGANIZER_EVENT_META='_ascla_google_organizer_event';

    public static function boot(): void { add_action('admin_post_ascla_google_callback',[self::class,'callback']); }

    public static function connect(string $service): array
    {
        Access::require(in_array($service,['calendar','youtube'],true),'Servicio inválido.',400);
        if ($service==='youtube') { Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador pueden conectar YouTube.',403); }
        $settings=Settings::get();
        Access::require($settings['google_client_id']!==''&&Secrets::get('google_client_secret')!=='','Integración Google Calendar / YouTube no configurada.',400);
        $state=bin2hex(random_bytes(32));
        set_transient('ascla_oauth_'.hash('sha256',$state),['user'=>get_current_user_id(),'service'=>$service,'session'=>hash('sha256',wp_get_session_token())],600);
        return ['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>$settings['google_client_id'],
            'redirect_uri'=>admin_url('admin-post.php?action=ascla_google_callback'),
            'response_type'=>'code',
            'scope'=>$service==='youtube'?'https://www.googleapis.com/auth/youtube.force-ssl':'https://www.googleapis.com/auth/calendar.events',
            'access_type'=>'offline',
            'prompt'=>'consent',
            'include_granted_scopes'=>'true',
            'state'=>$state,
        ],'', '&', PHP_QUERY_RFC3986)];
    }

    public static function callback(): void
    {
        try {
            Access::require(Access::member());
            $state=sanitize_text_field(wp_unslash($_GET['state']??''));
            $key='ascla_oauth_'.hash('sha256',$state);
            $stored=get_transient($key); delete_transient($key);
            Access::require($stored && (int)$stored['user']===get_current_user_id() && hash_equals($stored['session'],hash('sha256',wp_get_session_token())),'Estado OAuth inválido.');
            if($stored['service']==='youtube'){Access::require(Access::canPublish());}
            $code=sanitize_text_field(wp_unslash($_GET['code']??'')); Access::require($code!=='','Autorización cancelada.',400);
            $tokens=self::tokenRequest(['code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>admin_url('admin-post.php?action=ascla_google_callback')]);
            self::saveTokens(get_current_user_id(),$stored['service'],$tokens); Audit::record('oauth_connected',0,$stored['service']);
            wp_safe_redirect(\ASCLA\Core\Domain\Catalog::url('perfil',['google'=>'connected'])); exit;
        } catch (\Throwable $e) {
            wp_die('No se pudo conectar Google. Vuelva al perfil e intente de nuevo.','ASCLA',['response'=>400]);
        }
    }

    private static function tokenRequest(array $params): array
    {
        $params['client_id']=Settings::get()['google_client_id']; $params['client_secret']=Secrets::get('google_client_secret');
        $response=wp_remote_post('https://oauth2.googleapis.com/token',['timeout'=>20,'redirection'=>0,'limit_response_size'=>65536,'body'=>$params]);
        if (is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) { throw new IntegrationException('Google OAuth expirado o configuración inválida. Vuelva a conectar.'); }
        $tokens=json_decode(wp_remote_retrieve_body($response),true);
        if (empty($tokens['access_token'])) { throw new IntegrationException('Google no devolvió una credencial válida.'); }
        return $tokens;
    }

    private static function saveTokens(int $user,string $service,array $tokens): void
    {
        $name='google_'.$service.'_'.$user; $old=json_decode(Secrets::get($name),true)?:[];
        $tokens['refresh_token']=$tokens['refresh_token']??$old['refresh_token']??''; $tokens['expires_at']=time()+(int)($tokens['expires_in']??3600);
        Secrets::set($name,wp_json_encode($tokens));
    }

    public static function connected(int $user,string $service='calendar'): bool
    {
        return $user>0 && Secrets::get('google_'.$service.'_'.$user)!=='';
    }

    public static function accessToken(int $user,string $service='youtube'): string
    {
        $tokens=json_decode(Secrets::get('google_'.$service.'_'.$user),true)?:[];
        if (!$tokens) { throw new IntegrationException('Integración Google '.ucfirst($service).' no configurada.'); }
        if (($tokens['expires_at']??0)<time()+60) {
            if (empty($tokens['refresh_token'])) { throw new IntegrationException('Google OAuth expirado. Vuelva a conectar.'); }
            $new=self::tokenRequest(['grant_type'=>'refresh_token','refresh_token'=>$tokens['refresh_token']]);
            self::saveTokens($user,$service,$new);
            $tokens=json_decode(Secrets::get('google_'.$service.'_'.$user),true)?:$new;
        }
        return (string)($tokens['access_token']??'');
    }

    public static function disconnect(string $service): array
    {
        Access::require(in_array($service,['calendar','youtube'],true),'Servicio inválido.',400);
        Secrets::remove('google_'.$service.'_'.get_current_user_id()); Audit::record('oauth_disconnected',0,$service); return ['ok'=>true];
    }

    /** Personal copy for the current user, or organizer synchronization for event managers. */
    public static function calendar(int $id,string $operation): array
    {
        Access::require(in_array($operation,['save','cancel','organize'],true),'Operación inválida.',400);
        $post=Content::get($id); Access::require($post->post_type==='ascla_event'&&$post->post_status==='publish','Evento no disponible.',400);
        $meta=(array)get_post_meta($id,'_ascla',true);
        if ($operation!=='cancel') {
            Access::require(empty($meta['cancelled']),'El evento fue cancelado.',409);
            Access::require(strtotime((string)($meta['end']??''))>time(),'El evento ya finalizó.',400);
        }
        if ($operation==='organize') {
            Access::require(Access::canPublish() && Content::canEdit($post),'No puede sincronizar este evento como organizador.',403);
            return self::syncOrganizerEvent($id,get_current_user_id(),false);
        }
        return self::personalCalendar($post,$meta,$operation);
    }

    private static function personalCalendar(\WP_Post $post,array $meta,string $operation): array
    {
        $user=get_current_user_id(); $token=self::accessToken($user,'calendar');
        $organizerUser=absint(get_post_meta($post->ID,self::ORGANIZER_USER_META,true));
        $organizerRemote=sanitize_text_field((string)get_post_meta($post->ID,self::ORGANIZER_EVENT_META,true));
        if ($organizerUser===$user && $organizerRemote!=='') {
            if ($operation==='cancel') { throw new IntegrationException('Este es el calendario organizador del evento. Para retirarlo de Google, cancela el evento desde ASCLA.'); }
            return self::syncOrganizerEvent($post->ID,$user,false);
        }
        $key='_ascla_google_event_'.$post->ID;
        $remote=sanitize_text_field((string)get_user_meta($user,$key,true));
        if ($operation==='cancel') {
            return self::cancelPersonalCalendar($user,$token,$post->ID,$key,$remote);
        }
        $body=self::eventBody($post,$meta,[]); $data=[];
        if ($remote!=='') {
            $data=self::request($token,'PATCH',self::eventsUrl($remote),$body,false,[200,404,410]);
            if (!empty($data['_missing'])) { $remote=''; delete_user_meta($user,$key); }
        }
        if ($remote==='') { $data=self::request($token,'POST',self::eventsUrl(),$body,false,[200,201]); }
        $remoteId=sanitize_text_field((string)($data['id']??$remote));
        if ($remoteId==='') { throw new IntegrationException('Google Calendar no devolvió el identificador del evento creado.'); }
        update_user_meta($user,$key,$remoteId); Audit::record('calendar_save',$post->ID);
        return ['ok'=>true,'event_id'=>$remoteId];
    }

    private static function cancelPersonalCalendar(int $user,string $token,int $postId,string $key,string $remote): array
    {
        if ($remote==='') { return ['ok'=>true]; }
        self::request($token,'DELETE',self::eventsUrl($remote),null,false,[200,204,404,410]);
        delete_user_meta($user,$key);
        Audit::record('calendar_cancel',$postId);
        return ['ok'=>true,'event_id'=>''];
    }

    /**
     * Ensure that a published ASCLA event exists in one connected Google Calendar.
     * Guests are read from ASCLA registrations and are hidden from one another in Google.
     */
    public static function syncOrganizerEvent(int $id,int $preferredUser=0,bool $notifyGuests=false): array
    {
        $post=Content::get($id); $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_event'&&$post->post_status==='publish','Evento no disponible.',400);
        Access::require(empty($meta['cancelled']),'El evento fue cancelado.',409);
        Access::require(strtotime((string)($meta['end']??''))>time(),'El evento ya finalizó.',400);

        $storedUser=absint(get_post_meta($id,self::ORGANIZER_USER_META,true));
        $remote=sanitize_text_field((string)get_post_meta($id,self::ORGANIZER_EVENT_META,true));
        $organizer=self::resolveOrganizer($post,$storedUser,$preferredUser,$remote!=='');
        if (!$organizer) { throw new IntegrationException('Conecta Google Calendar en Mi perfil para sincronizar el evento y enviar invitaciones de calendario.'); }
        if ($remote==='') {
            $personal=sanitize_text_field((string)get_user_meta($organizer,'_ascla_google_event_'.$id,true));
            if ($personal!=='') { $remote=$personal; }
        }

        $token=self::accessToken($organizer,'calendar');
        $existing=[];
        if ($remote!=='') {
            $existing=self::request($token,'GET',self::eventsUrl($remote),null,false,[200,404,410]);
            if (!empty($existing['_missing'])) {
                $remote=''; $existing=[];
                delete_post_meta($id,self::ORGANIZER_EVENT_META);
            }
        }

        $attendees=self::eventAttendees($id,$organizer,(array)($existing['attendees']??[]));
        $method=$remote!==''?'PATCH':'POST';
        $url=self::eventsUrl($remote);
        $data=self::request($token,$method,$url,self::eventBody($post,$meta,$attendees),$notifyGuests,[200,201]);
        $remoteId=sanitize_text_field((string)($data['id']??$remote));
        if ($remoteId==='') { throw new IntegrationException('Google Calendar no devolvió el identificador del evento creado.'); }
        update_post_meta($id,self::ORGANIZER_USER_META,$organizer);
        update_post_meta($id,self::ORGANIZER_EVENT_META,$remoteId);
        Audit::record($method==='POST'?'calendar_organize':'calendar_update',$id,'guests_'.count($attendees));
        return ['ok'=>true,'event_id'=>$remoteId,'organizer_user'=>$organizer,'attendees'=>count($attendees)];
    }

    /** Add newly invited ASCLA members as Google Calendar guests and ask Google to email them. */
    public static function inviteEvent(int $id,array $users): array
    {
        $users=array_values(array_unique(array_filter(array_map('absint',$users))));
        $post=get_post($id);
        if (!$users || !$post || $post->post_type!=='ascla_event') {
            return ['available'=>false,'sent'=>0];
        }
        $stored=absint(get_post_meta($id,self::ORGANIZER_USER_META,true));
        $preferred=0;
        foreach ([$stored,(int)$post->post_author,get_current_user_id()] as $candidate) {
            if ($candidate>0 && self::connected($candidate)) { $preferred=$candidate; break; }
        }
        if (!$preferred) {
            return ['available'=>false,'sent'=>0,'message'=>'Google Calendar no está conectado en la cuenta organizadora.'];
        }
        $result=self::syncOrganizerEvent($id,$preferred,true);
        $organizerAccount=get_userdata((int)$result['organizer_user']);
        $organizerEmail=strtolower(trim((string)($organizerAccount?$organizerAccount->user_email:'')));
        $sent=0;
        foreach ($users as $uid) {
            $user=get_userdata($uid); $email=strtolower(trim((string)($user->user_email??'')));
            if ($email!=='' && is_email($email) && $email!==$organizerEmail) { $sent++; }
        }
        return ['available'=>true,'sent'=>$sent,'event_id'=>$result['event_id'],'attendees'=>$result['attendees']];
    }

    /** Delete the organizer event and let Google send cancellation updates to guests. */
    public static function cancelOrganizerEvent(int $id): array
    {
        $organizer=absint(get_post_meta($id,self::ORGANIZER_USER_META,true));
        $remote=sanitize_text_field((string)get_post_meta($id,self::ORGANIZER_EVENT_META,true));
        if (!$organizer || $remote==='' || !self::connected($organizer)) { return ['ok'=>true,'available'=>false]; }
        $token=self::accessToken($organizer,'calendar');
        self::request($token,'DELETE',self::eventsUrl($remote),null,true,[200,204,404,410]);
        delete_post_meta($id,self::ORGANIZER_EVENT_META);
        Audit::record('calendar_cancel',$id,'organizer_'.$organizer);
        return ['ok'=>true,'available'=>true];
    }

    private static function resolveOrganizer(\WP_Post $post,int $stored,int $preferred,bool $remoteExists): int
    {
        if ($stored>0 && self::connected($stored)) { return $stored; }
        if ($remoteExists && $stored>0) { return 0; }
        $organizer=0;
        foreach (array_unique([$preferred,(int)$post->post_author,get_current_user_id()]) as $candidate) {
            if ($candidate>0 && self::connected($candidate)) { $organizer=$candidate; break; }
        }
        return $organizer;
    }

    private static function eventAttendees(int $id,int $organizer,array $existing): array
    {
        $organizerAccount=get_userdata($organizer);
        $organizerEmail=strtolower(trim((string)($organizerAccount->user_email??'')));
        $byEmail=self::existingAttendeeMap($existing,$organizerEmail);
        foreach (Store::rows('registrations',"event_id=%d AND status IN ('invited','accepted','offered')",[$id],'ORDER BY id ASC') as $row) {
            $account=get_userdata(absint($row['user_id']??0));
            $email=strtolower(trim((string)($account->user_email??'')));
            if (!is_email($email) || $email===$organizerEmail) { continue; }
            if (!isset($byEmail[$email])) { $byEmail[$email]=['email'=>$email]; }
        }
        return array_values($byEmail);
    }

    private static function existingAttendeeMap(array $existing,string $organizerEmail): array
    {
        $byEmail=[];
        foreach ($existing as $attendee) {
            if (!is_array($attendee)) { continue; }
            $email=strtolower(trim((string)($attendee['email']??'')));
            if (!is_email($email) || $email===$organizerEmail) { continue; }
            $clean=['email'=>$email];
            foreach (['displayName','responseStatus','comment','additionalGuests','optional'] as $field) {
                if (array_key_exists($field,$attendee)) { $clean[$field]=$attendee[$field]; }
            }
            $byEmail[$email]=$clean;
        }
        return $byEmail;
    }

    private static function eventBody(\WP_Post $post,array $meta,array $attendees): array
    {
        $description=trim(wp_strip_all_tags((string)$post->post_content));
        if (!empty($meta['chatham'])) { $description="Sesión bajo la Regla de Chatham House.\n\n".$description; }
        $body=[
            'summary'=>$post->post_title,
            'description'=>$description,
            'start'=>['dateTime'=>(string)($meta['start']??'')],
            'end'=>['dateTime'=>(string)($meta['end']??'')],
            'location'=>(string)($meta['location']??''),
            'visibility'=>'private',
            'guestsCanInviteOthers'=>false,
            'guestsCanModify'=>false,
            'guestsCanSeeOtherGuests'=>false,
        ];
        if (!empty($meta['url'])) { $body['description'].="\n\nEnlace del encuentro: ".esc_url_raw((string)$meta['url']); }
        if ($attendees) { $body['attendees']=$attendees; }
        return $body;
    }

    private static function eventsUrl(string $eventId=''): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/primary/events'.($eventId!==''?'/'.rawurlencode($eventId):'');
    }

    private static function request(string $token,string $method,string $url,?array $body,bool $sendUpdates,array $allowed): array
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
        $code=wp_remote_retrieve_response_code($response);
        if (($code===404 || $code===410) && in_array($code,$allowed,true)) { return ['_missing'=>true]; }
        if (!in_array($code,$allowed,true)) {
            if ($code===401) { throw new IntegrationException('La autorización de Google Calendar expiró. Desconecta y vuelve a conectar tu cuenta.'); }
            if ($code===403) { throw new IntegrationException('Google Calendar rechazó la operación. Revisa que la API de Calendar esté habilitada y vuelve a conectar tu cuenta.'); }
            throw new IntegrationException('Google Calendar no pudo completar la operación. Revisa fechas, permisos y configuración OAuth.');
        }
        $raw=wp_remote_retrieve_body($response);
        if ($raw==='') { return []; }
        $data=json_decode($raw,true);
        return is_array($data)?$data:[];
    }
}
