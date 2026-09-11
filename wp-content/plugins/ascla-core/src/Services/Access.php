<?php
namespace ASCLA\Core\Services;
final class Access
{
    public static function member(int $id=0): bool { return user_can($id?:get_current_user_id(),'ascla_access') && !get_user_meta($id?:get_current_user_id(),'_ascla_suspended',true); }
    public static function require(bool $condition,string $message='Acceso no autorizado.',int $code=403): void
    {
        if (!$condition) { throw new \ASCLA\Core\Rest\ApiException($message,$code); }
    }
    public static function limit(string $bucket,int $max=30,int $seconds=60): void
    {
        $key='rate:'.get_current_user_id().':'.$bucket.':'.intdiv(time(),$seconds);
        \ASCLA\Core\Repositories\Store::lock($key,static function () use ($key,$max,$seconds) {
            $cache='ascla_'.hash('sha256',$key); $count=(int)get_transient($cache);
            self::require($count<$max,'Demasiadas solicitudes. Espere un momento.',429);
            set_transient($cache,$count+1,$seconds);
        });
    }
    public static function excerpt(mixed $value,int $max=180): string
    {
        $text=is_scalar($value)?sanitize_textarea_field((string)$value):'';
        return mb_strlen($text)>$max?mb_substr($text,0,max(0,$max-1)).'…':$text;
    }
    public static function text(mixed $value,int $max=2000): string
    {
        self::require(is_scalar($value)||$value===null,'Texto no válido.',400);
        $value=sanitize_textarea_field((string)$value);
        self::require(mb_strlen($value)<=$max,'El texto supera el límite de '.$max.' caracteres.',400);
        return $value;
    }
}
