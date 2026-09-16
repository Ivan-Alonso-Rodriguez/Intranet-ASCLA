<?php
use ASCLA\Core\Frontend\Language;
defined('ABSPATH') || exit;
$admin = !empty($asclaAdminContext);
?>
<aside class="ascla-welcome<?php echo $admin ? ' ascla-welcome-admin' : ''; ?>" aria-label="<?php echo esc_attr($admin ? Language::text('Administración ASCLA','ASCLA administration') : Language::text('Comunidad ASCLA','ASCLA community')); ?>">
    <div class="ascla-welcome-brand"><img src="<?php echo esc_url(add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo.png')); ?>" alt="ASCLA" class="ascla-official-logo ascla-logo-normal"><img src="<?php echo esc_url(add_query_arg('ver',ASCLA_VERSION,ASCLA_URL.'assets/ascla-logo-white.png')); ?>" alt="" aria-hidden="true" class="ascla-official-logo ascla-logo-inverse"></div>
    <div class="ascla-welcome-copy">
        <?php if($admin): ?>
            <p class="ascla-welcome-eyebrow"><?php echo esc_html(Language::text('ADMINISTRACIÓN · SEGURIDAD · CONTROL','ADMINISTRATION · SECURITY · CONTROL')); ?></p>
            <h2><?php echo wp_kses_post(Language::text('Gestiona ASCLA<br>de forma segura.','Manage ASCLA<br>securely.')); ?></h2>
            <p><?php echo esc_html(Language::text('Acceso reservado para la administración técnica de WordPress y la configuración general de la plataforma.','Reserved access for WordPress technical administration and general platform configuration.')); ?></p>
            <div class="ascla-welcome-topics"><span><?php echo esc_html(Language::text('Usuarios','Users')); ?></span><span><?php echo esc_html(Language::text('Configuración','Settings')); ?></span><span><?php echo esc_html(Language::text('Operación','Operations')); ?></span></div>
        <?php else: ?>
            <p class="ascla-welcome-eyebrow"><?php echo esc_html(Language::text('CONECTA · COMPARTE · CRECE','CONNECT · SHARE · GROW')); ?></p>
            <h2><?php echo wp_kses_post(Language::text('El conocimiento<br>nos conecta.','Knowledge<br>connects us.')); ?></h2>
            <p><?php echo esc_html(Language::text('Un espacio para compartir experiencias, fortalecer vínculos y construir juntos el futuro de la secretaría corporativa.','A place to share experiences, build connections and shape the future of corporate governance together.')); ?></p>
            <div class="ascla-welcome-topics"><span><?php echo esc_html(Language::text('Comunidad','Community')); ?></span><span><?php echo esc_html(Language::text('Conocimiento','Knowledge')); ?></span><span>Networking</span></div>
        <?php endif; ?>
    </div>
    <div class="ascla-welcome-footer"><span class="ascla-welcome-line"></span>Asociación de Secretarios Corporativos<br>de América Latina</div>
    <div class="ascla-welcome-orbit" aria-hidden="true"><i></i><i></i><i></i></div>
</aside>
