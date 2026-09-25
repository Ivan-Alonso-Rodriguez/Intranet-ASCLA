<?php
namespace ASCLA\Core\Database;
use ASCLA\Core\Domain\Catalog;

final class Installer
{
    private const UPGRADE_INCLUDE='wp-admin/includes/upgrade.php';
    private const TABLE_EXISTS_SQL='SHOW TABLES LIKE %s';
    public const SCHEMA_VERSION=13;
    public static function activate(bool $networkWide=false): void
    {
        if ($networkWide) { wp_die('Active ASCLA Core individualmente en cada sitio; no se admite activación de red.'); }
        Catalog::register();
        \ASCLA\Core\Domain\Roles::sync();
        self::migrate();
        self::migrateIndexes();
        self::migrateDiscovery();
        self::migrateLegacyColumns();
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
        MigrationSchema::ensureColumn($table,'event_key','event_key varchar(96) DEFAULT NULL, ADD UNIQUE KEY delivery (user_id,event_key)','No se pudo actualizar el índice de notificaciones.');
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
    private static function migrateLegacyColumns(): void
    {
        global $wpdb;
        $migrations=[
            4=>[
                'table'=>'notifications',
                'columns'=>['context'=>'context longtext DEFAULT NULL'],
                'error'=>'No se pudo actualizar el contexto de notificaciones.',
            ],
            5=>[
                'table'=>'relations',
                'columns'=>['reason'=>"reason varchar(64) NOT NULL DEFAULT ''",'detail'=>'detail text NULL'],
                'error'=>'No se pudo actualizar el registro de reportes.',
            ],
            6=>[
                'table'=>'media',
                'columns'=>['original_id'=>'original_id bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY original_id (original_id)'],
                'error'=>'No se pudo actualizar el almacenamiento de imágenes.',
            ],
            7=>[
                'table'=>'relations',
                'columns'=>['reviewed_at'=>'reviewed_at datetime DEFAULT NULL','reviewed_by'=>'reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0'],
                'error'=>'No se pudo actualizar el estado de revisión de reportes.',
            ],
            8=>[
                'table'=>'conversations',
                'columns'=>[
                    'kind'=>"kind varchar(16) NOT NULL DEFAULT 'direct' AFTER pair_key",
                    'title'=>"title varchar(160) NOT NULL DEFAULT '' AFTER kind",
                    'created_by'=>'created_by bigint(20) unsigned NOT NULL DEFAULT 0 AFTER title',
                ],
                'error'=>'No se pudo actualizar el esquema de conversaciones.',
                'after'=>"SET kind='direct' WHERE kind='' OR kind IS NULL",
            ],
            9=>[
                'table'=>'conversations',
                'columns'=>['photo_id'=>'photo_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER created_by, ADD KEY photo_id (photo_id)'],
                'error'=>'No se pudo añadir la fotografía de los grupos.',
            ],
            10=>[
                'table'=>'conversations',
                'columns'=>['description'=>"description varchar(240) NOT NULL DEFAULT '' AFTER title"],
                'error'=>'No se pudo añadir la descripción de los grupos.',
            ],
        ];
        $current=(int)get_option('ascla_schema',0);
        foreach ($migrations as $version=>$migration) {
            if ($current>=$version) { continue; }
            $table=$wpdb->prefix.'ascla_'.$migration['table'];
            foreach ($migration['columns'] as $column=>$definition) {
                MigrationSchema::ensureColumn($table,$column,$definition,$migration['error']);
            }
            if (!empty($migration['after'])) { $wpdb->query('UPDATE '.$table.' '.$migration['after']); }
            update_option('ascla_schema',$version,false);
            $current=$version;
        }
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
        if(
            str_contains(strtolower($column['Type']),'unsigned')
            && $wpdb->query("ALTER TABLE $table MODIFY post_id bigint(20) NOT NULL DEFAULT 0")===false
        ){
            throw new MigrationException('No se pudo habilitar el almacenamiento temporal de archivos.');
        }
        update_option('ascla_schema',11,false);
    }
    private static function migrateAttendance(): void
    {
        if((int)get_option('ascla_schema',0)>=12) { return; }
        global $wpdb;
        require_once ABSPATH.self::UPGRADE_INCLUDE;
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
        if($wpdb->get_var($wpdb->prepare(self::TABLE_EXISTS_SQL,$wpdb->esc_like($table)))!==$table) {
            throw new MigrationException('No se pudo crear el registro de asistencia.');
        }
        update_option('ascla_schema',12,false);
    }
    private static function migrateImports(): void
    {
        if((int)get_option('ascla_schema',0)>=13) {return; }
        global $wpdb;require_once ABSPATH.self::UPGRADE_INCLUDE;$p=$wpdb->prefix.'ascla_';$collate=$wpdb->get_charset_collate();
        $schemas=[
            'imports'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nkind varchar(20) NOT NULL,\nactor_id bigint(20) unsigned NOT NULL,\nevent_id bigint(20) unsigned NOT NULL DEFAULT 0,\nfilename varchar(255) NOT NULL,\nstatus varchar(20) NOT NULL,\ntotal int unsigned NOT NULL DEFAULT 0,\nconfig longtext NOT NULL,\ncreated_at datetime NOT NULL,\ncompleted_at datetime DEFAULT NULL,\nPRIMARY KEY  (id),\nKEY kind_date (kind,created_at)",
            'import_rows'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nimport_id bigint(20) unsigned NOT NULL,\nposition int unsigned NOT NULL,\nstate varchar(20) NOT NULL,\nuser_id bigint(20) unsigned NOT NULL DEFAULT 0,\npayload longtext NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY import_position (import_id,position),\nKEY batch (import_id,state,id)",
            'user_interests'=>"id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint(20) unsigned NOT NULL,\nterm_id bigint(20) unsigned NOT NULL,\nsource varchar(16) NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY interest (user_id,term_id,source),\nKEY topic_source (term_id,source)"
        ];
        foreach($schemas as $name=>$schema){dbDelta("CREATE TABLE {$p}{$name} (\n$schema\n) ENGINE=InnoDB $collate;");if($wpdb->get_var($wpdb->prepare(self::TABLE_EXISTS_SQL,$wpdb->esc_like($p.$name)))!==$p.$name) {throw new MigrationException('No se pudo crear el registro de importaciones.'); }}
        $columns=['seconds'=>'int unsigned DEFAULT NULL','sessions'=>'longtext DEFAULT NULL','review_reason'=>"varchar(255) NOT NULL DEFAULT ''"];
        foreach($columns as $column=>$type) { MigrationSchema::ensureColumn($p.'attendance',$column,$column.' '.$type,'No se pudo ampliar el registro de asistencia.'); }
        update_option('ascla_schema',13,false);
        update_option('ascla_interest_index_cursor',0,false);
        if(!wp_next_scheduled('ascla_interest_index')) {wp_schedule_single_event(time()+1,'ascla_interest_index'); }
    }
    private static function migrate(): void
    {
        if ((int)get_option('ascla_schema',0)>=1) { return; }
        global $wpdb;
        require_once ABSPATH.self::UPGRADE_INCLUDE;
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
            if ($wpdb->get_var($wpdb->prepare(self::TABLE_EXISTS_SQL, $wpdb->esc_like($p.$name))) !== $p.$name) {
                throw new MigrationException('No se pudo crear el esquema ASCLA. Revise permisos de base de datos.');
            }
        }
        update_option('ascla_schema',1,false);
    }
    private static function ensureManagedPage(array $page): void
    {
        $option=$page['option'];$metaKey=$page['meta_key'];$metaValue=$page['meta_value'];
        $slug=$page['slug'];$title=$page['title'];$content=$page['content'];
        $canClaim=$page['can_claim'];$claim=$page['claim'];$error=$page['error'];
        $id=(int)get_option($option,0);
        if($id>0 && get_post_status($id) && get_post_status($id)!=='trash'){ return; }
        $owned=get_posts(['post_type'=>'page','post_status'=>['publish','draft','private'],'meta_key'=>$metaKey,'meta_value'=>$metaValue,'numberposts'=>1]);
        if($owned){ update_option($option,(int)$owned[0]->ID,false); return; }
        $existing=get_page_by_path($slug,OBJECT,'page');
        if($existing && get_post_status($existing->ID)!=='trash' && $canClaim($existing)){
            $claim($existing);
            update_option($option,(int)$existing->ID,false);
            return;
        }
        $id=wp_insert_post(['post_type'=>'page','post_title'=>$title,'post_name'=>$slug,'post_status'=>'publish','post_content'=>$content],true);
        if(is_wp_error($id)){ throw new MigrationException($error); }
        update_post_meta($id,$metaKey,$metaValue);
        update_option($option,(int)$id,false);
    }

    private static function loginPage(): void
    {
        self::ensureManagedPage([
            'option'=>'ascla_login_page','meta_key'=>'_ascla_login_page','meta_value'=>'1',
            'slug'=>'login','title'=>'Acceso ASCLA','content'=>'',
            'can_claim'=>static fn($page): bool=>trim((string)$page->post_content)==='' && !get_post_meta($page->ID,'_ascla_page',true),
            'claim'=>static function($page): void { update_post_meta($page->ID,'_ascla_login_page','1'); },
            'error'=>'No se pudo crear la página de acceso ASCLA.',
        ]);
    }

    private static function pages(): void
    {
        $ids=get_option('ascla_pages',[]);
        foreach (Catalog::PAGES as $slug=>$title) {
            if (!empty($ids[$slug]) && get_post_status($ids[$slug]) && get_post_status($ids[$slug])!=='trash') { continue; }
            $owned=get_posts(['post_type'=>'page','post_status'=>['publish','draft','private'],'meta_key'=>'_ascla_page','meta_value'=>$slug,'numberposts'=>1]);
            if ($owned) { $ids[$slug]=$owned[0]->ID; continue; }
            $id=wp_insert_post(['post_type'=>'page','post_title'=>$title,'post_name'=>$slug,'post_status'=>'publish','post_content'=>'[ascla_app page="'.$slug.'"]'],true);
            if (is_wp_error($id)) { throw new MigrationException('No se pudo crear la página '.$title); }
            update_post_meta($id,'_ascla_page',$slug); $ids[$slug]=$id;
        }
        update_option('ascla_pages',$ids,false);
    }
    private static function adminPage(): void
    {
        self::ensureManagedPage([
            'option'=>'ascla_admin_front_page','meta_key'=>'_ascla_page','meta_value'=>'admin',
            'slug'=>'administracion','title'=>'Administración ASCLA','content'=>'[ascla_app page="admin"]',
            'can_claim'=>static fn($page): bool=>(string)get_post_meta($page->ID,'_ascla_page',true)==='' && trim((string)$page->post_content)==='',
            'claim'=>static function($page): void {
                wp_update_post(['ID'=>$page->ID,'post_content'=>'[ascla_app page="admin"]']);
                update_post_meta($page->ID,'_ascla_page','admin');
            },
            'error'=>'No se pudo crear la página de administración ASCLA.',
        ]);
    }

    private static function terms(): void
    {
        $terms=['interest'=>['Gobierno corporativo','Inteligencia artificial','Juntas directivas','Sostenibilidad','Transformación digital','Gestión de riesgos'], 'area'=>['Secretaría corporativa','Cumplimiento','Estrategia','Gobierno de IA'], 'industry'=>['Servicios financieros','Energía','Tecnología','Industria','Consultoría'], 'goal'=>['Compartir experiencia','Aprender','Colaborar'], 'language'=>['Español','Inglés','Portugués'], 'category'=>['Gobernanza','Capacitación','Comunidad','Normativa']];
        foreach ($terms as $taxonomy=>$names) { foreach ($names as $name) { if (!term_exists($name,'ascla_'.$taxonomy)) { wp_insert_term($name,'ascla_'.$taxonomy); } } }
    }
}
