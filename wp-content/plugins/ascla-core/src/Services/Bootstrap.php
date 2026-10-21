<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Integrations\Secrets;

/** The same authorized startup payload serves HTML and REST; it is never shared between users. */
final class Bootstrap
{
    public static function get(): array
    {
        Access::require(Access::member(),'Inicie sesión con una cuenta autorizada.',is_user_logged_in()?403:401);
        $user=get_current_user_id();$settings=Settings::get();
        return [
            'me'=>Profiles::visible($user),
            'profile_completion'=>Profiles::completion($user),
            'profile_review'=>ProfileReview::forClient(ProfileReview::status($user)),
            'can_create'=>array_combine(array_keys(Catalog::TYPES),array_map([Content::class,'canCreate'],array_keys(Catalog::TYPES))),
            'birthday'=>Birthdays::info($user),
            'member_types'=>MemberTypes::OPTIONS,
            'catalogs'=>Profiles::catalogs(),
            'support_categories'=>SupportCategories::all(),
            'admin_area'=>current_user_can('ascla_admin_area'),
            'moderator'=>current_user_can('ascla_moderate'),
            'executive'=>current_user_can('ascla_publish'),
            'admin'=>current_user_can('ascla_manage'),
            'demo'=>$settings['demo'],
            'ai_mode'=>Knowledge::provider()->mode(),
            'google_connected'=>Secrets::get('google_calendar_'.$user)!=='',
            'password_min_length'=>$settings['password_min_length'],
            'notification_cursor'=>Notifications::latestId(),
        ];
    }
}
