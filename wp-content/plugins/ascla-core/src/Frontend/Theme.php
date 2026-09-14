<?php
namespace ASCLA\Core\Frontend;

/** Applies the preferred color scheme before paint to avoid a light/dark flash. */
final class Theme
{
    public const STORAGE_KEY='ascla-theme';

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
        echo '<script id="ascla-theme-bootstrap">'.self::script().'</script>';
    }
}
