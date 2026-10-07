<?php
namespace ASCLA\Core\Services;

/** Institutional credentials policy, applied to ASCLA and native WordPress forms. */
final class CredentialPolicy
{
    public const DEFAULTS=['password_min_length'=>12,'password_reset_minutes'=>60,'remember_days'=>14,'login_challenge_after'=>3,'login_lock_after'=>5,'login_ip_lock_after'=>20,'login_lock_minutes'=>15];

    public static function boot(): void
    {
        add_filter('password_reset_expiration',static fn()=>Settings::get()['password_reset_minutes']*MINUTE_IN_SECONDS);
        add_filter('auth_cookie_expiration',[self::class,'cookieExpiration'],10,3);
        add_action('validate_password_reset',[self::class,'resetValidation'],10,2);
        add_action('user_profile_update_errors',[self::class,'profileValidation'],10,3);
        add_filter('rest_pre_insert_user',[self::class,'restValidation'],10,2);
    }
    public static function settings(array &$data,array $input): void
    {
        foreach (['password_min_length'=>[12,128],'password_reset_minutes'=>[5,1440],'remember_days'=>[1,90],'login_challenge_after'=>[1,10],'login_lock_after'=>[2,20],'login_ip_lock_after'=>[2,100],'login_lock_minutes'=>[15,60]] as $key=>$range) {
            if (!array_key_exists($key,$input)) { continue; }
            Access::require(filter_var($input[$key],FILTER_VALIDATE_INT)!==false && $input[$key]>=$range[0] && $input[$key]<=$range[1],'Parámetro de seguridad fuera del rango permitido.',400);
            $data[$key]=(int)$input[$key];
        }
        Access::require($data['login_challenge_after']<$data['login_lock_after'],'El captcha debe activarse antes del bloqueo.',400);
    }
    public static function error(string $password,object $user): string
    {
        $minimum=Settings::get()['password_min_length'];
        if (strlen($password)>4096 || mb_strlen($password)<$minimum) { return 'La nueva contraseña debe tener al menos '.$minimum.' caracteres y como máximo 4096 bytes.'; }
        foreach (['/\p{Lu}/u','/\p{Ll}/u','/\p{N}/u','/[^\p{L}\p{N}\s]/u'] as $pattern) {
            if (!preg_match($pattern,$password)) { return 'Combina mayúsculas, minúsculas, números y símbolos.'; }
        }
        $normalized=mb_strtolower(remove_accents($password));
        if (preg_match('/(.)\1{3}/u',$normalized) || self::sequence($normalized)) { return 'Evita secuencias predecibles y caracteres repetidos.'; }
        $personal=implode(' ',[(string)($user->user_login??''),explode('@',(string)($user->user_email??''))[0],(string)($user->first_name??''),(string)($user->last_name??'')]);
        foreach (preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower(remove_accents($personal)))?:[] as $token) {
            if (mb_strlen($token)>=3 && str_contains($normalized,$token)) { return 'La contraseña no debe contener tu nombre, usuario ni correo.'; }
        }
        return '';
    }
    private static function sequence(string $password): bool
    {
        foreach (['0123456789','abcdefghijklmnopqrstuvwxyz','qwertyuiop','asdfghjkl','zxcvbnm'] as $sequence) {
            for ($i=0;$i<=strlen($sequence)-4;$i++) {
                $part=substr($sequence,$i,4);
                if (str_contains($password,$part) || str_contains($password,strrev($part))) { return true; }
            }
        }
        return false;
    }
    public static function resetValidation(\WP_Error $errors,mixed $user): void
    {
        if (!$user instanceof \WP_User || empty($_POST['pass1'])) { return; }
        $error=self::error((string)wp_unslash($_POST['pass1']),$user);
        if ($error!=='') { $errors->add('ascla_password_policy',$error); }
    }
    public static function profileValidation(\WP_Error $errors,bool $update,object $user): void
    {
        if (empty($user->user_pass)) { return; }
        $error=self::error((string)$user->user_pass,$user);
        if ($error!=='') { $errors->add('ascla_password_policy',$error); }
    }
    public static function restValidation(mixed $user,\WP_REST_Request $request): mixed
    {
        if (is_wp_error($user) || !$request->get_param('password')) { return $user; }
        $existing=$request['id']?get_userdata((int)$request['id']):false;
        $identity=(object)array_merge($existing?array_merge((array)$existing->data,['first_name'=>$existing->first_name,'last_name'=>$existing->last_name]):[],(array)$user);
        $error=self::error((string)$request['password'],$identity);
        return $error!==''?new \WP_Error('ascla_password_policy',$error,['status'=>400]):$user;
    }
    public static function cookieExpiration(int $duration,int $user,bool $remember): int
    {
        return $remember && user_can($user,'ascla_access')?Settings::get()['remember_days']*DAY_IN_SECONDS:$duration;
    }
}
