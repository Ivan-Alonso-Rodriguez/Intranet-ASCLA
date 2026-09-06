<?php
namespace ASCLA\Core\Domain;

final class Catalog
{
    public const TYPES = ['hub'=>'Hub ASCLA','forum'=>'Foros','topic'=>'Temas','event'=>'Eventos','resource'=>'Conocimiento','gallery'=>'Galerías','ally'=>'Aliados','contact'=>'Solicitudes'];
    public const PAGES = ['intranet'=>'Inicio','perfil'=>'Perfil','eventos'=>'Eventos y Capacitaciones','hub'=>'Hub ASCLA','galeria'=>'Galería','foros'=>'Foros','directorio'=>'Directorio','centro-conocimiento'=>'Centro de Conocimiento','asistente'=>'Asistente IA','aliados'=>'Aliados','mensajeria'=>'Mensajería','contacto'=>'Contacto'];
    public const TAXONOMIES = ['interest'=>'Intereses','area'=>'Áreas de conocimiento','industry'=>'Industrias','goal'=>'Objetivos de networking','language'=>'Idiomas','category'=>'Categorías'];
    public static function register(): void
    {
        foreach (self::TYPES as $key => $label) {
            register_post_type('ascla_'.$key, [
                'label'=>$label, 'public'=>false, 'publicly_queryable'=>false,
                'show_ui'=>false, 'show_in_rest'=>false, 'exclude_from_search'=>true,
                'supports'=>['title','editor','author','comments','revisions'],
                'map_meta_cap'=>true, 'capability_type'=>['ascla_item','ascla_items'],
                'rewrite'=>false,
            ]);
        }
        foreach (self::TAXONOMIES as $key=>$label) {
            register_taxonomy('ascla_'.$key, array_map(static fn($k)=>'ascla_'.$k, array_keys(self::TYPES)), [
                'label'=>$label,'public'=>false,'show_ui'=>false,'show_in_rest'=>false,'hierarchical'=>true,
                'capabilities'=>['manage_terms'=>'ascla_manage','edit_terms'=>'ascla_manage','delete_terms'=>'ascla_manage','assign_terms'=>'ascla_write'],
            ]);
        }
        register_post_status('ascla_rejected', ['label'=>'Rechazado','public'=>false,'internal'=>true]);
        register_post_status('ascla_hidden', ['label'=>'Oculto','public'=>false,'internal'=>true]);
    }
    public static function url(string $page, array $args=[]): string
    {
        $ids = get_option('ascla_pages', []);
        return add_query_arg($args, isset($ids[$page]) ? get_permalink($ids[$page]) : home_url('/'.$page.'/'));
    }
}
