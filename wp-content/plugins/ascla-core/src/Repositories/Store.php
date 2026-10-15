<?php
namespace ASCLA\Core\Repositories;

final class Store
{
    private const TABLES=['conversations','participants','messages','relations','registrations','notifications','audit','jobs','deliveries','media','attendance','imports','import_rows','user_interests'];
    public static function table(string $name): string
    {
        global $wpdb;
        if (!in_array($name,self::TABLES,true)) { throw new \InvalidArgumentException('Tabla no válida'); }
        return $wpdb->prefix.'ascla_'.$name;
    }
    public static function insert(string $table,array $data): int
    {
        global $wpdb;
        if ($wpdb->insert(self::table($table),$data)===false) { throw new RepositoryException('No se pudo guardar. Reintente la operación.'); }
        return (int)$wpdb->insert_id;
    }
    public static function update(string $table,array $data,array $where): void
    {
        global $wpdb;
        if ($wpdb->update(self::table($table),$data,$where)===false) { throw new RepositoryException('No se pudo actualizar.'); }
    }
    public static function delete(string $table,array $where): void
    {
        global $wpdb;
        if ($wpdb->delete(self::table($table),$where)===false) { throw new RepositoryException('No se pudo eliminar el registro.'); }
    }
    public static function rows(string $table,string $where='1=1',array $args=[],string $suffix='ORDER BY id DESC LIMIT 100'): array
    {
        global $wpdb; $sql='SELECT * FROM '.self::table($table).' WHERE '.$where.' '.$suffix;
        $query=$sql;
        if($args){$query=$wpdb->prepare($sql,...$args);}
        return $wpdb->get_results($query,ARRAY_A) ?: [];
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
    public static function lock(string $key,callable $callback,int $timeout=3): mixed
    {
        global $wpdb; $name='ascla_'.substr(hash('sha256',$wpdb->prefix.$key),0,56);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)',$name,max(0,min(3,$timeout))))!==1) { throw new RepositoryException('Operación en curso. Intente nuevamente.'); }
        try { return $callback(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name)); }
    }
    public static function atomic(string $key,callable $callback): mixed
    {
        return self::lock($key,static fn()=>Transaction::run($callback));
    }
    /** Normal contention is not an error for a background recovery attempt. */
    public static function tryLock(string $key,callable $callback): bool
    {
        global $wpdb;$name='ascla_'.substr(hash('sha256',$wpdb->prefix.$key),0,56);
        $result=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name));
        if ($result===null || $wpdb->last_error!=='') { throw new RepositoryException('No se pudo comprobar el bloqueo.'); }
        if ((int)$result!==1) { return false; }
        try { $callback();return true; } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name)); }
    }
    /** Hold a deterministic set of locks until the complete callback (including commit) ends. */
    public static function locks(array $keys,callable $callback): mixed
    {
        $keys=array_values(array_unique($keys));sort($keys,SORT_STRING);
        return self::acquireLocks($keys,0,$callback);
    }
    private static function acquireLocks(array $keys,int $offset,callable $callback): mixed
    {
        if (!isset($keys[$offset])) { return $callback(); }
        return self::lock($keys[$offset],static fn()=>self::acquireLocks($keys,$offset+1,$callback));
    }
}
