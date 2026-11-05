<?php
namespace ASCLA\Core\Frontend;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Services\Access;

/** Resolve ASCLA and native administration login destinations without widening access. */
final class LoginRedirect
{
    public static function resolve(string $redirect, string $requested, $user): string
    {
        if (!$user instanceof \WP_User || !user_can($user, 'ascla_access')) {
            return $redirect;
        }
        $fallback = App::homeUrl((int)$user->ID);
        $target = wp_validate_redirect($requested, $fallback);
        if (Access::isAdministrator((int)$user->ID)) {
            return self::administratorRedirect($requested,$target,$fallback,$user);
        }
        return self::memberRedirect($target,$fallback,$user);
    }

    private static function administratorRedirect(string $requested,string $target,string $fallback,\WP_User $user): string
    {
        if ($requested!=='' && str_starts_with($target,admin_url()) && user_can($user,'manage_options')) { return $target; }
        if ($requested==='' || url_to_postid($target)===url_to_postid(Catalog::url('intranet'))) { return $fallback; }
        return self::memberRedirect($target,$fallback,$user);
    }

    private static function memberRedirect(string $target,string $fallback,\WP_User $user): string
    {
        if(user_can($user,'ascla_admin_area') && str_starts_with($target,admin_url())){
            parse_str((string)wp_parse_url($target,PHP_URL_QUERY),$query);
            $page=is_string($query['page']??null)?sanitize_key($query['page']):'';
            return App::adminUrl($page!==''?['page'=>$page]:[]);
        }
        // Only ASCLA page IDs may receive member redirects, including query-string permalinks.
        $pageId = url_to_postid($target);
        return $pageId && in_array($pageId, array_map('intval', get_option('ascla_pages', [])), true)
            ? $target : $fallback;
    }
}
