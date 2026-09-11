<?php
namespace ASCLA\Core\Repositories;

final class Store
{
    private const TABLES=['conversations','participants','messages','relations','registrations','notifications','audit','jobs','media'];
    public static function table(string $name): string
    {
        global $wpdb;
        if (!in_array($name,self::TABLES,true)) { throw new \InvalidArgumentException('Tabla no válida'); }
        return $wpdb->prefix.'ascla_'.$name;
    }
    public static function insert(string $table,array $data): int
    {
        global $wpdb;
        if ($wpdb->insert(self::table($table),$data)===false) { throw new \RuntimeException('No se pudo guardar. Reintente la operación.'); }
        return (int)$wpdb->insert_id;
    }
    public static function update(string $table,array $data,array $where): void
    {
        global $wpdb;
        if ($wpdb->update(self::table($table),$data,$where)===false) { throw new \RuntimeException('No se pudo actualizar.'); }
    }
    public static function delete(string $table,array $where): void
    {
        global $wpdb; $wpdb->delete(self::table($table),$where);
    }
    public static function rows(string $table,string $where='1=1',array $args=[],string $suffix='ORDER BY id DESC LIMIT 100'): array
    {
        global $wpdb; $sql='SELECT * FROM '.self::table($table).' WHERE '.$where.' '.$suffix;
        return $wpdb->get_results($args?$wpdb->prepare($sql,...$args):$sql,ARRAY_A) ?: [];
    }
    public static function one(string $table,int $id): ?array { return self::rows($table,'id=%d',[$id],'LIMIT 1')[0]??null; }
    public static function count(string $table,string $where,array $args): int
    {
        global $wpdb; return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table($table).' WHERE '.$where,...$args));
    }
    public static function privateCommentFeedFilter(string $where): string
    {
        global $wpdb;
        return $where.$wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} AS ascla_parent WHERE ascla_parent.ID={$wpdb->comments}.comment_post_ID AND ascla_parent.post_type LIKE %s)",$wpdb->esc_like('ascla_').'%');
    }
    public static function lock(string $key,callable $callback): mixed
    {
        global $wpdb; $name='ascla_'.substr(hash('sha256',$wpdb->prefix.$key),0,56);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$name))!==1) { throw new \RuntimeException('Operación en curso. Intente nuevamente.'); }
        try { return $callback(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name)); }
    }
}
