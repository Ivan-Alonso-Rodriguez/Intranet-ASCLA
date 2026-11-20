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
        echo '<div class="ascla-appearance-switcher" hidden><label for="ascla-login-theme">'.esc_html(Language::label('Apariencia')).'</label><select id="ascla-login-theme">';
        foreach (['system'=>'Automático (sistema)','light'=>'Claro','dark'=>'Oscuro'] as $mode=>$label) {
            echo '<option value="'.esc_attr($mode).'">'.esc_html(Language::label($label)).'</option>';
        }
        echo '</select></div>';
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
