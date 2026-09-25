<?php
namespace ASCLA\Core\Database;

/** Small schema helpers used by incremental migrations. */
final class MigrationSchema
{
    public static function hasColumn(string $table,string $column): bool
    {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s",$column));
    }

    public static function ensureColumn(string $table,string $column,string $definition,string $error): void
    {
        global $wpdb;
        if (self::hasColumn($table,$column)) { return; }
        $changed=$wpdb->query("ALTER TABLE $table ADD $definition");
        if ($changed===false || !self::hasColumn($table,$column)) {
            throw new MigrationException($error);
        }
    }
}
