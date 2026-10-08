<?php
namespace ASCLA\Core\Frontend;

/** Standard WordPress gettext catalog, also projected to the existing JS dictionary. */
final class Translations
{
    private static string $locale='';

    public static function load(?string $locale=null): void
    {
        $locale=Language::valid($locale??Language::current())?:'es_ES';
        if (self::$locale===$locale && is_textdomain_loaded('ascla-core')) { return; }
        unload_textdomain('ascla-core',true);
        $global=WP_LANG_DIR.'/plugins/ascla-core-'.$locale.'.mo';
        $loaded=is_readable($global) && load_textdomain('ascla-core',$global,$locale);
        if (!$loaded) { load_textdomain('ascla-core',ASCLA_PATH.'languages/ascla-core-'.$locale.'.mo',$locale); }
        self::$locale=$locale;
    }

    public static function text(string $source): string
    {
        self::load();
        return __($source,'ascla-core');
    }

    public static function labels(): array
    {
        static $sources=null;
        $sources??=json_decode(file_get_contents(ASCLA_PATH.'languages/en.json'),true)?:[];
        self::load();$labels=[];
        foreach ($sources as $source=>$fallback) {
            $translated=__($source,'ascla-core');
            $labels[$source]=$translated===$source && Language::english()?$fallback:$translated;
        }
        return $labels;
    }
}
