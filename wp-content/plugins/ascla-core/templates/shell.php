<?php
use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Frontend\Icons;
use ASCLA\Core\Frontend\Language;
defined('ABSPATH') || exit;
$page=$args['page'];$profile=$args['profile'];$settings=$args['settings'];
$icons=['intranet'=>'home','perfil'=>'users','eventos'=>'calendar','hub'=>'hub','galeria'=>'gallery','foros'=>'hub','directorio'=>'users','centro-conocimiento'=>'book','asistente'=>'spark','aliados'=>'ally','mensajeria'=>'mail','contacto'=>'contact'];
$logo=ASCLA_URL.'assets/ascla-logo.png';
$initials=implode('',array_map(static fn($word)=>mb_substr($word,0,1),array_slice(preg_split('/\s+/u',$profile['name']),0,2)));
?>
<aside class="ascla-sidebar">
<a class="brand" href="<?php echo esc_url(Catalog::url('intranet')); ?>" aria-label="ASCLA inicio"><img src="<?php echo esc_url($logo); ?>" alt="ASCLA" width="1672" height="941"></a>
<div class="brand-sub"><?php echo esc_html(Language::label('COMUNIDAD DE ASOCIADOS')); ?></div><nav aria-label="<?php echo esc_attr(Language::label('Navegación principal')); ?>">
<?php foreach(Catalog::PAGES as $key=>$label): ?><a class="nav-link <?php echo $page===$key?'active':''; ?>" href="<?php echo esc_url(Catalog::url($key)); ?>" <?php if($page===$key){echo 'aria-current="page"';} ?>><?php echo Icons::html($icons[$key]); ?><span><?php echo esc_html(Language::label($label)); ?></span></a><?php endforeach; ?>
</nav><div class="nav-bottom"><?php if(current_user_can('ascla_moderate')): ?><a class="nav-link" href="<?php echo esc_url(admin_url('admin.php?page=ascla')); ?>"><?php echo Icons::html('settings'); ?><?php echo esc_html(Language::label('Administración')); ?></a><?php endif; ?><a class="nav-link" href="<?php echo esc_url(wp_logout_url(wp_login_url())); ?>"><?php echo Icons::html('logout'); ?><?php echo esc_html(Language::label('Cerrar sesión')); ?></a></div></aside>
<div class="ascla-main"><header class="ascla-header">
<a class="header-brand" href="<?php echo esc_url(Catalog::url('intranet')); ?>" aria-label="ASCLA inicio"><img src="<?php echo esc_url($logo); ?>" alt="ASCLA" width="1672" height="941"></a>
<button type="button" class="btn icon-button mobile-menu" data-action="menu" aria-label="<?php echo esc_attr(Language::label('Abrir navegación')); ?>"><?php echo Icons::html('menu'); ?></button>
<form class="header-search" data-form="global-search"><?php echo Icons::html('search'); ?><input name="q" aria-label="<?php echo esc_attr(Language::label('Buscar en ASCLA')); ?>" placeholder="<?php echo esc_attr(Language::label('Buscar en tu comunidad…')); ?>" autocomplete="off"></form>
<div class="header-right"><?php if($settings['demo']): ?><span class="demo-badge">DEMO MODE</span><?php endif; ?>
<button type="button" class="btn icon-button" data-action="notifications" aria-label="<?php echo esc_attr(Language::label('Notificaciones')); ?>"><?php echo Icons::html('bell'); ?><span class="notification-count" hidden></span></button>
<a class="header-profile" aria-label="<?php echo esc_attr(Language::label('Mi perfil')); ?>" href="<?php echo esc_url(Catalog::url('perfil')); ?>"><span class="avatar"><?php if(!empty($profile['photo_url'])): ?><img src="<?php echo esc_url($profile['photo_url']); ?>" alt="<?php echo esc_attr($profile['name']); ?>"><?php else: echo esc_html($initials); endif; ?></span><span><strong><?php echo esc_html($profile['name']); ?></strong><small class="muted"><?php echo esc_html($profile['member_type']??'Comunidad ASCLA'); ?></small></span><?php echo Icons::html('chevron'); ?></a></div></header>
<main id="main" class="page-wrap"><div class="breadcrumb">ASCLA › <?php echo esc_html(Language::label(Catalog::PAGES[$page]??'Inicio')); ?></div><div id="page-content"><output class="view-loading"><?php echo esc_html(Language::label('Cargando tu comunidad ASCLA…')); ?></output></div><div class="demo-footer">© <?php echo esc_html(wp_date('Y')); ?> ASCLA · Conectamos conocimiento, fortalecemos la gobernanza.<?php if($settings['demo']){echo ' · Datos ficticios de demostración.';} ?></div></main></div>
