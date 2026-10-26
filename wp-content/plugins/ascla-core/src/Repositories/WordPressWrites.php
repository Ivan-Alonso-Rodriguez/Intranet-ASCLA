<?php
namespace ASCLA\Core\Repositories;

/** WordPress APIs may return false/WP_Error instead of throwing on failed writes. */
final class WordPressWrites
{
    private const FAILED='No se pudieron guardar todos los cambios. Reinténtalo.';

    public static function post(array $data): int
    {
        $result=empty($data['ID'])?wp_insert_post($data,true):wp_update_post($data,true);
        if (is_wp_error($result) || (int)$result<=0) { throw new RepositoryException(self::FAILED); }
        return (int)$result;
    }

    public static function meta(string $type,int $id,string $key,mixed $value,bool $preserveSlashes=false): void
    {
        $result=update_metadata($type,$id,$key,$preserveSlashes?wp_slash($value):$value);
        // false also means unchanged; that is valid only if the requested value is stored.
        if ($result===false && get_metadata($type,$id,$key,true)!=$value) { throw new RepositoryException(self::FAILED); }
    }

    public static function user(array $data): int
    {
        $result=wp_update_user($data);
        if (is_wp_error($result) || (int)$result<=0) { throw new RepositoryException(self::FAILED); }
        $stored=get_userdata((int)$result);
        foreach (['first_name','last_name','display_name','user_email'] as $field) {
            if (array_key_exists($field,$data) && (!$stored || (string)$stored->$field!==(string)$data[$field])) {
                throw new RepositoryException(self::FAILED);
            }
        }
        return (int)$result;
    }

    public static function option(string $key,mixed $value): void
    {
        if (update_option($key,$value,false)===false && get_option($key)!=$value) { throw new RepositoryException(self::FAILED); }
    }

    /** A shared generation must advance for every committed profile mutation, including concurrent users. */
    public static function bumpProfileRevision(): void
    {
        Transaction::run(static function() {
            global $wpdb;$key='ascla_profile_revision';
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'0','off')",$key))===false) { throw new RepositoryException(self::FAILED); }
            $value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$key));
            if ($value===null || $wpdb->last_error!=='') { throw new RepositoryException(self::FAILED); }
            // A transaction may have read the generation before waiting for the row lock.
            foreach ([$key,'alloptions','notoptions'] as $cacheKey) { wp_cache_delete($cacheKey,'options'); }
            self::option($key,(int)$value+1);
        });
    }

    public static function terms(int $id,array $terms,string $taxonomy): void
    {
        if (is_wp_error(wp_set_object_terms($id,$terms,$taxonomy))) { throw new RepositoryException(self::FAILED); }
    }

    public static function deleteMeta(string $type,int $id,string $key): void
    {
        if (delete_metadata($type,$id,$key)===false && metadata_exists($type,$id,$key)) { throw new RepositoryException(self::FAILED); }
    }

    public static function addMeta(string $type,int $id,string $key,mixed $value): void
    {
        if (add_metadata($type,$id,$key,$value)===false) { throw new RepositoryException(self::FAILED); }
    }
}
