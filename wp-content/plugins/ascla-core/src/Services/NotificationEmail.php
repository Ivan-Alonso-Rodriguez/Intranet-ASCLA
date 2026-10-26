<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class NotificationEmail
{
    private const MESSAGE_EMAIL_META='_ascla_message_email_last_sent';
    private const MESSAGE_EMAIL_WINDOW=900;
    private const KINDS=[
        'connection'=>'connections','connection_accepted'=>'connections',
        'conversation_request'=>'messages','conversation_accepted'=>'messages','conversation_group'=>'messages','message'=>'messages',
        'event'=>'events','event_reminder'=>'events','event_waitlist_available'=>'events','event_cancelled'=>'events','event_updated'=>'events','microevent'=>'events',
        'event_reactivated'=>'events',
        'support_received'=>'support','support_request'=>'support','support_update'=>'support',
    ];

    public static function send(int $user,string $kind,string $label,string $url,array $context): bool
    {
        $category=self::KINDS[sanitize_key($kind)]??'';
        if ($category==='' || empty(Notifications::emailPreferences($user)[$category])) { return true; }
        $deliver=static fn()=>\ASCLA\Core\Integrations\Mailer::notification($user,$category,self::copy($kind,$label,$context),$url);
        if (sanitize_key($kind)!=='message') { return $deliver(); }
        return self::messageAllowed($user,$context,$deliver);
    }

    private static function copy(string $kind,string $fallback,array $context): string
    {
        $actor=absint($context['actor']??0);
        $name=$actor && Access::member($actor)?Profiles::publicName($actor):'Un asociado';
        return match (sanitize_key($kind)) {
            'connection'=>$name.' quiere conectar contigo en ASCLA.',
            'connection_accepted'=>$name.' aceptó tu solicitud de conexión en ASCLA.',
            'conversation_request'=>$name.' te envió una solicitud de conversación en ASCLA.',
            'conversation_accepted'=>$name.' aceptó tu solicitud de conversación en ASCLA.',
            'conversation_group'=>$name.' te añadió a una conversación grupal en ASCLA.',
            'message'=>'Tienes mensajes nuevos en ASCLA.',
            'event'=>'Tienes una nueva invitación a un evento de ASCLA.',
            'event_waitlist_available'=>'Se liberó un cupo para ti en un evento de ASCLA. Entra para confirmar tu asistencia.',
            'event_cancelled'=>'Un evento en el que participabas o estabas en espera fue cancelado. Revisa los detalles en ASCLA.',
            'event_reactivated'=>'Un evento relacionado contigo vuelve a estar activo. Revisa la fecha y el estado de tu inscripción en ASCLA.',
            'event_updated'=>'Un evento relacionado contigo cambió información importante. Revisa los detalles actualizados en ASCLA.',
            'event_reminder'=>'Se acerca un encuentro en el que confirmaste tu participación. Revisa el horario y los detalles en ASCLA.',
            'microevent'=>'Tienes una nueva invitación a un círculo ASCLA.',
            'support_received','support_request','support_update'=>'Hay una actualización de una solicitud en ASCLA. Ingresa para consultar sus detalles.',
            default=>Access::excerpt($fallback,255),
        };
    }

    private static function messageAllowed(int $user,array $context,callable $deliver): bool
    {
        $conversation=(($context['type']??'')==='conversation')?absint($context['id']??0):0;
        $key=(string)$conversation;
        return (bool)Store::lock('message-email:'.$user.':'.$key,static fn()=>self::messageAllowedLocked($user,$key,$deliver));
    }

    private static function recentMessageHistory(int $user,int $now): array
    {
        $history=get_user_meta($user,self::MESSAGE_EMAIL_META,true);
        $history=is_array($history)?$history:[];
        foreach ($history as $conversation=>$sentAt) {
            if ((int)$sentAt < $now-DAY_IN_SECONDS) { unset($history[$conversation]); }
        }
        return $history;
    }

    private static function trimMessageHistory(array $history): array
    {
        if (count($history)>50) { arsort($history);$history=array_slice($history,0,50,true); }
        return $history;
    }

    private static function messageAllowedLocked(int $user,string $key,callable $deliver): bool
    {
        $now=time();$history=self::recentMessageHistory($user,$now);$last=(int)($history[$key]??0);
        if ($last>0 && ($now-$last)<self::MESSAGE_EMAIL_WINDOW) {
            if (count($history)>50) { update_user_meta($user,self::MESSAGE_EMAIL_META,self::trimMessageHistory($history)); }
            return true;
        }
        if (!$deliver()) { return false; }
        $history[$key]=$now;
        update_user_meta($user,self::MESSAGE_EMAIL_META,self::trimMessageHistory($history));
        return true;
    }
}

