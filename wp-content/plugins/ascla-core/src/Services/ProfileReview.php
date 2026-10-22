<?php
namespace ASCLA\Core\Services;

/** Review decisions belong to the member and persist across browsers and sessions. */
final class ProfileReview
{
    public const DEFAULTS=['profile_review_days'=>180,'profile_snooze_days'=>7,'directory_page_size'=>ProfileDirectory::PAGE_SIZE];
    public static function settings(array &$data,array $input): void
    {
        foreach (['profile_review_days','profile_snooze_days','directory_page_size'] as $key) {
            if (!array_key_exists($key,$input)) { continue; }
            $data[$key]=FormRules::integer($key,$input[$key],'Parámetro de perfiles fuera del rango permitido.');
        }
    }
    public static function status(int $id): array
    {
        $settings=Settings::get();$incomplete=(bool)ProfileRequirements::completion(Profiles::raw($id))['missing'];
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
            Access::require(!ProfileRequirements::completion(Profiles::raw($id))['missing'],'Completa los criterios del perfil antes de confirmar la vigencia.',400);
            self::confirmed($id);
        } else {
            $state=(array)(get_user_meta($id,'_ascla_profile_review',true)?:[]);
            $state['snoozed_until']=time()+Settings::get()['profile_snooze_days']*DAY_IN_SECONDS;
            \ASCLA\Core\Repositories\WordPressWrites::meta('user',$id,'_ascla_profile_review',$state);
        }
        return self::status($id);
    }
    /** The browser needs decisions and reminder settings, not precise internal review dates. */
    public static function forClient(array $state): array
    {
        return array_intersect_key($state,array_flip(['due','incomplete','snooze_days']));
    }
    public static function confirmed(int $id): void
    {
        \ASCLA\Core\Repositories\WordPressWrites::meta('user',$id,'_ascla_profile_review',['confirmed_at'=>time(),'snoozed_until'=>0]);
    }
}
