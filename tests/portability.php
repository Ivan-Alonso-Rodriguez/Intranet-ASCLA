<?php
// Run by WP-CLI in the dedicated ascla-portability environment only.
if (home_url()!=='http://localhost:8089') { WP_CLI::error('Dedicated local installation required'); }
if (($args[0]??'')==='before') {
    update_option('ascla_portability_previous_plugin',get_option('ascla_version','none'));
    if (!get_option('ascla_portability_foreign_page')) {
        $id=wp_insert_post(['post_type'=>'page','post_name'=>'intranet','post_title'=>'Página previa','post_content'=>'CONTENIDO_PREVIO_NO_SOBRESCRIBIR','post_status'=>'publish']);
        update_option('ascla_portability_foreign_page',$id);
    }
    WP_CLI::success('Prepared unrelated page to test slug collision.');return;
}
$pages=get_option('ascla_pages',[]);$foreign=(int)get_option('ascla_portability_foreign_page');
ASCLA\Core\Database\Installer::activate(false);
ASCLA\Core\Database\Installer::activate(false);
$checks=[
    'twelve_pages'=>count($pages)===12,
    'idempotent_pages'=>$pages===get_option('ascla_pages'),
    'foreign_content_preserved'=>get_post_field('post_content',$foreign)==='CONTENIDO_PREVIO_NO_SOBRESCRIBIR',
    'slug_collision_isolated'=>($pages['intranet']??0)!==$foreign,
    'schema_version'=>get_option('ascla_schema')==6,
    'member_role'=>(bool)get_role('ascla_member'),
    'private_cpt'=>!get_post_type_object('ascla_resource')->public && !get_post_type_object('ascla_resource')->show_in_rest,
];
global $wpdb;$tables=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'ascla_').'%'));$checks['nine_tables']=count($tables)===9;
foreach($checks as $name=>$passed)if(!$passed)WP_CLI::error('Portability failure: '.$name);
echo wp_json_encode(['zip_sha256'=>hash_file('sha256','/dist/ascla-core.zip'),'installation'=>get_option('ascla_portability_previous_plugin')==='none'?'fresh':'update','previous_plugin'=>get_option('ascla_portability_previous_plugin'),'wordpress'=>$GLOBALS['wp_version'],'plugin'=>ASCLA_VERSION,'php'=>PHP_VERSION,'checks'=>$checks,'intranet'=>ASCLA\Core\Domain\Catalog::url('intranet')],JSON_PRETTY_PRINT)."\n";
