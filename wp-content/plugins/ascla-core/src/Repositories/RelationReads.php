<?php
namespace ASCLA\Core\Repositories;

/** Batch only within a read operation. Never reuse permission facts inside writes. */
final class RelationReads
{
    private static array $scopes=[];

    public static function run(int $viewer,callable $callback): mixed
    {
        if ($viewer<1 || Transaction::active()) { return $callback(); }
        foreach (self::$scopes as $scope) {
            if ($scope['viewer']===$viewer && $scope['blog']===get_current_blog_id() && get_current_user_id()===$viewer) { return $callback(); }
        }
        self::$scopes[]=['viewer'=>$viewer,'blog'=>get_current_blog_id(),'pairs'=>null];
        try { return $callback(); } finally { array_pop(self::$scopes); }
    }

    public static function changed(): void
    {
        foreach (self::$scopes as &$scope) { $scope['pairs']=null; }unset($scope);
    }

    /** Null means no active read scope: the caller must query current storage. */
    public static function pair(int $a,int $b): ?array
    {
        if (Transaction::active()) { return null; }
        foreach (array_reverse(array_keys(self::$scopes)) as $index) {
            $scope=&self::$scopes[$index];$viewer=$scope['viewer'];
            if ($scope['blog']!==get_current_blog_id() || get_current_user_id()!==$viewer || ($a!==$viewer && $b!==$viewer)) { unset($scope);continue; }
            if ($scope['pairs']===null) { $scope['pairs']=self::load($viewer); }
            return $scope['pairs'][$a===$viewer?$b:$a]??[];
        }
        return null;
    }

    private static function load(int $viewer): array
    {
        global $wpdb;
        $rows=Store::rows('relations',"kind IN ('block','recommendation_ignore','connection_cooldown') AND (user_id=%d OR target_id=%d)",[$viewer,$viewer],'ORDER BY id DESC');
        if ($wpdb->last_error!=='') { throw new RepositoryException('No se pudieron comprobar los permisos de conexión.'); }
        $pairs=[];
        foreach ($rows as $row) {
            $target=(int)$row['user_id']===$viewer?(int)$row['target_id']:(int)$row['user_id'];
            $pairs[$target][]=$row;
        }
        return $pairs;
    }
}
