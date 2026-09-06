<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
final class Notifications
{
    public static function send(int $user,string $kind,string $label,string $url=''): void
    {
        if (!$user || !Access::member($user)) { return; }
        Store::insert('notifications',['user_id'=>$user,'kind'=>sanitize_key($kind),'label'=>sanitize_text_field($label),'url'=>esc_url_raw($url),'created_at'=>current_time('mysql',true)]);
    }
    public static function once(int $user,string $key,string $kind,string $label,string $url=''): bool
    {
        if (!$user || !Access::member($user)) { return false; }
        return Store::lock('notice:'.$user.':'.$key,static function () use($user,$key,$kind,$label,$url) {
            if (Store::count('notifications','user_id=%d AND event_key=%s',[$user,$key])) { return false; }
            Store::insert('notifications',['user_id'=>$user,'event_key'=>substr($key,0,96),'kind'=>sanitize_key($kind),'label'=>sanitize_text_field($label),'url'=>esc_url_raw($url),'created_at'=>current_time('mysql',true)]);
            return true;
        });
    }
    public static function list(): array { return Store::rows('notifications','user_id=%d',[get_current_user_id()]); }
    public static function read(int $id): array
    {
        Store::update('notifications',['read_at'=>current_time('mysql',true)],['id'=>$id,'user_id'=>get_current_user_id()]); return ['ok'=>true];
    }
}
