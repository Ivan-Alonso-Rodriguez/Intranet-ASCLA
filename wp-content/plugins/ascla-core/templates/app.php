<?php defined('ABSPATH') || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html(get_the_title().' · ASCLA'); ?></title><?php wp_head(); ?></head>
<body class="ascla-app-body"><?php wp_body_open(); ?><a class="skip-link" href="#main">Ir al contenido</a><div id="ascla-root"><div class="loading">Cargando tu comunidad ASCLA…</div></div><noscript>Active JavaScript para utilizar la intranet.</noscript><?php wp_footer(); ?></body>
</html>
