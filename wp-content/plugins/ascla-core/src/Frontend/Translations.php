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

    /** Only registered system errors are translated; user content is never rewritten. */
    public static function error(string $message): string
    {
        $translated=self::text($message);
        if ($translated!==$message) { return $translated; }
        $formats=[
            '/^El texto supera el límite de (\d+) caracteres\.$/u'=>'El texto supera el límite de %d caracteres.',
            '/^La nueva contraseña debe tener al menos (\d+) caracteres y como máximo (\d+) bytes\.$/u'=>'La nueva contraseña debe tener al menos %d caracteres y como máximo %d bytes.',
        ];
        foreach ($formats as $pattern=>$format) {
            if (preg_match($pattern,$message,$matches)) { return vsprintf(self::text($format),array_map('intval',array_slice($matches,1))); }
        }
        $prefix='Completa los campos obligatorios: ';
        if (str_starts_with($message,$prefix) && str_ends_with($message,'.')) {
            $fields=explode(', ',substr($message,strlen($prefix),-1));
            $allowed=array_values(\ASCLA\Core\Services\ProfileRequirements::options()['required']);
            if ($fields && !array_diff($fields,$allowed)) {
                return self::text('Completa los campos obligatorios:').' '.implode(', ',array_map([self::class,'text'],$fields)).'.';
            }
        }
        return $message;
    }
}
