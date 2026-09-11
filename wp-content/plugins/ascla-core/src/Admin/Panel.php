<?php
namespace ASCLA\Core\Admin;
final class Panel
{
    public static function boot(): void
    {
        add_action('admin_menu',static function () {
            add_menu_page('ASCLA','ASCLA','ascla_moderate','ascla',[self::class,'render'],'dashicons-groups',3);
            foreach (['Dashboard','Miembros','Hub','Moderación','Eventos','Microeventos','Solicitudes','Conocimiento','IA','Networking','Galería','Aliados','Integraciones','Logs','Configuración'] as $label) {
                add_submenu_page('ascla',$label,$label,in_array($label,['Miembros','Integraciones','Configuración'],true)?'ascla_manage':'ascla_moderate','ascla-'.sanitize_title($label),[self::class,'render']);
            }
        });
        add_action('admin_enqueue_scripts',static function ($hook) {
            if (str_contains($hook,'ascla')) { \ASCLA\Core\Frontend\App::assets('admin'); }
        });
    }
    public static function render(): void { echo '<div id="ascla-root" class="ascla-admin"><div class="loading">Cargando ASCLA…</div></div>'; }
}
