<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Notifications
{
    private const EMAIL_META='_ascla_email_notifications';
    private const EMAIL_DEFAULTS=['connections'=>true,'messages'=>true,'events'=>true,'support'=>true];
    private const TOAST_KINDS=[
        'message',
        'connection',
        'connection_accepted',
        'conversation_request',
        'conversation_accepted',
        'event',
        'event_updated',
        'event_cancelled',
        'event_waitlist_available',
    ];

    public static function emailPreferences(int $user=0): array
    {
        $user=$user?:get_current_user_id();
        if (!$user || !Access::member($user)) { return self::EMAIL_DEFAULTS; }
        $stored=get_user_meta($user,self::EMAIL_META,true);
        $stored=is_array($stored)?$stored:[];
        $preferences=self::EMAIL_DEFAULTS;
        foreach (array_keys(self::EMAIL_DEFAULTS) as $key) {
            if (array_key_exists($key,$stored)) { $preferences[$key]=(bool)$stored[$key]; }
        }
        return $preferences;
    }

    public static function saveEmailPreferences(int $user,array $input): array
    {
        Access::require($user>0 && Access::member($user),'Usuario no válido.',400);
        $preferences=self::emailPreferences($user);
        foreach (array_keys(self::EMAIL_DEFAULTS) as $key) {
            if (array_key_exists($key,$input)) { $preferences[$key]=rest_sanitize_boolean($input[$key]); }
        }
        update_user_meta($user,self::EMAIL_META,$preferences);
        return $preferences;
    }


    private static function data(int $user,string $kind,string $label,string $url,array $context): array
    {
        $safe=[];
        if (in_array($context['type']??'', ['post','profile','conversation','job'],true)) {
            $safe=['type'=>$context['type'],'id'=>absint($context['id']??0),'actor'=>absint($context['actor']??0)];
            if (!empty($context['note'])) { $safe['note']=Access::excerpt((string)$context['note'],160); }
            if (!empty($context['changes']) && is_array($context['changes'])) {
                $allowed=['title','start','end','modality','location','url','capacity'];
                $safe['changes']=array_values(array_unique(array_intersect($allowed,array_map('sanitize_key',$context['changes']))));
            }
        }
        return ['user_id'=>$user,'kind'=>sanitize_key($kind),'label'=>Access::excerpt($label,255),'url'=>esc_url_raw($url),'context'=>wp_json_encode($safe),'created_at'=>current_time('mysql',true)];
    }
    public static function send(int $user,string $kind,string $label,string $url='',array $context=[]): void
    {
        if ($user && Access::member($user)) {
            Store::insert('notifications',self::data($user,$kind,$label,$url,$context));
            NotificationEmail::send($user,$kind,$label,$url,$context);
        }
    }
    public static function once(int $user,string $key,string $kind,string $label,string $url='',array $context=[]): bool
    {
        if (!$user || !Access::member($user)) { return false; }
        $key=substr($key,0,96);
        return Store::lock('notice:'.$user.':'.$key,static function () use($user,$key,$kind,$label,$url,$context) {
            if (Store::count('notifications','user_id=%d AND event_key=%s',[$user,$key])) { return false; }
            Store::insert('notifications',self::data($user,$kind,$label,$url,$context)+['event_key'=>$key]);
            NotificationEmail::send($user,$kind,$label,$url,$context);
            return true;
        });
    }
    public static function latestId(): int
    {
        global $wpdb;
        $table=Store::table('notifications');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(id),0) FROM $table WHERE user_id=%d",get_current_user_id()));
    }

    /** Lightweight near-real-time feed. Reading a toast never removes it from the notification center. */
    public static function toastFeed(int $after): array
    {
        $after=max(0,$after);
        $rows=Store::rows('notifications','user_id=%d AND id>%d',[get_current_user_id(),$after],'ORDER BY id ASC LIMIT 100');
        $cursor=$after; $items=[];
        foreach ($rows as $row) {
            $cursor=max($cursor,(int)$row['id']);
            if (!empty($row['read_at']) || !in_array((string)$row['kind'],self::TOAST_KINDS,true)) { continue; }
            $items[]=NotificationTarget::view($row);
            if (count($items)>=12) { break; }
        }
        return ['cursor'=>$cursor,'items'=>$items]+self::summary();
    }

    public static function list(): array
    {
        return array_map([NotificationTarget::class,'view'],Store::rows('notifications','user_id=%d',[get_current_user_id()]));
    }
    public static function summary(): array
    {
        return ['unread_total'=>Store::count('notifications','user_id=%d AND read_at IS NULL',[get_current_user_id()])];
    }
    public static function feed(array $filter): array
    {
        $where='user_id=%d'; $args=[get_current_user_id()];
        if (($filter['filter']??'')==='unread') { $where.=' AND read_at IS NULL'; }
        $total=Store::count('notifications',$where,$args); $pages=max(1,(int)ceil($total/20));
        $page=min($pages,max(1,(int)($filter['page']??1))); $offset=($page-1)*20;
        $items=Store::rows('notifications',$where,$args,'ORDER BY id DESC LIMIT 20 OFFSET '.$offset);
        return ['items'=>array_map([NotificationTarget::class,'view'],$items),'total'=>$total,'pages'=>$pages,'page'=>$page]+self::summary();
    }
    public static function read(int $id): array
    {
        global $wpdb; $table=Store::table('notifications');
        $wpdb->query($wpdb->prepare("UPDATE $table SET read_at=%s WHERE id=%d AND user_id=%d AND read_at IS NULL",current_time('mysql',true),$id,get_current_user_id()));
        return ['ok'=>true]+self::summary();
    }
    public static function readAll(): array
    {
        global $wpdb; $table=Store::table('notifications');
        $wpdb->query($wpdb->prepare("UPDATE $table SET read_at=%s WHERE user_id=%d AND read_at IS NULL",current_time('mysql',true),get_current_user_id()));
        return ['ok'=>true]+self::summary();
    }
    public static function delete(int $id): array
    {
        $row=Store::one('notifications',$id);
        Access::require($row && (int)$row['user_id']===get_current_user_id(),'Notificación no encontrada.',404);
        Store::delete('notifications',['id'=>$id,'user_id'=>get_current_user_id()]);
        return ['ok'=>true,'deleted'=>$id]+self::summary();
    }
    public static function removeProfileNotices(int $user,array $kinds,int $profileId): int
    {
        $user=absint($user); $profileId=absint($profileId);
        $kinds=array_values(array_filter(array_map('sanitize_key',$kinds)));
        if (!$user || !$profileId || !$kinds) { return 0; }
        $removed=0;
        $placeholders=implode(',',array_fill(0,count($kinds),'%s'));
        foreach (Store::rows('notifications',"user_id=%d AND kind IN ($placeholders)",array_merge([$user],$kinds),'') as $row) {
            $context=json_decode($row['context']??'null',true);
            $matches=is_array($context) && ($context['type']??'')==='profile' && absint($context['id']??0)===$profileId;
            if (!$matches) {
                parse_str((string)wp_parse_url($row['url']??'',PHP_URL_QUERY),$query);
                $matches=absint($query['member']??0)===$profileId;
            }
            if ($matches) { Store::delete('notifications',['id'=>(int)$row['id'],'user_id'=>$user]); $removed++; }
        }
        return $removed;
    }
    public static function removeConversationRequestNotices(int $user,int $actor): int
    {
        $user=absint($user); $actor=absint($actor); if (!$user || !$actor) { return 0; }
        $removed=0;
        foreach (Store::rows('notifications',"user_id=%d AND kind='conversation_request'",[$user],'') as $row) {
            $context=json_decode($row['context']??'null',true);
            if (is_array($context) && absint($context['actor']??0)===$actor) {
                Store::delete('notifications',['id'=>(int)$row['id'],'user_id'=>$user]); $removed++;
            }
        }
        return $removed;
    }

    public static function removeConversationNotices(int $conversationId): int
    {
        $conversationId=absint($conversationId); if (!$conversationId) { return 0; }
        $removed=0;
        foreach (Store::rows('notifications',"kind IN ('message','conversation_group','conversation_request','conversation_accepted')",[],'') as $row) {
            $context=json_decode($row['context']??'null',true);
            if (is_array($context) && ($context['type']??'')==='conversation' && absint($context['id']??0)===$conversationId) {
                Store::delete('notifications',['id'=>(int)$row['id']]); $removed++;
            }
        }
        return $removed;
    }

    public static function open(int $id): array
    {
        $row=Store::one('notifications',$id);
        Access::require($row && (int)$row['user_id']===get_current_user_id(),'Notificación no encontrada.',404);
        $view=NotificationTarget::view($row); self::read($id);
        return ['url'=>$view['url'],'available'=>$view['available']];
    }
}

final class NotificationEmail
{
    private const MESSAGE_EMAIL_META='_ascla_message_email_last_sent';
    private const MESSAGE_EMAIL_WINDOW=900;
    private const KINDS=[
        'connection'=>'connections','connection_accepted'=>'connections',
        'conversation_request'=>'messages','conversation_accepted'=>'messages','conversation_group'=>'messages','message'=>'messages',
        'event'=>'events','event_waitlist_available'=>'events','event_cancelled'=>'events','event_updated'=>'events','microevent'=>'events',
        'support_received'=>'support','support_request'=>'support','support_update'=>'support',
    ];

    public static function send(int $user,string $kind,string $label,string $url,array $context): void
    {
        $category=self::KINDS[sanitize_key($kind)]??'';
        if ($category==='' || empty(Notifications::emailPreferences($user)[$category])) { return; }
        if (sanitize_key($kind)==='message' && !self::messageAllowed($user,$context)) { return; }
        try {
            \ASCLA\Core\Integrations\Mailer::notification($user,$category,self::copy($kind,$label,$context),$url);
        } catch (\Throwable) {
            // Email delivery is complementary: an SMTP failure must never cancel the in-app notification.
        }
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
            'event_updated'=>'Un evento relacionado contigo cambió información importante. Revisa los detalles actualizados en ASCLA.',
            'microevent'=>'Tienes una nueva invitación a un círculo ASCLA.',
            'support_received','support_request','support_update'=>'Hay una actualización de una solicitud en ASCLA. Ingresa para consultar sus detalles.',
            default=>Access::excerpt($fallback,255),
        };
    }

    private static function messageAllowed(int $user,array $context): bool
    {
        $conversation=(($context['type']??'')==='conversation')?absint($context['id']??0):0;
        $key=(string)$conversation;
        return (bool)Store::lock('message-email:'.$user.':'.$key,static fn()=>self::messageAllowedLocked($user,$key));
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

    private static function messageAllowedLocked(int $user,string $key): bool
    {
        $now=time();$history=self::recentMessageHistory($user,$now);$last=(int)($history[$key]??0);
        if ($last>0 && ($now-$last)<self::MESSAGE_EMAIL_WINDOW) {
            if (count($history)>50) { update_user_meta($user,self::MESSAGE_EMAIL_META,self::trimMessageHistory($history)); }
            return false;
        }
        $history[$key]=$now;
        update_user_meta($user,self::MESSAGE_EMAIL_META,self::trimMessageHistory($history));
        return true;
    }
}

