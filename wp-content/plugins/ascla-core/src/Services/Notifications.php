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
    public static function list(): array { return Store::rows('notifications','user_id=%d',[get_current_user_id()]); }
    public static function read(int $id): array
    {
        Store::update('notifications',['read_at'=>current_time('mysql',true)],['id'=>$id,'user_id'=>get_current_user_id()]); return ['ok'=>true];
    }
}
