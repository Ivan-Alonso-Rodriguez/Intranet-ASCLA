<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Notifications
{
    private const EMAIL_META='_ascla_email_notifications';
    private const EMAIL_DEFAULTS=['connections'=>true,'messages'=>true,'events'=>true];
    private const EMAIL_KINDS=[
        'connection'=>'connections',
        'connection_accepted'=>'connections',
        'conversation_request'=>'messages',
        'conversation_accepted'=>'messages',
        'conversation_group'=>'messages',
        'message'=>'messages',
        'event'=>'events',
        'event_waitlist_available'=>'events',
        'microevent'=>'events',
    ];

    public static function emailPreferences(int $user=0): array
    {
        $user=$user?:get_current_user_id();
        if (!$user || !Access::member($user)) return self::EMAIL_DEFAULTS;
        $stored=get_user_meta($user,self::EMAIL_META,true);
        $stored=is_array($stored)?$stored:[];
        $preferences=self::EMAIL_DEFAULTS;
        foreach (array_keys(self::EMAIL_DEFAULTS) as $key) {
            if (array_key_exists($key,$stored)) $preferences[$key]=(bool)$stored[$key];
        }
        return $preferences;
    }

    public static function saveEmailPreferences(int $user,array $input): array
    {
        Access::require($user>0 && Access::member($user),'Usuario no válido.',400);
        $preferences=self::emailPreferences($user);
        foreach (array_keys(self::EMAIL_DEFAULTS) as $key) {
            if (array_key_exists($key,$input)) $preferences[$key]=rest_sanitize_boolean($input[$key]);
        }
        update_user_meta($user,self::EMAIL_META,$preferences);
        return $preferences;
    }

    private static function emailCategory(string $kind): string
    {
        return self::EMAIL_KINDS[sanitize_key($kind)]??'';
    }

    private static function emailCopy(string $kind,string $fallback,array $context): string
    {
        $actor=absint($context['actor']??0);
        $name=$actor && Access::member($actor)?Profiles::publicName($actor):'Un asociado';
        return match (sanitize_key($kind)) {
            'connection'=>$name.' quiere conectar contigo en ASCLA.',
            'connection_accepted'=>$name.' aceptó tu solicitud de conexión en ASCLA.',
            'conversation_request'=>$name.' te envió una solicitud de conversación en ASCLA.',
            'conversation_accepted'=>$name.' aceptó tu solicitud de conversación en ASCLA.',
            'conversation_group'=>$name.' te añadió a una conversación grupal en ASCLA.',
            'message'=>'Tienes un nuevo mensaje en ASCLA.',
            'event'=>'Tienes una nueva invitación a un evento de ASCLA.',
            'event_waitlist_available'=>'Se liberó un cupo para ti en un evento de ASCLA. Entra para confirmar tu asistencia.',
            'microevent'=>'Tienes una nueva invitación a un círculo ASCLA.',
            default=>Access::excerpt($fallback,255),
        };
    }

    private static function email(int $user,string $kind,string $label,string $url,array $context): void
    {
        $category=self::emailCategory($kind);
        if ($category==='' || empty(self::emailPreferences($user)[$category])) return;
        try {
            \ASCLA\Core\Integrations\Mailer::notification($user,$category,self::emailCopy($kind,$label,$context),$url);
        } catch (\Throwable $error) {
            // Email delivery is complementary: an SMTP failure must never cancel the in-app notification.
        }
    }

    private static function data(int $user,string $kind,string $label,string $url,array $context): array
    {
        $safe=[];
        if (in_array($context['type']??'', ['post','profile','conversation','job'],true)) {
            $safe=['type'=>$context['type'],'id'=>absint($context['id']??0),'actor'=>absint($context['actor']??0)];
        }
        return ['user_id'=>$user,'kind'=>sanitize_key($kind),'label'=>Access::excerpt($label,255),'url'=>esc_url_raw($url),'context'=>wp_json_encode($safe),'created_at'=>current_time('mysql',true)];
    }
    public static function send(int $user,string $kind,string $label,string $url='',array $context=[]): void
    {
        if ($user && Access::member($user)) {
            Store::insert('notifications',self::data($user,$kind,$label,$url,$context));
            self::email($user,$kind,$label,$url,$context);
        }
    }
    public static function once(int $user,string $key,string $kind,string $label,string $url='',array $context=[]): bool
    {
        if (!$user || !Access::member($user)) { return false; }
        $key=substr($key,0,96);
        return Store::lock('notice:'.$user.':'.$key,static function () use($user,$key,$kind,$label,$url,$context) {
            if (Store::count('notifications','user_id=%d AND event_key=%s',[$user,$key])) { return false; }
            Store::insert('notifications',self::data($user,$kind,$label,$url,$context)+['event_key'=>$key]);
            self::email($user,$kind,$label,$url,$context);
            return true;
        });
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
        if (!$user || !$profileId || !$kinds) return 0;
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
        $user=absint($user); $actor=absint($actor); if (!$user || !$actor) return 0;
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
        $conversationId=absint($conversationId); if (!$conversationId) return 0;
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
