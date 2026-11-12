<?php
/**
 * Plugin Name: ASCLA QA Tools
 * Description: Prepara y limpia cuentas temporales para Bruno y Playwright en el sitio de pruebas.
 * Version: 1.0.0
 * Requires PHP: 8.2
 */
if (!defined('ABSPATH')) { exit; }

final class ASCLA_QA_Tools
{
    private const OPTION='ascla_qa_tools_run';
    public static function boot(): void
    {
        add_action('admin_menu',static fn()=>add_management_page('ASCLA QA','ASCLA QA','manage_options','ascla-qa-tools',[self::class,'page']));
        add_action('admin_post_ascla_qa_tools_setup',[self::class,'setup']);
        add_action('admin_post_ascla_qa_tools_cleanup',[self::class,'cleanup']);
    }
    private static function ready(): bool
    {
        $host=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
        $local=in_array($host,['localhost','127.0.0.1'],true) || (wp_get_environment_type()==='local' && preg_match('/^ascla[_-][a-z0-9_-]+$/',$host));
        return defined('ASCLA_VERSION') && ASCLA_VERSION==='1.11.0' && class_exists(ASCLA\Core\Services\Profiles::class)
            && ($host==='wordpress.ingsoftware.lat' || $local);
    }
    private static function authorize(string $action): void
    {
        if (!current_user_can('manage_options')) { wp_die('Acceso reservado a administración.',403); }
        check_admin_referer('ascla_qa_tools_'.$action);
        if (!self::ready()) { wp_die('Requiere ASCLA Core 1.11.0 y el sitio de pruebas configurado.',400); }
    }
    private static function execute(string $run,string $action): string
    {
        define('ASCLA_QA_WEB_SETUP',true);define('ASCLA_QA_RUN',$run);define('ASCLA_QA_ACTION',$action);
        $actor=get_current_user_id();ob_start();
        try { require __DIR__.'/lib/acceptance-fixture.php';return (string)ob_get_clean(); }
        catch (Throwable $error) { ob_end_clean();throw $error; }
        finally { wp_set_current_user($actor); }
    }
    public static function setup(): void
    {
        self::authorize('setup');
        if (($_POST['qa_confirm']??'')!=='1' || get_option(self::OPTION) || get_option('ascla_acceptance_fixture')) {
            wp_die('Confirma el entorno de pruebas y limpia cualquier lote anterior antes de crear otro.',400);
        }
        $run='web_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(6));
        if (!add_option(self::OPTION,['run'=>$run,'created_at'=>gmdate('c'),'creator'=>get_current_user_id()],'','no')) { wp_die('Ya existe un lote activo.',409); }
        try {
            $fixture=json_decode(self::execute($run,'setup'),true,512,JSON_THROW_ON_ERROR);
            $fixture+=['run'=>$run,'origin'=>'ascla-qa-tools','core_version'=>ASCLA_VERSION,'issued_at'=>gmdate('c')];
            nocache_headers();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="ascla-qa-access.json"');
            echo wp_json_encode($fixture,JSON_PRETTY_PRINT);exit;
        } catch (Throwable $error) { wp_die('No se pudo preparar el lote. Usa Limpiar lote antes de intentarlo otra vez.',500); }
    }
    public static function cleanup(): void
    {
        self::authorize('cleanup');$state=get_option(self::OPTION);
        $run=is_array($state)?($state['run']??''):'';
        if (!$run || !hash_equals($run,(string)($_POST['run']??''))) { wp_die('El lote solicitado no coincide con el activo.',409); }
        $journal=get_option('ascla_acceptance_fixture');
        if ($journal && ($journal['run']??'')!==$run) { wp_die('El lote de datos pertenece a otra ejecución.',409); }
        try { self::execute($run,'cleanup');delete_option(self::OPTION); }
        catch (Throwable $error) { wp_die('La limpieza no se completó. Conserva el complemento y vuelve a intentarlo.',500); }
        if (($_POST['format']??'')==='json') { wp_send_json(['cleaned'=>true,'run'=>$run]); }
        wp_safe_redirect(admin_url('tools.php?page=ascla-qa-tools&cleaned=1'));exit;
    }
    public static function page(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Acceso reservado a administración.',403); }
        echo '<div class="wrap"><h1>ASCLA QA · Bruno y Playwright</h1><p>Pruebas sobre <strong>'.esc_html(home_url()).'</strong></p>';
        if (!self::ready()) { echo '<div class="notice notice-error inline"><p>Instala primero ASCLA Core 1.11.0 en wordpress.ingsoftware.lat o en un local de pruebas.</p></div></div>';return; }
        $state=get_option(self::OPTION);
        if (isset($_GET['cleaned'])) { echo '<div class="notice notice-success inline"><p>Lote temporal eliminado.</p></div>'; }
        if ($state) {
            echo '<h2>Lote activo</h2><p>Solo se eliminarán los usuarios sintéticos y los datos de este lote: <code>'.esc_html($state['run']).'</code>.</p><form data-ascla-qa-cleanup method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ascla_qa_tools_cleanup');
            echo '<input type="hidden" name="action" value="ascla_qa_tools_cleanup"><input type="hidden" name="run" value="'.esc_attr($state['run']).'">';
            submit_button('Limpiar lote temporal','secondary');echo '</form>';
        } else {
            echo '<p>Crea cinco cuentas temporales. Se descargarán sus credenciales en un archivo privado para ejecutar las pruebas desde tu equipo.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ascla_qa_tools_setup');
            echo '<input type="hidden" name="action" value="ascla_qa_tools_setup"><label><input type="checkbox" name="qa_confirm" value="1" required> Este sitio es un entorno de pruebas.</label>';
            submit_button('Crear lote y descargar acceso');echo '</form>';
        }
        echo '<h2>Al terminar</h2><p>El ejecutor remoto intenta limpiar el lote automáticamente. También puedes hacerlo aquí con tu administrador habitual. Después, desactiva y elimina ASCLA QA Tools.</p></div>';
    }
}
ASCLA_QA_Tools::boot();
