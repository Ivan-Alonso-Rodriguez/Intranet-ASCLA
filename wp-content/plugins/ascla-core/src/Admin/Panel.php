<?php
namespace ASCLA\Core\Admin;
final class Panel
{
    public static function boot(): void
    {
        add_action('admin_menu',static function () {
            // Legacy/native wp-admin entry for technical Administrators. Ejecutivo and Moderador use /administracion/.
            add_menu_page('ASCLA','ASCLA','ascla_admin_area','ascla',[self::class,'render'],'dashicons-groups',3);
            // Eventos, Conocimiento y Galería son responsabilidad del Ejecutivo (ascla_publish);
            // Miembros, Integraciones y Configuración quedan reservados al Administrador (ascla_manage).
            $caps=['Importar intereses'=>'ascla_manage','Estadísticas'=>'ascla_publish','Dashboard'=>'ascla_admin_area','IA'=>'ascla_admin_area','Microeventos'=>'ascla_manage','Logs'=>'ascla_manage','Miembros'=>'ascla_manage','Integraciones'=>'ascla_manage','Configuración'=>'ascla_manage','Eventos'=>'ascla_publish','Conocimiento'=>'ascla_publish','Galería'=>'ascla_publish'];
            foreach (['Dashboard','Importar intereses','Estadísticas','Miembros','Hub','Moderación','Eventos','Microeventos','Solicitudes','Conocimiento','IA','Networking','Galería','Aliados','Integraciones','Logs','Configuración'] as $label) {
                add_submenu_page('ascla',$label,$label,$caps[$label]??'ascla_moderate','ascla-'.sanitize_title($label),[self::class,'render']);
            }
        });
        add_action('admin_enqueue_scripts',static function ($hook) {
            if (str_contains($hook,'ascla')) { \ASCLA\Core\Frontend\App::assets('admin'); }
        });
        add_action('admin_head',static function () {
            $screen=get_current_screen();
            if ($screen && str_contains((string)$screen->id,'ascla')) { \ASCLA\Core\Frontend\Theme::printScript(); }
        },0);
    }
    public static function render(): void {
        if(!\ASCLA\Core\Services\Access::member() || !current_user_can('ascla_admin_area')){wp_die('Acceso no autorizado.','ASCLA',['response'=>403]);}
        echo '<div id="ascla-root" class="ascla-admin"><div class="loading">Cargando ASCLA…</div></div>';
    }
}
