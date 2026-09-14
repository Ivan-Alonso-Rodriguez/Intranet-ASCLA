<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;

/** Internal suggestions use explicit opt-ins and idempotent delivery keys. */
final class Discovery
{
    public static function resource(int $id): array
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'ascla_resource' || $post->post_status !== 'publish') { return ['sent' => 0]; }
        $sent = 0;
        self::eachMember(static function ($user) use ($post, &$sent) {
            if ($user->ID === (int)$post->post_author || !Content::canRead($post)) { return; }
            $rank=KnowledgeRecommendations::score($post,(int)$user->ID);
            // Explicit interests are strongest; keywords can also qualify when they have a meaningful profile match.
            if (($rank['relevance']??0)>=8) {
                $sent += Notifications::once($user->ID, 'resource:' . $post->ID, 'resource', 'Hay un nuevo recurso relacionado con tus intereses y conocimientos.', Catalog::url('centro-conocimiento', ['item' => $post->ID]),['type'=>'post','id'=>$post->ID]) ? 1 : 0;
            }
        });
        return ['sent' => $sent];
    }

    public static function networking(): array
    {
        $sent = 0;
        self::eachMember(static function ($user) use (&$sent) {
            $profile = Profiles::raw($user->ID);
            if (empty($profile['networking']) || empty($profile['directory'])) { return; }
            $suggestion = Matching::recommendations()[0] ?? null;
            if (!$suggestion || $suggestion['affinity']['score'] <= 0) { return; }
            $sent += Notifications::once($user->ID, 'networking:' . wp_date('o-W'), 'networking', 'Descubre una conexión afín a tus intereses.', Catalog::url('perfil', ['member' => $suggestion['id']]),['type'=>'profile','id'=>$suggestion['id']]) ? 1 : 0;
        });
        return ['sent' => $sent];
    }

    private static function eachMember(callable $callback): void
    {
        $original = get_current_user_id();
        try {
            $offset = 0;
            do {
                $users = get_users(['capability' => 'ascla_access', 'number' => 100, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC']);
                $offset += 100;
                foreach ($users as $user) {
                    if (!Access::member($user->ID)) { continue; }
                    wp_set_current_user($user->ID);
                    $callback($user);
                }
            } while (count($users) === 100);
        } finally { wp_set_current_user($original); }
    }
}
