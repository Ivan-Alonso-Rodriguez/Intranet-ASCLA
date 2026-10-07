<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\EditorialPrivacy;
use ASCLA\Core\Domain\EntityRedactor;

final class EditorialPayload
{
    private const EDITORIAL_TYPES=['gallery','resource','event'];

    public static function reader(\WP_Post $post,array $meta,mixed $title,mixed $body): array
    {
        if (!in_array(substr($post->post_type,6),self::EDITORIAL_TYPES,true) || Content::canEdit($post)) {
            return [$title,$body,$meta];
        }
        $view=EditorialPrivacy::reader($title,$body,$meta);
        $redactedTitle=$view['title'];
        if ($post->post_type==='ascla_event') {
            $identities=array_values(array_filter(array_map('trim',preg_split('/[\r\n,;]+/u',(string)($meta['identities']??''))?:[])));
            $redactedTitle=EntityRedactor::redactEntities($title,$identities);
        }
        return [$redactedTitle,$view['body'],$view['meta']];
    }
}
