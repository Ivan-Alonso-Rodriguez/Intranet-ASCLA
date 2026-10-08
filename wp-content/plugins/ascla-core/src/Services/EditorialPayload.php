<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\EditorialPrivacy;

final class EditorialPayload
{
    private const EDITORIAL_TYPES=['gallery','resource','event'];

    public static function reader(\WP_Post $post,array $meta,mixed $title,mixed $body): array
    {
        if (!in_array(substr($post->post_type,6),self::EDITORIAL_TYPES,true) || Content::canEdit($post)) {
            return [$title,$body,$meta];
        }
        $view=EditorialPrivacy::reader($title,$body,$meta);
        return [$view['title'],$view['body'],$view['meta']];
    }
}
