<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Notifications
{
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
        if ($user && Access::member($user)) { Store::insert('notifications',self::data($user,$kind,$label,$url,$context)); }
    }
    public static function once(int $user,string $key,string $kind,string $label,string $url='',array $context=[]): bool
    {
        if (!$user || !Access::member($user)) { return false; }
        $key=substr($key,0,96);
        return Store::lock('notice:'.$user.':'.$key,static function () use($user,$key,$kind,$label,$url,$context) {
            if (Store::count('notifications','user_id=%d AND event_key=%s',[$user,$key])) { return false; }
            Store::insert('notifications',self::data($user,$kind,$label,$url,$context)+['event_key'=>$key]); return true;
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
    public static function open(int $id): array
    {
        $row=Store::one('notifications',$id);
        Access::require($row && (int)$row['user_id']===get_current_user_id(),'Notificación no encontrada.',404);
        $view=NotificationTarget::view($row); self::read($id);
        return ['url'=>$view['url'],'available'=>$view['available']];
    }
}
