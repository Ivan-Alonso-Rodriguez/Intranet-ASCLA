<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Integrations\Mailer;
use ASCLA\Core\Repositories\Store;

/** Birthday privacy + once-per-day delivery. Birth dates never become public profile data. */
final class Birthdays
{
    private const LAST_RUN_OPTION='ascla_birthdays_last_run';
    private const MAIL_YEAR_META='_ascla_birthday_mail_year';

    public static function normalize(mixed $value): string
    {
        $value=trim(Access::text($value,10));
        if ($value==='') return '';
        Access::require((bool)preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$value),'Fecha de nacimiento no válida.',400);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());
        $errors=\DateTimeImmutable::getLastErrors();
        $valid=$date instanceof \DateTimeImmutable && ($errors===false || ((int)$errors['warning_count']===0 && (int)$errors['error_count']===0)) && $date->format('Y-m-d')===$value;
        Access::require($valid,'Fecha de nacimiento no válida.',400);
        $today=current_datetime()->setTime(0,0,0);
        $minimum=new \DateTimeImmutable('1900-01-01',wp_timezone());
        Access::require($date>=$minimum && $date<=$today,'La fecha de nacimiento debe ser una fecha real y no puede estar en el futuro.',400);
        return $value;
    }

    public static function isToday(string $birthDate,?\DateTimeInterface $now=null): bool
    {
        if (!preg_match('/^\\d{4}-(\\d{2})-(\\d{2})$/',$birthDate,$match)) return false;
        $now=$now?:current_datetime();
        return $match[1].'-'.$match[2]===$now->format('m-d');
    }

    public static function info(int $user=0): array
    {
        $user=$user?:get_current_user_id();
        if (!$user || !Access::member($user)) return ['today'=>false];
        $profile=(array)get_user_meta($user,'_ascla_profile',true);
        $birth=(string)($profile['birth_date']??'');
        if (!self::isToday($birth)) return ['today'=>false];
        $account=get_userdata($user);
        $first=trim((string)($profile['first_name']??$account?->first_name??''));
        if ($first==='') $first=trim((string)($account?->display_name??'Asociado'));
        return [
            'today'=>true,
            'date'=>current_datetime()->format('Y-m-d'),
            'first_name'=>$first,
            'message'=>'¡Feliz cumpleaños! Todo ASCLA te desea un gran día y un nuevo año lleno de buenas conexiones, aprendizajes y proyectos.',
        ];
    }

    /** Run cheaply on init; the guarded work executes only once per WordPress-local calendar day. */
    public static function maybeProcess(): void
    {
        if (wp_installing() || wp_doing_ajax()) return;
        $today=current_datetime()->format('Y-m-d');
        if ((string)get_option(self::LAST_RUN_OPTION,'')===$today) return;
        try {
            Store::lock('birthdays:'.$today,static function()use($today){
                if ((string)get_option(self::LAST_RUN_OPTION,'')===$today) return;
                foreach (get_users(['capability'=>'ascla_access','fields'=>'ID']) as $id) {
                    try { self::celebrate((int)$id); } catch (\Throwable $error) { /* Birthday delivery must never interrupt the site. */ }
                }
                update_option(self::LAST_RUN_OPTION,$today,false);
            });
        } catch (\Throwable $error) { /* Another request may already be processing birthdays. */ }
    }

    /** Safe to call after a profile/admin update so a birthday saved today is not missed. */
    public static function celebrate(int $user): void
    {
        if (!$user || !Access::member($user)) return;
        $profile=(array)get_user_meta($user,'_ascla_profile',true);
        if (!self::isToday((string)($profile['birth_date']??''))) return;
        $year=current_datetime()->format('Y');
        if ((string)get_user_meta($user,self::MAIL_YEAR_META,true)!==$year) {
            $sent=false;
            try { $sent=Mailer::birthday($user); } catch (\Throwable $error) { $sent=false; }
            if ($sent) update_user_meta($user,self::MAIL_YEAR_META,$year);
        }
        $account=get_userdata($user);
        if (!$account) return;
        $name=trim((string)$account->display_name)?:'Un asociado';
        $url=Catalog::url('perfil',['member'=>$user]);
        foreach (get_users(['capability'=>'ascla_manage','fields'=>'ID']) as $admin) {
            $admin=(int)$admin;
            if ($admin===$user || !Access::member($admin)) continue;
            Notifications::once(
                $admin,
                'birthday:'.$year.':'.$user,
                'birthday',
                '🎉 Hoy cumple años '.$name.'. Puedes enviarle un saludo desde ASCLA.',
                $url,
                ['type'=>'profile','id'=>$user]
            );
        }
    }
}
