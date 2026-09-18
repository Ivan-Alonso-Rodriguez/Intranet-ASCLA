<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Database\Installer;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\InterestIndex;

final class Schema12To13MigrationTest extends TestCase
{
    public function testSchema12To13PreservesExistingDataAndBuildsNewStructures(): void
    {
        global $wpdb;

        $oldSchema=get_option('ascla_schema');
        $oldVersion=get_option('ascla_version');
        $oldCursor=get_option('ascla_interest_index_cursor',null);
        $hadCursor=get_option('ascla_interest_index_cursor',false)!==false;
        $oldIndexEvent=wp_next_scheduled('ascla_interest_index');
        $createdUser=0;$createdPost=0;$createdTerm=0;
        $backups=[];$temporaryAttendance=false;
        $newTables=['imports','import_rows','user_interests'];

        try {
            // Use one real taxonomy term so profile/forms metadata can be checked after migration.
            $terms=get_terms(['taxonomy'=>'ascla_interest','hide_empty'=>false,'number'=>1]);
            if(is_wp_error($terms) || !$terms){
                $inserted=wp_insert_term('Migración 12-13','ascla_interest');
                self::assertFalse(is_wp_error($inserted));
                $createdTerm=(int)$inserted['term_id'];$termId=$createdTerm;
            }else{$termId=(int)$terms[0]->term_id;}

            $createdUser=wp_insert_user([
                'user_login'=>'ascla_migration_'.wp_generate_password(10,false,false),
                'user_pass'=>wp_generate_password(24,true,true),
                'user_email'=>'migration-'.wp_generate_password(8,false,false).'@example.test',
                'display_name'=>'Migración ASCLA',
            ]);
            self::assertIsInt($createdUser);self::assertGreaterThan(0,$createdUser);
            update_user_meta($createdUser,'_ascla_profile',['first_name'=>'Conservado','interests'=>[$termId],'company'=>'Perfil preexistente']);
            update_user_meta($createdUser,'_ascla_forms_interests',['ids'=>[$termId],'updated_at'=>'2026-09-17 12:00:00']);

            $createdPost=wp_insert_post([
                'post_type'=>'ascla_event','post_status'=>'publish','post_title'=>'Evento preexistente esquema 12','post_content'=>'Contenido que debe conservarse.',
            ],true);
            self::assertFalse(is_wp_error($createdPost));$createdPost=(int)$createdPost;
            update_post_meta($createdPost,'_ascla',['capacity'=>25,'location'=>'Sala de prueba']);

            $registration=Store::insert('registrations',[
                'event_id'=>$createdPost,'user_id'=>$createdUser,'status'=>'confirmed','created_at'=>current_time('mysql',true),
            ]);
            $registrationBefore=Store::one('registrations',$registration);
            $profileBefore=get_user_meta($createdUser,'_ascla_profile',true);
            $formsBefore=get_user_meta($createdUser,'_ascla_forms_interests',true);
            $eventBefore=get_post($createdPost,ARRAY_A);
            $eventMetaBefore=get_post_meta($createdPost,'_ascla',true);

            // Hide the schema-13 tables from this connection so dbDelta must really create them.
            foreach($newTables as $name){
                $table=Store::table($name);$backup=$table.'_migration_backup_'.substr(md5((string)microtime(true).$name),0,8);
                if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))===$table){
                    self::assertNotFalse($wpdb->query("RENAME TABLE `$table` TO `$backup`"));$backups[$table]=$backup;
                }
            }

            // Shadow attendance with the exact schema-12 shape so the real table remains untouched.
            $attendance=Store::table('attendance');
            self::assertNotFalse($wpdb->query("CREATE TEMPORARY TABLE `$attendance` (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                status varchar(16) NOT NULL,
                minutes int(11) unsigned DEFAULT NULL,
                source varchar(16) NOT NULL,
                recorded_by bigint(20) unsigned NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY attendance (event_id,user_id),
                KEY user_event (user_id,event_id)
            ) ENGINE=InnoDB {$wpdb->get_charset_collate()}"));
            $temporaryAttendance=true;
            self::assertNotFalse($wpdb->insert($attendance,[
                'event_id'=>$createdPost,'user_id'=>$createdUser,'status'=>'present','minutes'=>48,'source'=>'zoom','recorded_by'=>1,'updated_at'=>'2026-09-17 12:00:00',
            ]));
            $attendanceId=(int)$wpdb->insert_id;
            $attendanceBefore=$wpdb->get_row($wpdb->prepare("SELECT id,event_id,user_id,status,minutes,source,recorded_by,updated_at FROM `$attendance` WHERE id=%d",$attendanceId),ARRAY_A);

            update_option('ascla_schema',12,false);update_option('ascla_version',ASCLA_VERSION,false);
            Installer::upgrade();

            self::assertSame(13,(int)get_option('ascla_schema'));
            foreach($newTables as $name){
                $table=Store::table($name);
                self::assertSame($table,$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))),"Missing migrated table $name");
            }
            foreach(['seconds','sessions','review_reason'] as $column){
                self::assertNotEmpty($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$attendance` LIKE %s",$column)),"Missing attendance column $column");
            }

            $attendanceAfter=$wpdb->get_row($wpdb->prepare("SELECT id,event_id,user_id,status,minutes,source,recorded_by,updated_at FROM `$attendance` WHERE id=%d",$attendanceId),ARRAY_A);
            self::assertSame($attendanceBefore,$attendanceAfter,'Schema 12 attendance data changed during migration.');
            self::assertSame($profileBefore,get_user_meta($createdUser,'_ascla_profile',true));
            self::assertSame($formsBefore,get_user_meta($createdUser,'_ascla_forms_interests',true));
            self::assertSame($eventBefore['post_title'],get_post($createdPost)->post_title);
            self::assertSame($eventBefore['post_content'],get_post($createdPost)->post_content);
            self::assertSame($eventMetaBefore,get_post_meta($createdPost,'_ascla',true));
            self::assertSame($registrationBefore,Store::one('registrations',$registration));

            // The new interest table is a projection: source metadata stays intact and can rebuild it.
            InterestIndex::sync($createdUser);
            $indexed=Store::rows('user_interests','user_id=%d',[$createdUser],'ORDER BY source ASC,id ASC');
            self::assertCount(2,$indexed);
            self::assertSame(['forms','profile'],array_values(array_unique(array_column($indexed,'source'))));
            self::assertSame([$termId],array_values(array_unique(array_map('intval',array_column($indexed,'term_id')))));

            // Re-running the migration must be safe and keep the same data.
            Installer::upgrade();
            self::assertSame(13,(int)get_option('ascla_schema'));
            self::assertSame($profileBefore,get_user_meta($createdUser,'_ascla_profile',true));
            self::assertSame($registrationBefore,Store::one('registrations',$registration));
        } finally {
            if($temporaryAttendance){$wpdb->query('DROP TEMPORARY TABLE IF EXISTS `'.Store::table('attendance').'`');}
            // Remove the test-created schema-13 tables before restoring the originals.
            foreach(array_reverse($newTables) as $name){
                $table=Store::table($name);
                if(isset($backups[$table])){
                    $wpdb->query("DROP TABLE IF EXISTS `$table`");
                    $wpdb->query("RENAME TABLE `{$backups[$table]}` TO `$table`");
                }
            }
            if($createdPost){wp_delete_post($createdPost,true);}
            if($createdUser){wp_delete_user($createdUser);}
            if($createdTerm){wp_delete_term($createdTerm,'ascla_interest');}
            update_option('ascla_schema',$oldSchema,false);update_option('ascla_version',$oldVersion,false);
            if($hadCursor)update_option('ascla_interest_index_cursor',$oldCursor,false);else delete_option('ascla_interest_index_cursor');
            $current=wp_next_scheduled('ascla_interest_index');
            if($current && $current!==$oldIndexEvent)wp_unschedule_event($current,'ascla_interest_index');
        }
    }
}
