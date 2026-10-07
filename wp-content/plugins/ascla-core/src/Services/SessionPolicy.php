<?php
namespace ASCLA\Core\Services;

/** RN-051/RNF-005: polling never extends a session; activity is explicit. */
final class SessionPolicy
{
    public const IDLE_SECONDS=30*MINUTE_IN_SECONDS;
    private static bool $expired=false;

    public static function boot(): void
    {
        add_filter('determine_current_user',[self::class,'validateUser'],100);
        add_filter('rest_authentication_errors',[self::class,'authenticationError'],110);
    }

    public static function validateUser(mixed $userId): mixed
    {
        self::$expired=false;
        $id=(int)$userId;
        if (!$id || !user_can($id,'ascla_access')) { return $userId; }
        if (!Access::member($id)) {
            \WP_Session_Tokens::get_instance($id)->destroy_all();
            self::$expired=true;
            return 0;
        }
        $token=wp_get_session_token();
        // CLI/internal jobs and application-password authentication have no browser cookie.
        return $token==='' || self::validateToken($id,$token)?$userId:0;
    }

    public static function validateToken(int $userId,string $token): bool
    {
        $manager=\WP_Session_Tokens::get_instance($userId);
        $session=$manager->get($token);
        $last=(int)($session['ascla_activity']??$session['login']??0);
        if (!$session || !$last || time()-$last>=self::IDLE_SECONDS || !Access::member($userId)) {
            $manager->destroy($token);
            self::$expired=true;
            return false;
        }
        return true;
    }

    public static function authenticationError(mixed $error): mixed
    {
        return self::$expired?new \WP_Error('ascla_session_expired','La sesión expiró. Inicia sesión nuevamente.',['status'=>401]):$error;
    }

    public static function status(bool $renew=false): array
    {
        $id=get_current_user_id();$token=wp_get_session_token();
        Access::require($id>0 && $token!=='' && self::validateToken($id,$token),'La sesión expiró. Inicia sesión nuevamente.',401);
        $manager=\WP_Session_Tokens::get_instance($id);
        $session=$manager->get($token);
        if ($renew) {
            $session['ascla_activity']=time();
            $manager->update($token,$session);
        }
        $last=(int)($session['ascla_activity']??$session['login']);
        return ['remaining'=>max(0,min($last+self::IDLE_SECONDS,(int)$session['expiration'])-time()),'idle_seconds'=>self::IDLE_SECONDS];
    }

}
