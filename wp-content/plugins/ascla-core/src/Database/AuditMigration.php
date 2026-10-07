<?php
namespace ASCLA\Core\Database;
final class AuditMigration
{
    public static function run(): void
    {
        if ((int)get_option('ascla_schema',0)>=14) { return; }
        global $wpdb;
        $table=\ASCLA\Core\Repositories\Store::table('audit');
        if ($wpdb->query("ALTER TABLE $table MODIFY detail LONGTEXT NOT NULL")===false) {
            throw new \RuntimeException('No se pudo ampliar el historial de auditoría.');
        }
        update_option('ascla_schema',14,false);
    }
}
