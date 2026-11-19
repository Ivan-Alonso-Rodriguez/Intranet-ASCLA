<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><?php \ASCLA\Core\Frontend\Theme::printScript(); ?><title><?php echo esc_html(get_the_title().' · ASCLA'); ?></title><style id="ascla-critical" nonce="<?php echo esc_attr(\ASCLA\Core\Frontend\SecurityHeaders::nonce()); ?>">
html,body{margin:0;background:#f6f9fc;color:#19344d}
#ascla-root{min-height:100vh;font-family:system-ui,sans-serif}
.ascla-sidebar{position:fixed;inset:0 auto 0 0;width:248px;background:#eff7ff;color:#19344d;box-sizing:border-box;padding:25px 16px;overflow:auto}
.ascla-main{margin-left:248px}
.ascla-header{height:68px;background:#fff;display:flex;align-items:center;padding:0 36px}
.brand{display:flex;width:165px;height:49px}
.brand img,.header-brand img{width:100%;height:100%;object-fit:contain}
.header-brand,.brand-logo-inverse{display:none}
.breadcrumb{color:#4e657a}
.page-wrap{padding:25px 36px}
.nav-link{display:flex;gap:12px;padding:12px;text-decoration:none;color:inherit}
.nav-link svg{width:20px;height:20px}
.view-loading{display:block;min-height:55vh;padding:36px 0}
@media(max-width:1279px){.ascla-sidebar{width:224px}.ascla-main{margin-left:224px}}
@media(max-width:900px){.ascla-sidebar{transform:translateX(-100%)}.ascla-main{margin-left:0}.ascla-header{height:72px;padding:0 22px}.header-brand{display:flex;width:116px;height:38px}.ascla-header .breadcrumb{display:none}.page-wrap{padding:24px}}
@media(max-width:600px){.ascla-header{height:66px;padding:0 16px}.header-brand{width:92px;height:32px}.page-wrap{padding:20px 16px}}
html[data-ascla-theme="dark"],html[data-ascla-theme="dark"] body,html[data-ascla-theme="dark"] #ascla-root{background:#122334;color:#e8f2fb}
html[data-ascla-theme="dark"] .ascla-sidebar{background:#172b40;color:#e8f2fb}
html[data-ascla-theme="dark"] .ascla-header{background:#1a2e43}
html[data-ascla-theme="dark"] .brand-logo-normal{display:none}
html[data-ascla-theme="dark"] .brand-logo-inverse{display:block}
</style><link rel="preload" as="image" href="<?php echo esc_url(add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo.png')); ?>"><link rel="preload" as="image" href="<?php echo esc_url(add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo-white.png')); ?>"><?php wp_head(); ?></head>
<body class="ascla-app-body"><?php wp_body_open(); ?><a class="skip-link" href="#main"><?php echo esc_html(\ASCLA\Core\Frontend\Language::label('Ir al contenido')); ?></a><div id="ascla-root"><?php \ASCLA\Core\Frontend\App::shell(); ?></div><noscript><?php echo esc_html(\ASCLA\Core\Frontend\Language::label('Active JavaScript para utilizar la intranet.')); ?></noscript><?php wp_footer(); ?></body>
</html>
