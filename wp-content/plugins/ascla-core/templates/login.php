<?php
use ASCLA\Core\Frontend\Language;
use ASCLA\Core\Frontend\Login;
use ASCLA\Core\Frontend\Theme;
use ASCLA\Core\Services\Turnstile;

defined('ABSPATH') || exit;

$locale=Language::requested() ?: Language::current();
$error=Login::frontErrorMessage();
$loggedOut=Login::consumeLoggedOutNotice();
$userValue='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && isset($_POST['log']) && is_string($_POST['log'])){
    $userValue=trim(wp_unslash($_POST['log']));
}
$asclaAdminContext=false;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?php echo esc_html(Language::text('Entrar a la comunidad · ASCLA','Sign in to the community · ASCLA')); ?></title>
<?php Theme::printScript(); wp_head(); ?>
</head>
<body class="login login-action-login wp-core-ui ascla-login ascla-frontend-login">
<?php require_once ASCLA_PATH.'templates/login-welcome.php'; ?>

<div id="login">
    <h1><a href="<?php echo esc_url(Login::url()); ?>" aria-label="ASCLA">ASCLA</a></h1>

    <div class="ascla-login-intro">
        <p class="ascla-login-eyebrow">INTRANET ASCLA</p>
        <h2 id="ascla-login-title"><?php echo esc_html(Language::text('Te damos la bienvenida','Welcome to ASCLA')); ?></h2>
        <p><?php echo esc_html(Language::text('Ingresa con tu cuenta para acceder a la comunidad.','Sign in with your account to access the community.')); ?></p>
    </div>

    <?php if($loggedOut): ?>
        <output class="message success"><?php echo esc_html(Language::text('Has cerrado sesión correctamente.','You have signed out successfully.')); ?></output>
    <?php endif; ?>
    <?php if($error!==''): ?>
        <div id="login_error" role="alert"><?php echo esc_html($error); ?></div>
    <?php endif; ?>

    <form name="loginform" id="loginform" action="<?php echo esc_url(Login::url()); ?>" method="post" novalidate>
        <p>
            <label for="user_login"><?php echo esc_html(Language::text('Usuario o correo electrónico','Username or email address')); ?></label>
            <input type="text" name="log" id="user_login" class="input" value="<?php echo esc_attr($userValue); ?>" size="20" autocapitalize="off" autocomplete="username" required autofocus>
        </p>
        <div class="user-pass-wrap">
            <label for="user_pass"><?php echo esc_html(Language::text('Contraseña','Password')); ?></label>
            <div class="wp-pwd">
                <input type="password" name="pwd" id="user_pass" class="input password-input" value="" size="20" autocomplete="current-password" spellcheck="false" required>
                <button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="<?php echo esc_attr(Language::text('Mostrar contraseña','Show password')); ?>">
                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                </button>
            </div>
        </div>

        <?php Turnstile::render('login'); ?>

        <p class="forgetmenot">
            <input name="rememberme" type="checkbox" id="rememberme" value="forever">
            <label for="rememberme"><?php echo esc_html(Language::text('Recordarme','Remember me')); ?></label>
        </p>
        <p class="submit">
            <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php echo esc_attr(Language::text('Entrar a la comunidad','Sign in to the community')); ?>">
        </p>
        <?php wp_nonce_field('ascla_frontend_login','_ascla_login_nonce'); ?>
        <input type="hidden" name="_ascla_locale" value="<?php echo esc_attr($locale); ?>">
        <input type="hidden" name="ascla_frontend_login" value="1">
    </form>

    <p id="nav"><a href="<?php echo esc_url(wp_lostpassword_url(Login::url())); ?>"><?php echo esc_html(Language::text('¿Olvidaste tu contraseña?','Lost your password?')); ?></a></p>
    <p id="backtoblog"><a href="<?php echo esc_url(home_url('/')); ?>">&larr; <?php echo esc_html(Language::text('Volver a ASCLA','Back to ASCLA')); ?></a></p>
</div>

<p class="ascla-login-help"><?php echo esc_html(Language::text('¿Aún no tienes una cuenta? Solicita tu acceso a la administración de ASCLA.','Need an account? Request access from ASCLA administration.')); ?></p>

<div class="language-switcher">
    <form id="language-switcher" method="post" action="<?php echo esc_url(Login::url()); ?>">
        <label for="ascla-login-language"><?php echo esc_html(Language::text('Idioma','Language')); ?></label>
        <select id="ascla-login-language" name="_ascla_locale">
            <?php foreach(Language::SUPPORTED as $code=>$name): ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($locale,$code); ?>><?php echo esc_html($name); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="_ascla_login_language" value="1">
        <?php wp_nonce_field('ascla_login_language','_ascla_language_nonce'); ?>
        <button type="submit" class="button"><?php echo esc_html(Language::text('Cambiar','Change')); ?></button>
    </form>
</div>

<script>
(function(){
  var button=document.querySelector('.wp-hide-pw');
  var input=document.getElementById('user_pass');
  if(!button||!input)return;
  button.classList.remove('hide-if-no-js');
  button.addEventListener('click',function(){
    var show=input.type==='password';
    input.type=show?'text':'password';
    button.setAttribute('aria-label',show?'<?php echo esc_js(Language::text('Ocultar contraseña','Hide password')); ?>':'<?php echo esc_js(Language::text('Mostrar contraseña','Show password')); ?>');
    var icon=button.querySelector('.dashicons');
    if(icon){
      icon.classList.toggle('dashicons-visibility',!show);
      icon.classList.toggle('dashicons-hidden',show);
    }
  });
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
