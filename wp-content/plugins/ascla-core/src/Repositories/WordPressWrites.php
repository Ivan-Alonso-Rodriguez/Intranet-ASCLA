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

    public static function meta(string $type,int $id,string $key,mixed $value): void
    {
        $result=update_metadata($type,$id,$key,$value);
        // false also means unchanged; that is valid only if the requested value is stored.
        if ($result===false && get_metadata($type,$id,$key,true)!=$value) { throw new RepositoryException(self::FAILED); }
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
