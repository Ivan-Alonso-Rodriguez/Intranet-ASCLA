<?php
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Frontend\Icons;
use ASCLA\Core\Frontend\Language;
use ASCLA\Core\Frontend\Login;
use ASCLA\Core\Frontend\App;
use ASCLA\Core\Services\Access;
defined('ABSPATH') || exit;
$page=$args['page'];$profile=$args['profile'];$settings=$args['settings'];
$administrator=Access::isAdministrator();$homeUrl=App::homeUrl();
$icons=['intranet'=>'home','perfil'=>'users','eventos'=>'calendar','hub'=>'hub','galeria'=>'gallery','foros'=>'hub','directorio'=>'users','centro-conocimiento'=>'book','asistente'=>'spark','aliados'=>'ally','mensajeria'=>'mail','contacto'=>'contact'];
$logo=add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo.png');
$logoWhite=add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo-white.png');
$initials=implode('',array_map(static fn($word)=>mb_substr($word,0,1),array_slice(preg_split('/\s+/u',$profile['name']),0,2)));
?>
<aside class="ascla-sidebar">
<a class="brand" href="<?php echo esc_url($homeUrl); ?>" aria-label="<?php echo esc_attr(Language::label('ASCLA inicio')); ?>"><img src="<?php echo esc_url($logoWhite); ?>" alt="ASCLA" width="1608" height="472"></a>
<div class="brand-sub"><?php echo esc_html(Language::label('COMUNIDAD DE ASOCIADOS')); ?></div><nav aria-label="<?php echo esc_attr(Language::label('Navegación principal')); ?>">
<?php foreach(Catalog::PAGES as $key=>$label):
    if($administrator && in_array($key,['directorio','mensajeria','contacto'],true)){continue;}
    $adminHome=$administrator && $key==='intranet';
    $active=$adminHome?$page==='admin':$page===$key;
    $url=$adminHome?$homeUrl:Catalog::url($key);
    if($adminHome){$label='Administración';}
?><a class="nav-link <?php echo $active?'active':''; ?>" href="<?php echo esc_url($url); ?>" <?php if($active){echo 'aria-current="page"';} ?>><?php echo Icons::html($adminHome?'settings':$icons[$key]); ?><span><?php echo esc_html(Language::label($label)); ?></span></a><?php endforeach; ?>
</nav><div class="nav-bottom"><?php if(current_user_can('ascla_admin_area') && !$administrator): ?><a class="nav-link" href="<?php echo esc_url(App::adminUrl()); ?>"><?php echo Icons::html('settings'); ?><?php echo esc_html(Language::label('Administración')); ?></a><?php endif; ?><a class="nav-link" href="<?php echo esc_url(wp_logout_url(Login::url())); ?>"><?php echo Icons::html('logout'); ?><?php echo esc_html(Language::label('Cerrar sesión')); ?></a></div></aside>
<div class="ascla-main"><header class="ascla-header">
<a class="header-brand" href="<?php echo esc_url($homeUrl); ?>" aria-label="<?php echo esc_attr(Language::label('ASCLA inicio')); ?>"><img class="brand-logo-normal" src="<?php echo esc_url($logo); ?>" alt="ASCLA" width="1343" height="382"><img class="brand-logo-inverse" src="<?php echo esc_url($logoWhite); ?>" alt="" aria-hidden="true" width="1608" height="472"></a>
<button type="button" class="btn icon-button mobile-menu" data-action="menu" aria-label="<?php echo esc_attr(Language::label('Abrir navegación')); ?>"><?php echo Icons::html('menu'); ?></button>
<form class="header-search" data-form="global-search"><?php echo Icons::html('search'); ?><input name="q" aria-label="<?php echo esc_attr(Language::label('Buscar en ASCLA')); ?>" placeholder="<?php echo esc_attr(Language::label('Buscar en tu comunidad…')); ?>" autocomplete="off"></form>
<div class="header-right"><?php if($settings['demo']): ?><span class="demo-badge">DEMO MODE</span><?php endif; ?>
<form class="header-language" method="post" action="" aria-label="<?php echo esc_attr(Language::text('Cambiar idioma','Change language')); ?>">
<label class="screen-reader-text" for="ascla-app-language"><?php echo esc_html(Language::text('Idioma','Language')); ?></label>
<select id="ascla-app-language" name="_ascla_locale" aria-label="<?php echo esc_attr(Language::text('Idioma','Language')); ?>" data-admin-language-select><?php foreach(Language::SUPPORTED as $locale=>$name): ?><option value="<?php echo esc_attr($locale); ?>" <?php selected(Language::current(),$locale); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select>
<input type="hidden" name="_ascla_change_language" value="1"><?php wp_nonce_field('ascla_change_language','_ascla_language_nonce'); ?>
<noscript><button type="submit" class="btn small"><?php echo esc_html(Language::text('Cambiar','Change')); ?></button></noscript>
</form>
<button type="button" class="btn icon-button theme-button" data-action="theme-menu" aria-label="<?php echo esc_attr(Language::text('Apariencia','Appearance')); ?>" title="<?php echo esc_attr(Language::text('Apariencia','Appearance')); ?>"><span class="theme-icon theme-icon-light"><?php echo Icons::html('sun'); ?></span><span class="theme-icon theme-icon-dark"><?php echo Icons::html('moon'); ?></span></button>
<button type="button" class="btn icon-button" data-action="notifications" aria-label="<?php echo esc_attr(Language::label('Notificaciones')); ?>"><?php echo Icons::html('bell'); ?><span class="notification-count" hidden></span></button>
<a class="header-profile" href="<?php echo esc_url(Catalog::url('perfil')); ?>"><span class="avatar"><?php if(!empty($profile['photo_url'])): ?><img src="<?php echo esc_url($profile['photo_url']); ?>" alt="<?php echo esc_attr($profile['name']); ?>"><?php else: echo esc_html($initials); endif; ?></span><span><strong><?php echo esc_html($profile['name']); ?></strong><small class="muted"><?php echo esc_html($profile['member_type']??'Comunidad ASCLA'); ?></small></span><?php echo Icons::html('chevron'); ?></a></div></header>
<main id="main" class="page-wrap" tabindex="-1"><div class="breadcrumb">ASCLA › <?php echo esc_html(Language::label(Catalog::PAGES[$page]??($page==='admin'?'Administración':'Inicio'))); ?></div><div id="page-content"><output class="view-loading"><?php echo esc_html(Language::label('Cargando tu comunidad ASCLA…')); ?></output></div><div class="demo-footer">© <?php echo esc_html(wp_date('Y')); ?> ASCLA · <?php echo esc_html(Language::label('Conectamos conocimiento, fortalecemos la gobernanza.')); ?><?php if($settings['demo']){echo ' · '.esc_html(Language::label('Datos ficticios de demostración.'));} ?></div></main></div>
