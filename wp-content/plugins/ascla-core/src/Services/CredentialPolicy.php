<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Frontend\Language;

/** Institutional credentials policy, applied to ASCLA and native WordPress forms. */
final class CredentialPolicy
{
    public const DEFAULTS=['password_min_length'=>8,'password_reset_minutes'=>60,'remember_days'=>14,'login_challenge_after'=>3,'login_lock_after'=>5,'login_ip_lock_after'=>20,'login_lock_minutes'=>15];
    private const COMMON=['password','password1','password123','contrasena','12345678','123456789','1234567890','87654321','qwerty123','qwertyuiop','abcdefgh','admin123','letmein123'];

    public static function boot(): void
    {
        add_filter('password_reset_expiration',static fn()=>Settings::get()['password_reset_minutes']*MINUTE_IN_SECONDS);
        add_filter('auth_cookie_expiration',[self::class,'cookieExpiration'],10,3);
        add_action('validate_password_reset',[self::class,'resetValidation'],10,2);
        add_action('user_profile_update_errors',[self::class,'profileValidation'],10,3);
        add_filter('rest_pre_insert_user',[self::class,'restValidation'],10,2);
        add_filter('rest_request_before_callbacks',[NativeAccountPolicy::class,'validate'],10,3);
        add_filter('password_hint',[self::class,'hint']);
        add_action('login_enqueue_scripts',[self::class,'resetAssets']);
    }
    public static function upgrade(): void
    {
        if ((int)get_option('ascla_password_policy_version',0)>=1) { return; }
        $stored=(array)get_option('ascla_settings',[]);
        // Replace the old default once; preserve deliberately configured lengths.
        if ((int)($stored['password_min_length']??0)===12) {
            $stored['password_min_length']=self::DEFAULTS['password_min_length'];
            update_option('ascla_settings',$stored,false);
        }
        update_option('ascla_password_policy_version',1,false);
    }
    public static function hint(): string
    {
        return sprintf(Language::text('Usa al menos %d caracteres. No es obligatorio combinar mayúsculas, números y símbolos. Evita contraseñas muy comunes.','Use at least %d characters. Uppercase letters, numbers and symbols are optional. Avoid very common passwords.'),Settings::get()['password_min_length']);
    }
    public static function resetAssets(): void
    {
        if (!in_array($GLOBALS['action']??'',['rp','resetpass'],true)) { return; }
        wp_dequeue_script('user-profile');
        wp_enqueue_script('ascla-password-policy',ASCLA_URL.'assets/password-policy.js',['jquery'],\ASCLA\Core\Frontend\App::assetVersion('assets/password-policy.js'),true);
        wp_localize_script('ascla-password-policy','ASCLA_PASSWORD_POLICY',[
            'minimum'=>Settings::get()['password_min_length'],'common'=>self::COMMON,
            'valid'=>Language::text('Cumple los requisitos','Meets the requirements'),
            'invalid'=>Language::text('Revisa los requisitos de la contraseña','Check the password requirements'),
            'mismatch'=>Language::text('Las nuevas contraseñas no coinciden.','The new passwords do not match.'),
            'show'=>Language::text('Mostrar contraseña','Show password'),
            'hide'=>Language::text('Ocultar contraseña','Hide password'),
        ]);
    }
    public static function settings(array &$data,array $input): void
    {
        foreach (['password_min_length','password_reset_minutes','remember_days','login_challenge_after','login_lock_after','login_ip_lock_after','login_lock_minutes'] as $key) {
            if (!array_key_exists($key,$input)) { continue; }
            $data[$key]=FormRules::integer($key,$input[$key],'Parámetro de seguridad fuera del rango permitido.');
        }
        Access::require($data['login_challenge_after']<$data['login_lock_after'],'El captcha debe activarse antes del bloqueo.',400);
    }
    public static function username(mixed $input,int $id): string
    {
        $login=trim(Access::text($input,60));
        Access::require($login!=='' && sanitize_user($login,true)===$login && validate_username($login) && !preg_match('/\s/u',$login),'Use un nombre de usuario válido, sin espacios ni caracteres especiales no permitidos.',400);
        $existing=username_exists($login);
        Access::require(!$existing || (int)$existing===$id,'Ese nombre de usuario ya está registrado.',409);
        return $login;
    }

    public static function error(string $password,object $user): string
    {
        $minimum=Settings::get()['password_min_length'];
        if (strlen($password)>4096 || mb_strlen(trim($password))<$minimum) {
            return 'La nueva contraseña debe tener al menos '.$minimum.' caracteres y como máximo 4096 bytes.';
        }
        if (preg_match('/[\x00-\x1f\x7f]/u',$password)) {
            return Language::text('La contraseña no debe contener caracteres de control.','The password must not contain control characters.');
        }
        $normalized=mb_strtolower(remove_accents(trim($password)));
        if (in_array($normalized,self::COMMON,true) || preg_match('/^(.)\1+$/u',$normalized)) {
            return Language::text('Esta contraseña es muy común. Elige otra menos predecible.','This password is very common. Choose a less predictable one.');
        }
        return '';
    }
    public static function resetValidation(\WP_Error $errors,mixed $user): void
    {
        if (!$user instanceof \WP_User || empty($_POST['pass1'])) { return; }
        $error=self::error((string)wp_unslash($_POST['pass1']),$user);
        if ($error!=='') { $errors->add('ascla_password_policy',$error); }
    }
    public static function profileValidation(\WP_Error $errors,bool $update,object $user): void
    {
        unset($update); // WordPress callback signature includes this argument.
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
