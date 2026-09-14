<?php
namespace ASCLA\Core\Services;

final class Account
{
    public static function changePassword(array $input): array
    {
        Access::limit('password_change',5,300);
        $user=wp_get_current_user();
        Access::require($user->exists(),'Sesión no válida.',401);

        $current=(string)($input['current_password']??'');
        $password=(string)($input['new_password']??'');
        $confirm=(string)($input['confirm_password']??'');

        Access::require($current!=='' && $password!=='' && $confirm!=='','Complete los tres campos de contraseña.',400);
        Access::require(strlen($current)<=4096 && strlen($password)<=4096 && strlen($confirm)<=4096,'Contraseña no válida.',400);
        Access::require(wp_check_password($current,$user->user_pass,$user->ID),'La contraseña actual no es correcta.',400);
        Access::require($password===$confirm,'Las nuevas contraseñas no coinciden.',400);
        Access::require(strlen($password)>=12,'La nueva contraseña debe tener al menos 12 caracteres.',400);
        Access::require(!wp_check_password($password,$user->user_pass,$user->ID),'La nueva contraseña debe ser diferente de la actual.',400);

        wp_set_password($password,$user->ID);
        // wp_set_password changes the hash used by authentication cookies. Issue a fresh
        // cookie so a successful in-intranet password change does not unexpectedly log out.
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID,true,is_ssl());
        Audit::record('password_changed',$user->ID);

        return ['ok'=>true,'message'=>'Contraseña actualizada correctamente.'];
    }

    public static function sendPasswordReset(): array
    {
        Access::limit('password_reset_mail',2,300);
        $user=wp_get_current_user();
        Access::require($user->exists() && is_email($user->user_email),'Tu cuenta necesita un correo electrónico válido.',400);

        $result=retrieve_password($user->user_login);
        if (is_wp_error($result)) {
            throw new \ASCLA\Core\Rest\ApiException('No se pudo enviar el enlace de recuperación. Revisa la configuración de correo o inténtalo nuevamente.',502);
        }
        Audit::record('password_reset_requested',$user->ID);
        return ['ok'=>true,'message'=>'Enviamos un enlace de recuperación a tu correo registrado. Revisa también la carpeta de spam.'];
    }
}
