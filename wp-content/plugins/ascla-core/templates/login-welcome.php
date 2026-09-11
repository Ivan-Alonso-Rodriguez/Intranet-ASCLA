<?php use ASCLA\Core\Frontend\Language; defined('ABSPATH') || exit; ?>
<aside class="ascla-welcome" aria-label="Comunidad ASCLA">
    <div class="ascla-welcome-brand"><img src="<?php echo esc_url(ASCLA_URL.'assets/ascla-logo.png'); ?>" alt="ASCLA" class="ascla-official-logo"></div>
    <div class="ascla-welcome-copy">
        <p class="ascla-welcome-eyebrow"><?php echo esc_html(Language::text('CONECTA · COMPARTE · CRECE','CONNECT · SHARE · GROW')); ?></p>
        <h2><?php echo wp_kses_post(Language::text('El conocimiento<br>nos conecta.','Knowledge<br>connects us.')); ?></h2>
        <p><?php echo esc_html(Language::text('Un espacio para compartir experiencias, fortalecer vínculos y construir juntos el futuro de la secretaría corporativa.','A place to share experiences, build connections and shape the future of corporate governance together.')); ?></p>
        <div class="ascla-welcome-topics"><span><?php echo esc_html(Language::text('Comunidad','Community')); ?></span><span><?php echo esc_html(Language::text('Conocimiento','Knowledge')); ?></span><span>Networking</span></div>
    </div>
    <div class="ascla-welcome-footer"><span class="ascla-welcome-line"></span>Asociación de Secretarios Corporativos<br>de América Latina</div>
    <div class="ascla-welcome-orbit" aria-hidden="true"><i></i><i></i><i></i></div>
</aside>
