<?php
namespace ASCLA\Core\Services;

/** Review decisions belong to the member and persist across browsers and sessions. */
final class ProfileReview
{
    public const DEFAULTS=['profile_review_days'=>180,'profile_snooze_days'=>7,'directory_page_size'=>15];
    public static function settings(array &$data,array $input): void
    {
        foreach (['profile_review_days'=>[1,730],'profile_snooze_days'=>[1,30],'directory_page_size'=>[5,100]] as $key=>$range) {
            if (!array_key_exists($key,$input)) { continue; }
            Access::require(filter_var($input[$key],FILTER_VALIDATE_INT)!==false && $input[$key]>=$range[0] && $input[$key]<=$range[1],'Parámetro de perfiles fuera del rango permitido.',400);
            $data[$key]=(int)$input[$key];
        }
    }
    public static function status(int $id): array
    {
        $settings=Settings::get();$incomplete=(bool)ProfileRequirements::missing(Profiles::raw($id));
        $state=(array)(get_user_meta($id,'_ascla_profile_review',true)?:[]);
        $checked=(int)($state['confirmed_at']??0);$snoozed=(int)($state['snoozed_until']??0);
        $baseline=$checked?:strtotime(get_userdata($id)->user_registered.' UTC');
        $next=max($snoozed,$incomplete?0:$baseline+$settings['profile_review_days']*DAY_IN_SECONDS);
        return ['due'=>$next<=time(),'incomplete'=>$incomplete,'confirmed_at'=>$checked,'next_at'=>$next,'snooze_days'=>$settings['profile_snooze_days']];
    }
    public static function respond(string $decision): array
    {
        $id=get_current_user_id();Access::require(Access::member($id));
        Access::require(in_array($decision,['confirm','snooze'],true),'Revisión de perfil no válida.',400);
        if ($decision==='confirm') {
            Access::require(!ProfileRequirements::missing(Profiles::raw($id)),'Completa los campos mínimos antes de confirmar la vigencia.',400);
            self::confirmed($id);
        } else {
            $state=(array)(get_user_meta($id,'_ascla_profile_review',true)?:[]);
            $state['snoozed_until']=time()+Settings::get()['profile_snooze_days']*DAY_IN_SECONDS;
            update_user_meta($id,'_ascla_profile_review',$state);
        }
        return self::status($id);
    }
    public static function confirmed(int $id): void
    {
        update_user_meta($id,'_ascla_profile_review',['confirmed_at'=>time(),'snoozed_until'=>0]);
    }
}
