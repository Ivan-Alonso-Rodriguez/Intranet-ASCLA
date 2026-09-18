<?php
namespace ASCLA\Core\Database;
use ASCLA\Core\Domain\Catalog;

final class Installer
{
    public const SCHEMA_VERSION=13;
    public static function activate(bool $networkWide=false): void
    {
        if ($networkWide) { wp_die('Active ASCLA Core individualmente en cada sitio; no se admite activación de red.'); }
        Catalog::register();
        \ASCLA\Core\Domain\Roles::sync();
        self::migrate();
        self::migrateIndexes();
        self::migrateDiscovery();
        self::migrateNotificationContext();
        self::migrateReportReasons();
        self::migrateMediaMasters();
        self::migrateReportReviewState();
        self::migrateConversationGroups();
        self::migrateConversationPhotos();
        self::migrateConversationDescriptions();
        self::migrateTemporaryMedia();
        self::migrateAttendance();
        self::migrateImports();
        self::loginPage();
        self::pages();
        self::adminPage();
        self::terms();
        if (!wp_next_scheduled('ascla_jobs')) { wp_schedule_event(time()+60, 'hourly', 'ascla_jobs'); }
        if (!wp_next_scheduled('ascla_monthly')) { wp_schedule_event(time()+120, 'daily', 'ascla_monthly'); }
        if (!wp_next_scheduled('ascla_discovery')) { wp_schedule_event(time()+300,'daily','ascla_discovery'); }
        update_option('ascla_version', ASCLA_VERSION, false);
        flush_rewrite_rules();
    }
    public static function upgrade(): void
    {
        if (get_option('ascla_version') !== ASCLA_VERSION || (int)get_option('ascla_schema',0)<self::SCHEMA_VERSION) { self::activate(false); }
        \ASCLA\Core\Domain\Roles::upgrade();
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
    private static function migrateReportReasons(): void
    {
        if ((int)get_option('ascla_schema',0)>=5) { return; }
        global $wpdb; $table=$wpdb->prefix.'ascla_relations';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'reason'))) {
            $wpdb->query("ALTER TABLE $table ADD reason varchar(64) NOT NULL DEFAULT ''");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'detail'))) {
            $wpdb->query("ALTER TABLE $table ADD detail text NULL");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'reason')) || !$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'detail'))) {
            throw new MigrationException('No se pudo actualizar el registro de reportes.');
        }
        update_option('ascla_schema',5,false);
    }
    private static function migrateMediaMasters(): void
    {
        if ((int)get_option('ascla_schema',0)>=6) { return; }
        global $wpdb; $table=$wpdb->prefix.'ascla_media';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'original_id'))) {
            $wpdb->query("ALTER TABLE $table ADD original_id bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY original_id (original_id)");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'original_id'))) {
            throw new MigrationException('No se pudo actualizar el almacenamiento de imágenes.');
        }
        update_option('ascla_schema',6,false);
    }
    private static function migrateReportReviewState(): void
    {
        if ((int)get_option('ascla_schema',0)>=7) { return; }
        global $wpdb; $table=$wpdb->prefix.'ascla_relations';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'reviewed_at'))) {
            $wpdb->query("ALTER TABLE $table ADD reviewed_at datetime DEFAULT NULL, ADD reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'reviewed_at')) || !$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'reviewed_by'))) {
            throw new MigrationException('No se pudo actualizar el estado de revisión de reportes.');
        }
        update_option('ascla_schema',7,false);
    }

    private static function migrateConversationGroups(): void
    {
        if ((int)get_option('ascla_schema',0)>=8) { return; }
        global $wpdb; $table=$wpdb->prefix.'ascla_conversations';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'kind'))) {
            $wpdb->query("ALTER TABLE $table ADD kind varchar(16) NOT NULL DEFAULT 'direct' AFTER pair_key");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'title'))) {
            $wpdb->query("ALTER TABLE $table ADD title varchar(160) NOT NULL DEFAULT '' AFTER kind");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'created_by'))) {
            $wpdb->query("ALTER TABLE $table ADD created_by bigint(20) unsigned NOT NULL DEFAULT 0 AFTER title");
        }
        foreach (['kind','title','created_by'] as $column) {
            if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",$column))) {
                throw new MigrationException('No se pudo actualizar el esquema de conversaciones.');
            }
        }
        $wpdb->query("UPDATE $table SET kind='direct' WHERE kind='' OR kind IS NULL");
        update_option('ascla_schema',8,false);
    }

    private static function migrateConversationPhotos(): void
    {
        if ((int)get_option('ascla_schema',0)>=9) { return; }
        global $wpdb; $table=$wpdb->prefix.'ascla_conversations';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'photo_id'))) {
            $wpdb->query("ALTER TABLE $table ADD photo_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER created_by, ADD KEY photo_id (photo_id)");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'photo_id'))) {
            throw new MigrationException('No se pudo añadir la fotografía de los grupos.');
        }
        update_option('ascla_schema',9,false);
    }

    private static function migrateConversationDescriptions(): void
    {
        if ((int)get_option('ascla_schema',0)>=10) { return; }
        global $wpdb; $table=$wpdb->prefix.'ascla_conversations';
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'description'))) {
            $wpdb->query("ALTER TABLE $table ADD description varchar(240) NOT NULL DEFAULT '' AFTER title");
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",'description'))) {
            throw new MigrationException('No se pudo añadir la descripción de los grupos.');
        }
        update_option('ascla_schema',10,false);
    }

    /** Preserve saved files while allowing the -1 marker used by uncommitted uploads. */
    private static function migrateTemporaryMedia(): void
    {
        if((int)get_option('ascla_schema',0)>=11){return;}
        global $wpdb;$table=$wpdb->prefix.'ascla_media';
        if((int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE post_id>9223372036854775807")>0){
            throw new MigrationException('Hay referencias de archivos fuera del rango admitido. No se modificaron los datos.');
        }
        $column=$wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'post_id'",ARRAY_A);
        if(!$column){throw new MigrationException('No se encontró la referencia de archivos.');}
        if(str_contains(strtolower($column['Type']),'unsigned')){
            if($wpdb->query("ALTER TABLE $table MODIFY post_id bigint(20) NOT NULL DEFAULT 0")===false){
                throw new MigrationException('No se pudo habilitar el almacenamiento temporal de archivos.');
            }
        }
        update_option('ascla_schema',11,false);
    }
    private static function migrateAttendance(): void
    {
        if((int)get_option('ascla_schema',0)>=12) return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=$wpdb->prefix.'ascla_attendance';$collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(16) NOT NULL,
            minutes int(11) unsigned DEFAULT NULL,
            source varchar(16) NOT NULL,
            recorded_by bigint(20) unsigned NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY attendance (event_id,user_id),
            KEY user_event (user_id,event_id)
        ) ENGINE=InnoDB $collate;");
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table) {
            throw new MigrationException('No se pudo crear el registro de asistencia.');
        }
        update_option('ascla_schema',12,false);
    }
    private static function migrateImports(): void
    {
        if((int)get_option('ascla_schema',0)>=13)return;
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$p=$wpdb->prefix.'ascla_';$collate=$wpdb->get_charset_collate();
        $schemas=[
            'imports'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nkind varchar(20) NOT NULL,\nactor_id bigint(20) unsigned NOT NULL,\nevent_id bigint(20) unsigned NOT NULL DEFAULT 0,\nfilename varchar(255) NOT NULL,\nstatus varchar(20) NOT NULL,\ntotal int unsigned NOT NULL DEFAULT 0,\nconfig longtext NOT NULL,\ncreated_at datetime NOT NULL,\ncompleted_at datetime DEFAULT NULL,\nPRIMARY KEY  (id),\nKEY kind_date (kind,created_at)",
            'import_rows'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nimport_id bigint(20) unsigned NOT NULL,\nposition int unsigned NOT NULL,\nstate varchar(20) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL DEFAULT 0,\npayload longtext NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY import_position (import_id,position),\nKEY batch (import_id,state,id)",
            'user_interests'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\nterm_id bigint(20) unsigned NOT NULL,\nsource varchar(16) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY interest (user_id,term_id,source),\nKEY topic_source (term_id,source)"
        ];
        foreach($schemas as $name=>$schema){dbDelta("CREATE TABLE {$p}{$name} (\n$schema\n) ENGINE=InnoDB $collate;");if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($p.$name)))!==$p.$name)throw new MigrationException('No se pudo crear el registro de importaciones.');}
        $columns=['seconds'=>'int unsigned DEFAULT NULL','sessions'=>'longtext DEFAULT NULL','review_reason'=>"varchar(255) NOT NULL DEFAULT ''"];
        foreach($columns as $column=>$type)if(!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$p}attendance LIKE %s",$column)) && $wpdb->query("ALTER TABLE {$p}attendance ADD $column $type")===false)throw new MigrationException('No se pudo ampliar el registro de asistencia.');
        update_option('ascla_schema',13,false);
        update_option('ascla_interest_index_cursor',0,false);
        if(!wp_next_scheduled('ascla_interest_index'))wp_schedule_single_event(time()+1,'ascla_interest_index');
    }
    private static function migrate(): void
    {
        if ((int)get_option('ascla_schema',0)>=1) { return; }
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $p=$wpdb->prefix.'ascla_'; $collate=$wpdb->get_charset_collate();
        $tables=[
            'conversations'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\npair_key varchar(100) NOT NULL,\nkind varchar(16) NOT NULL DEFAULT 'direct',\ntitle varchar(160) NOT NULL DEFAULT '',\ndescription varchar(240) NOT NULL DEFAULT '',\ncreated_by bigint(20) unsigned NOT NULL DEFAULT 0,\nphoto_id bigint(20) unsigned NOT NULL DEFAULT 0,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY pair_key (pair_key),\nKEY photo_id (photo_id)",
            'participants'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nconversation_id bigint(20) unsigned NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\nlast_read bigint(20) unsigned NOT NULL DEFAULT 0,\nPRIMARY KEY  (id),\nUNIQUE KEY member (conversation_id,user_id),\nKEY user_id (user_id)",
            'messages'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nconversation_id bigint(20) unsigned NOT NULL,\nsender_id bigint(20) unsigned NOT NULL,\nbody text NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY conversation_id (conversation_id,id)",
            'relations'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\ntarget_id bigint(20) unsigned NOT NULL,\nkind varchar(24) NOT NULL,\nreason varchar(64) NOT NULL DEFAULT '',\ndetail text NULL,\nreviewed_at datetime DEFAULT NULL,\nreviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY relation (user_id,target_id,kind),\nKEY target_kind (target_id,kind)",
            'registrations'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nevent_id bigint(20) unsigned NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\nstatus varchar(24) NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY registration (event_id,user_id),\nKEY event_status (event_id,status)",
            'notifications'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\nkind varchar(32) NOT NULL,\nlabel varchar(255) NOT NULL,\nurl text NOT NULL,\nread_at datetime DEFAULT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY user_unread (user_id,read_at,id)",
            'audit'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nactor_id bigint(20) unsigned NOT NULL,\naction varchar(64) NOT NULL,\nobject_id bigint(20) unsigned NOT NULL DEFAULT 0,\ndetail varchar(255) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY action_date (action,created_at)",
            'jobs'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nkind varchar(32) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL,\npayload longtext NOT NULL,\nstatus varchar(24) NOT NULL,\nattempts int(11) NOT NULL DEFAULT 0,\nresult longtext DEFAULT NULL,\nerror varchar(255) DEFAULT NULL,\nlocked_at datetime DEFAULT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY queue (status,id),\nKEY owner (user_id,id)",
            'media'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\npost_id bigint(20) NOT NULL DEFAULT 0,\noriginal_id bigint(20) unsigned NOT NULL DEFAULT 0,\nname varchar(255) NOT NULL,\nmime varchar(80) NOT NULL,\nbytes longblob NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY owner (user_id),\nKEY post_id (post_id),\nKEY original_id (original_id)",
        ];
        foreach ($tables as $name=>$schema) {
            dbDelta("CREATE TABLE {$p}{$name} (\n{$schema}\n) ENGINE=InnoDB {$collate};");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($p.$name))) !== $p.$name) {
                throw new \RuntimeException('No se pudo crear el esquema ASCLA. Revise permisos de base de datos.');
            }
        }
        update_option('ascla_schema',1,false);
    }
    private static function loginPage(): void
    {
        $id=(int)get_option('ascla_login_page',0);
        if($id>0 && get_post_status($id) && get_post_status($id)!=='trash'){ return; }

        $owned=get_posts([
            'post_type'=>'page',
            'post_status'=>['publish','draft','private'],
            'meta_key'=>'_ascla_login_page',
            'meta_value'=>'1',
            'numberposts'=>1,
        ]);
        if($owned){ update_option('ascla_login_page',(int)$owned[0]->ID,false); return; }

        $existing=get_page_by_path('login',OBJECT,'page');
        if($existing && get_post_status($existing->ID)!=='trash'){
            // Never overwrite an unrelated page. Reuse it only if it is empty and clearly safe to claim.
            $content=trim((string)$existing->post_content);
            if($content==='' && !get_post_meta($existing->ID,'_ascla_page',true)){
                update_post_meta($existing->ID,'_ascla_login_page','1');
                update_option('ascla_login_page',(int)$existing->ID,false);
                return;
            }
        }

        $id=wp_insert_post([
            'post_type'=>'page',
            'post_title'=>'Acceso ASCLA',
            'post_name'=>'login',
            'post_status'=>'publish',
            'post_content'=>'',
        ],true);
        if(is_wp_error($id)){ throw new \RuntimeException('No se pudo crear la página de acceso ASCLA.'); }
        update_post_meta($id,'_ascla_login_page','1');
        update_option('ascla_login_page',(int)$id,false);
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
    private static function adminPage(): void
    {
        $id=(int)get_option('ascla_admin_front_page',0);
        if($id>0 && get_post_status($id) && get_post_status($id)!=='trash'){ return; }

        $owned=get_posts([
            'post_type'=>'page',
            'post_status'=>['publish','draft','private'],
            'meta_key'=>'_ascla_page',
            'meta_value'=>'admin',
            'numberposts'=>1,
        ]);
        if($owned){ update_option('ascla_admin_front_page',(int)$owned[0]->ID,false); return; }

        $existing=get_page_by_path('administracion',OBJECT,'page');
        if($existing && get_post_status($existing->ID)!=='trash'){
            $current=(string)get_post_meta($existing->ID,'_ascla_page',true);
            if($current==='' && trim((string)$existing->post_content)===''){
                wp_update_post(['ID'=>$existing->ID,'post_content'=>'[ascla_app page="admin"]']);
                update_post_meta($existing->ID,'_ascla_page','admin');
                update_option('ascla_admin_front_page',(int)$existing->ID,false);
                return;
            }
        }

        $id=wp_insert_post([
            'post_type'=>'page',
            'post_title'=>'Administración ASCLA',
            'post_name'=>'administracion',
            'post_status'=>'publish',
            'post_content'=>'[ascla_app page="admin"]',
        ],true);
        if(is_wp_error($id)){ throw new \RuntimeException('No se pudo crear la página de administración ASCLA.'); }
        update_post_meta($id,'_ascla_page','admin');
        update_option('ascla_admin_front_page',(int)$id,false);
    }

    private static function terms(): void
    {
        $terms=['interest'=>['Gobierno corporativo','Inteligencia artificial','Juntas directivas','Sostenibilidad','Transformación digital','Gestión de riesgos'], 'area'=>['Secretaría corporativa','Cumplimiento','Estrategia','Gobierno de IA'], 'industry'=>['Servicios financieros','Energía','Tecnología','Industria','Consultoría'], 'goal'=>['Compartir experiencia','Aprender','Colaborar'], 'language'=>['Español','Inglés','Portugués'], 'category'=>['Gobernanza','Capacitación','Comunidad','Normativa']];
        foreach ($terms as $taxonomy=>$names) { foreach ($names as $name) { if (!term_exists($name,'ascla_'.$taxonomy)) { wp_insert_term($name,'ascla_'.$taxonomy); } } }
    }
}
