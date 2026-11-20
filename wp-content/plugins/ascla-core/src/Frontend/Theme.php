<?php
namespace ASCLA\Core\Frontend;

/** Shares the ASCLA palette and applies the preferred appearance before paint. */
final class Theme
{
    public const STORAGE_KEY='ascla-theme';

    public static function registerBrandStyle(): void
    {
        wp_register_style('ascla-brand', ASCLA_URL . 'assets/brand.css', [], App::assetVersion('assets/brand.css'));
    }

    public static function script(): string
    {
        return <<<'JS'
(function(){
  try {
    var key='ascla-theme';
    var mode=localStorage.getItem(key);
    if(mode!=='light'&&mode!=='dark'&&mode!=='system') mode='system';
    var dark=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches;
    var theme=mode==='system'?(dark?'dark':'light'):mode;
    var html=document.documentElement;
    html.dataset.asclaTheme=theme;
    html.dataset.asclaThemeMode=mode;
    html.style.colorScheme=theme;
  } catch(e) {
    var fallback=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';
    document.documentElement.dataset.asclaTheme=fallback;
    document.documentElement.dataset.asclaThemeMode='system';
    document.documentElement.style.colorScheme=fallback;
  }
})();
JS;
    }

    public static function printScript(): void
    {
        wp_print_inline_script_tag(self::script(),['id'=>'ascla-theme-bootstrap']);
    }

    public static function enqueueLoginControl(): void
    {
        wp_enqueue_script('ascla-login-theme',ASCLA_URL.'assets/login-theme.js',[],App::assetVersion('assets/login-theme.js'),true);
    }

    public static function loginSelector(): void
    {
        $title=Language::label('Apariencia');
        echo '<div class="ascla-appearance-switcher" hidden><button type="button" id="ascla-login-theme" class="ascla-login-theme-button theme-button" aria-label="'.esc_attr($title).'" title="'.esc_attr($title).'" aria-haspopup="dialog" aria-controls="ascla-login-appearance" aria-expanded="false"><span class="theme-icon theme-icon-light">'.Icons::html('sun').'</span><span class="theme-icon theme-icon-dark">'.Icons::html('moon').'</span></button>';
        echo '<dialog id="ascla-login-appearance" class="ascla-login-appearance" aria-labelledby="ascla-login-appearance-title"><div class="ascla-login-appearance-heading"><h2 id="ascla-login-appearance-title">'.esc_html($title).'</h2><button type="button" class="ascla-login-theme-close" aria-label="'.esc_attr(Language::label('Cerrar')).'">'.Icons::html('close').'</button></div><p>'.esc_html(Language::label('Elige cómo quieres ver la intranet. En Automático, ASCLA sigue la preferencia de tu dispositivo.')).'</p><div class="ascla-login-theme-options">';
        foreach ([
            'system'=>['Automático (sistema)','Usa el modo claro u oscuro configurado en tu dispositivo.'],
            'light'=>['Claro','Mantiene la interfaz clara en este navegador.'],
            'dark'=>['Oscuro','Mantiene la interfaz oscura en este navegador.'],
        ] as $mode=>[$label,$description]) {
            echo '<button type="button" class="ascla-login-theme-choice" data-theme="'.esc_attr($mode).'" aria-pressed="false"><span class="ascla-login-theme-title">'.esc_html(Language::label($label)).'</span><span>'.esc_html(Language::label($description)).'</span></button>';
        }
        echo '</div></dialog></div>';
    }

    public static function openLoginPreferences(): void
    {
        echo '<div class="ascla-login-preferences">';
    }

    public static function closeLoginPreferences(): void
    {
        echo '</div>';
    }
}
