<?php
namespace ASCLA\Core\Database;
use ASCLA\Core\Domain\Catalog;

final class Installer
{
    public static function activate(bool $networkWide=false): void
    {
        if ($networkWide) { wp_die('Active ASCLA Core individualmente en cada sitio; no se admite activación de red.'); }
        Catalog::register();
        self::roles();
        self::migrate();
        self::migrateIndexes();
        self::migrateDiscovery();
        self::migrateNotificationContext();
        self::pages();
        self::terms();
        if (!wp_next_scheduled('ascla_jobs')) { wp_schedule_event(time()+60, 'hourly', 'ascla_jobs'); }
        if (!wp_next_scheduled('ascla_monthly')) { wp_schedule_event(time()+120, 'daily', 'ascla_monthly'); }
        if (!wp_next_scheduled('ascla_discovery')) { wp_schedule_event(time()+300,'daily','ascla_discovery'); }
        update_option('ascla_version', ASCLA_VERSION, false);
        flush_rewrite_rules();
    }
    public static function upgrade(): void
    {
        if (get_option('ascla_version') !== ASCLA_VERSION) { self::activate(false); }
    }
    private static function migrateIndexes(): void
    {
        if ((int)get_option('ascla_schema',0)>=2) { return; }
        $page=1;
        do {
            $posts=get_posts(['post_type'=>array_map(static fn($type)=>'ascla_'.$type,array_keys(Catalog::TYPES)),'post_status'=>['publish','draft','pending','private','ascla_hidden','ascla_rejected'],'numberposts'=>200,'paged'=>$page++]);
            foreach ($posts as $post) { \ASCLA\Core\Services\Content::indexMeta(0,$post->ID,'_ascla',(array)get_post_meta($post->ID,'_ascla',true)); }
        } while (count($posts)===200);
        update_option('ascla_schema',2,false);
    }
    private static function migrateDiscovery(): void
    {
        if ((int)get_option('ascla_schema',0)>=3) { return; }
        global $wpdb;
        $table=$wpdb->prefix.'ascla_notifications';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'event_key'))) {
            $wpdb->query("ALTER TABLE $table ADD event_key varchar(96) DEFAULT NULL, ADD UNIQUE KEY delivery (user_id,event_key)");
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'event_key'))) { throw new \RuntimeException('No se pudo actualizar el índice de notificaciones.'); }
        }
        $page=1;
        do {
            $posts=get_posts(['post_type'=>'ascla_resource','post_status'=>['publish','draft','pending','ascla_hidden','ascla_rejected'],'numberposts'=>200,'paged'=>$page++]);
            foreach ($posts as $post) {
                $meta=(array)get_post_meta($post->ID,'_ascla',true);
                \ASCLA\Core\Services\Content::indexMeta(0,$post->ID,'_ascla',$meta);
                if ($post->post_status==='publish' && !empty($meta['reviewed'])) { \ASCLA\Core\Services\Content::tags($post->ID,(array)($meta['tags']??[])); }
            }
        } while (count($posts)===200);
        update_option('ascla_schema',3,false);
    }
    private static function migrateNotificationContext(): void
    {
        if ((int)get_option('ascla_schema',0)>=4) { return; }
        global $wpdb; $table=$wpdb->prefix.'ascla_notifications';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'context'))) {
            $wpdb->query("ALTER TABLE $table ADD context longtext DEFAULT NULL");
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'context'))) { throw new MigrationException('No se pudo actualizar el contexto de notificaciones.'); }
        }
        update_option('ascla_schema',4,false);
    }
    private static function roles(): void
    {
        $base=['read'=>true,'ascla_access'=>true,'ascla_write'=>true];
        add_role('ascla_member', 'Asociado ASCLA', $base);
        add_role('ascla_moderator','Moderador ASCLA', $base+['ascla_moderate'=>true]);
        foreach (['ascla_member'=>$base, 'ascla_moderator'=>$base+['ascla_moderate'=>true], 'administrator'=>$base+['ascla_moderate'=>true,'ascla_manage'=>true]] as $name=>$caps) {
            $role=get_role($name);
            if ($role) { foreach ($caps as $cap=>$grant) { $role->add_cap($cap); } }
        }
    }
    private static function migrate(): void
    {
        if ((int)get_option('ascla_schema',0)>=1) { return; }
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $p=$wpdb->prefix.'ascla_'; $collate=$wpdb->get_charset_collate();
        $tables=[
            'conversations'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\npair_key varchar(100) NOT NULL,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY pair_key (pair_key)",
            'participants'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nconversation_id bigint(20) unsigned NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\nlast_read bigint(20) unsigned NOT NULL DEFAULT 0,\nPRIMARY KEY  (id),\nUNIQUE KEY member (conversation_id,user_id),\nKEY user_id (user_id)",
            'messages'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nconversation_id bigint(20) unsigned NOT NULL,\nsender_id bigint(20) unsigned NOT NULL,\nbody text NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY conversation_id (conversation_id,id)",
            'relations'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\ntarget_id bigint(20) unsigned NOT NULL,\nkind varchar(24) NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY relation (user_id,target_id,kind),\nKEY target_kind (target_id,kind)",
            'registrations'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nevent_id bigint(20) unsigned NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\nstatus varchar(24) NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY registration (event_id,user_id),\nKEY event_status (event_id,status)",
            'notifications'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\nkind varchar(32) NOT NULL,\nlabel varchar(255) NOT NULL,\nurl text NOT NULL,\nread_at datetime DEFAULT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY user_unread (user_id,read_at,id)",
            'audit'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nactor_id bigint(20) unsigned NOT NULL,\naction varchar(64) NOT NULL,\nobject_id bigint(20) unsigned NOT NULL DEFAULT 0,\ndetail varchar(255) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY action_date (action,created_at)",
            'jobs'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nkind varchar(32) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\npayload longtext NOT NULL,\nstatus varchar(24) NOT NULL,\nattempts int(11) NOT NULL DEFAULT 0,\nresult longtext DEFAULT NULL,\nerror varchar(255) DEFAULT NULL,\nlocked_at datetime DEFAULT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY queue (status,id),\nKEY owner (user_id,id)",
            'media'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\npost_id bigint(20) unsigned NOT NULL DEFAULT 0,\nname varchar(255) NOT NULL,\nmime varchar(80) NOT NULL,\nbytes longblob NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY owner (user_id),\nKEY post_id (post_id)",
        ];
        foreach ($tables as $name=>$schema) {
            dbDelta("CREATE TABLE {$p}{$name} (\n{$schema}\n) ENGINE=InnoDB {$collate};");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($p.$name))) !== $p.$name) {
                throw new \RuntimeException('No se pudo crear el esquema ASCLA. Revise permisos de base de datos.');
            }
        }
        update_option('ascla_schema',1,false);
    }
    private static function pages(): void
    {
        $ids=get_option('ascla_pages',[]);
        foreach (Catalog::PAGES as $slug=>$title) {
            if (!empty($ids[$slug]) && get_post_status($ids[$slug]) && get_post_status($ids[$slug])!=='trash') { continue; }
            $owned=get_posts(['post_type'=>'page','post_status'=>['publish','draft','private'],'meta_key'=>'_ascla_page','meta_value'=>$slug,'numberposts'=>1]);
            if ($owned) { $ids[$slug]=$owned[0]->ID; continue; }
            $id=wp_insert_post(['post_type'=>'page','post_title'=>$title,'post_name'=>$slug,'post_status'=>'publish','post_content'=>'[ascla_app page="'.$slug.'"]'],true);
            if (is_wp_error($id)) { throw new \RuntimeException('No se pudo crear la página '.$title); }
            update_post_meta($id,'_ascla_page',$slug); $ids[$slug]=$id;
        }
        update_option('ascla_pages',$ids,false);
    }
    private static function terms(): void
    {
        $terms=['interest'=>['Gobierno corporativo','Inteligencia artificial','Juntas directivas','Sostenibilidad','Transformación digital','Gestión de riesgos'], 'area'=>['Secretaría corporativa','Cumplimiento','Estrategia','Gobierno de IA'], 'industry'=>['Servicios financieros','Energía','Tecnología','Industria','Consultoría'], 'goal'=>['Compartir experiencia','Aprender','Colaborar'], 'language'=>['Español','Inglés','Portugués'], 'category'=>['Gobernanza','Capacitación','Comunidad','Normativa']];
        foreach ($terms as $taxonomy=>$names) { foreach ($names as $name) { if (!term_exists($name,'ascla_'.$taxonomy)) { wp_insert_term($name,'ascla_'.$taxonomy); } } }
    }
}
