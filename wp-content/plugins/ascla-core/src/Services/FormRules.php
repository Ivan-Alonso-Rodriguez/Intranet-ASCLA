<?php
namespace ASCLA\Core\Services;

/** Bounds shared by the form controls and domain writes, including forced REST calls. */
final class FormRules
{
    public const INTEGERS=[
        'capacity'=>[0,100000],'offer_hours'=>[1,168],'duration_seconds'=>[0,604800],
        'matching_min_affinity'=>[0,100],'matching_max_suggestions'=>[1,5],'matching_weight'=>[0,100],
        'micro_min'=>[2,50],'micro_capacity'=>[2,100],'micro_limit'=>[1,4],'micro_priority_hours'=>[0,168],'micro_interval_days'=>[7,90],'priority_hours'=>[0,168],
        'password_min_length'=>[12,128],'password_reset_minutes'=>[5,1440],'remember_days'=>[1,90],
        'login_challenge_after'=>[1,10],'login_lock_after'=>[2,20],'login_ip_lock_after'=>[2,100],'login_lock_minutes'=>[15,60],
        'profile_review_days'=>[1,730],'profile_snooze_days'=>[1,30],'directory_page_size'=>[5,100],
        'reminder_hours'=>[1,720],
    ];
    public const TEXT=['url'=>2000,'youtube_url'=>2000,'agenda'=>10000,'location'=>10000,'source'=>10000,'copyright'=>10000,'benefits'=>10000,'initiatives'=>10000,'summary'=>10000,'transcript'=>100000,'identities'=>10000,'contact_name'=>200,'contact_email'=>100,'contact_phone'=>60,'conditions'=>10000];
    public const MAIL_TEXT=['smtp_host'=>253,'smtp_user'=>254,'smtp_from'=>254,'smtp_name'=>254,'smtp_password'=>2000];

    public static function integer(string $field,mixed $value,string $message='Número entero fuera del rango permitido.'): int
    {
        [$min,$max]=self::INTEGERS[$field];
        return self::bounded($value,$min,$max,$message);
    }
    public static function bounded(mixed $value,int $min,int $max,string $message='Número no válido.'): int
    {
        Access::require((is_int($value)||is_string($value)) && filter_var($value,FILTER_VALIDATE_INT)!==false && (int)$value>=$min && (int)$value<=$max,$message,400);
        return (int)$value;
    }
    public static function ids(mixed $values): array
    {
        Access::require(is_array($values)&&array_is_list($values),'Selección no válida.',400);
        return array_values(array_unique(array_map(static fn($value)=>self::bounded($value,1,PHP_INT_MAX,'Selección no válida.'),$values)));
    }
    public static function boolean(mixed $value): bool
    {
        Access::require(in_array($value,[true,false,0,1,'0','1','true','false'],true),'Selección no válida.',400);
        return rest_sanitize_boolean($value);
    }
}
