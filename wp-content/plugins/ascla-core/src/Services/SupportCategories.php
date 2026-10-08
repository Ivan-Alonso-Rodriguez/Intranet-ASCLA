<?php
namespace ASCLA\Core\Services;

final class SupportCategories
{
    public const DEFAULTS=['Consulta general','Soporte técnico','Eventos','Membresía','Cambio de datos del perfil','Sugerencia'];
    public static function all(): array
    {
        $settings=(array)get_option('ascla_settings',[]);
        return array_values((array)($settings['support_categories']??self::DEFAULTS));
    }
    public static function settings(array &$data,array $input): void
    {
        if (!array_key_exists('support_categories',$input)) { return; }
        $input=$input['support_categories'];
        Access::require(is_string($input)||is_array($input),'Categorías de Contacto no válidas.',400);
        $values=is_array($input)?$input:explode("\n",$input);
        $values=array_values(array_unique(array_filter(array_map(static fn($v)=>trim(Access::text($v,80)),$values))));
        Access::require(count($values)>=1 && count($values)<=20,'Configura entre 1 y 20 categorías de Contacto.',400);
        $data['support_categories']=$values;
    }
    public static function validate(array &$meta,array $input): void
    {
        $categories=self::all();$category=trim(Access::text($input['description']??$categories[0],80));
        Access::require(in_array($category,$categories,true),'Selecciona una categoría de Contacto vigente.',400);
        $meta['description']=$category;
    }
}
