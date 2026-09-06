<?php
namespace ASCLA\Core\Frontend;

use ASCLA\Core\Domain\Catalog;

/** Brand the native authentication flow without replacing its security controls. */
final class Login
{
    public static function boot(): void
    {
        add_action('login_init', static function () { add_filter('gettext', [self::class, 'translate'], 10, 3); add_filter('language_attributes', static fn($attributes) => str_replace('lang="en-US"', 'lang="es"', $attributes)); });
        add_action('login_enqueue_scripts', static function () {
            wp_enqueue_style('ascla-login', ASCLA_URL . 'assets/login.css', ['login'], ASCLA_VERSION);
        });
        add_filter('login_body_class', static fn($classes) => array_merge($classes, ['ascla-login']));
        add_filter('login_site_html_link', static fn() => '<a href="' . esc_url(Catalog::url('intranet')) . '">&larr; Volver a ASCLA</a>');
        add_filter('login_remember_me_help_text', static fn() => 'Mantiene tu sesión durante más tiempo. Usa esta opción solo en tus dispositivos personales.');
        add_filter('login_headerurl', static fn() => Catalog::url('intranet'));
        add_filter('login_headertext', static fn() => 'ASCLA · Comunidad profesional');
        add_filter('login_title', static fn($title, $screen) => esc_html($screen . ' · ASCLA'), 10, 2);
        add_action('login_header', static function () { require ASCLA_PATH . 'templates/login-welcome.php'; });
        add_filter('login_message', [self::class, 'welcome']);
        add_action('login_footer', static function () {
            echo '<p class="ascla-login-help">¿Aún no tienes una cuenta? Solicita tu acceso a la administración de ASCLA.</p>';
        });
        add_filter('login_redirect', [self::class, 'redirect'], 10, 3);
    }

    /** Spanish UI copy is scoped to wp-login.php; native form handling stays with WordPress. */
    public static function translate(string $translation, string $text, string $domain): string
    {
        if ($domain !== 'default') { return $translation; }
        static $labels = [
            'Log In' => 'Entrar a la comunidad',
            'Log in' => 'Volver al inicio de sesión',
            'Username or Email Address' => 'Usuario o correo electrónico',
            'Username' => 'Usuario',
            'Password' => 'Contraseña',
            'Remember Me' => 'Recordarme',
            'Lost your password?' => '¿Olvidaste tu contraseña?',
            'Lost Password' => 'Recuperar contraseña',
            'Get New Password' => 'Enviar enlace de recuperación',
            'Show password' => 'Mostrar contraseña',
            'Hide password' => 'Ocultar contraseña',
            '&larr; Go to %s' => '&larr; Volver a %s',
            'Reset Password' => 'Restablecer contraseña',
            'New password' => 'Nueva contraseña',
            'Confirm new password' => 'Confirmar nueva contraseña',
            'Save Password' => 'Guardar contraseña',
            'Generate Password' => 'Generar contraseña',
            'Strength indicator' => 'Seguridad de la contraseña',
            'Confirm use of weak password' => 'Confirmar el uso de una contraseña débil',
            'Please enter your username or email address. You will receive an email message with instructions on how to reset your password.' => 'Escribe tu usuario o correo electrónico. Recibirás un enlace para restablecer tu contraseña.',
            'Enter your new password below or generate one.' => 'Escribe una nueva contraseña o genera una automáticamente.',
            'Your password has been reset.' => 'Tu contraseña se ha restablecido.',
            'You are now logged out.' => 'Has cerrado sesión.',
            'You have logged in successfully.' => 'Has iniciado sesión correctamente.',
            '<strong>Error:</strong> The username field is empty.' => '<strong>Error:</strong> Escribe tu usuario.',
            '<strong>Error:</strong> The password field is empty.' => '<strong>Error:</strong> Escribe tu contraseña.',
            '<strong>Error:</strong> The email field is empty.' => '<strong>Error:</strong> Escribe tu correo electrónico.',
            '<strong>Error:</strong> The password you entered for the username %s is incorrect.' => '<strong>Error:</strong> La contraseña de %s es incorrecta.',
            '<strong>Error:</strong> The password you entered for the email address %s is incorrect.' => '<strong>Error:</strong> La contraseña de %s es incorrecta.',
            '<strong>Error:</strong> The username <strong>%s</strong> is not registered on this site. If you are unsure of your username, try your email address instead.' => '<strong>Error:</strong> El usuario <strong>%s</strong> no está registrado. Prueba con tu correo electrónico.',
            'Unknown email address. Check again or try your username.' => 'No se reconoce ese correo electrónico. Revísalo o prueba con tu usuario.',
        ];
        return $labels[$text] ?? $translation;
    }

    public static function welcome(string $message): string
    {
        $copy = match ($GLOBALS['action'] ?? 'login') {
            'login' => ['Te damos la bienvenida', 'Ingresa con tu cuenta para acceder a la comunidad.'],
            'lostpassword', 'retrievepassword' => ['Recupera tu acceso', 'Te ayudamos a volver a tu comunidad.'],
            'resetpass', 'rp' => ['Elige tu nueva contraseña', 'Protege tu cuenta con una contraseña única.'],
            'register' => ['Únete a la comunidad', 'Completa los datos para solicitar tu cuenta.'],
            default => ['Tu cuenta ASCLA', 'Gestiona tu acceso a la comunidad.'],
        };
        return '<div class="ascla-login-intro"><p class="ascla-login-eyebrow">INTRANET ASCLA</p><h2>'
            . esc_html($copy[0]) . '</h2><p>' . esc_html($copy[1]) . '</p></div>' . $message;
    }

    public static function redirect(string $redirect, string $requested, $user): string
    {
        if (!$user instanceof \WP_User || !user_can($user, 'ascla_access') || user_can($user, 'manage_options')) {
            return $redirect;
        }
        $fallback = Catalog::url('intranet');
        $target = wp_validate_redirect($requested, $fallback);
        // Only ASCLA page IDs may receive member redirects, including query-string permalinks.
        $pageId = url_to_postid($target);
        return $pageId && in_array($pageId, array_map('intval', get_option('ascla_pages', [])), true)
            ? $target : $fallback;
    }
}
