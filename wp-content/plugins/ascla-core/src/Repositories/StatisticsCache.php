<?php
namespace ASCLA\Core\Repositories;

/** One bounded cache bucket per staff account; identity labels are rendered outside it. */
final class StatisticsCache
{
    private const REVISION='ascla_statistics_revision';
    public const TTL=30;
    public const MAX_CONTEXTS=8;

    public static function boot(): void
    {
        foreach (['user_register','profile_update','deleted_user','set_user_role','add_user_role','remove_user_role','set_object_terms','created_term','edited_term','delete_term','wp_insert_comment','edit_comment','deleted_comment','transition_comment_status'] as $hook) {
            add_action($hook,[self::class,'invalidate'],20,0);
        }
        add_action('save_post',[self::class,'postChanged'],20,2);
        add_action('deleted_post',[self::class,'postChanged'],20,2);
        foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) {
            add_action($hook,[self::class,'metaChanged'],20,3);
        }
        foreach (['added_user_meta','updated_user_meta','deleted_user_meta'] as $hook) {
            add_action($hook,[self::class,'userMetaChanged'],20,3);
        }
    }

    public static function remember(array $context,callable $calculate): array
    {
        if (Transaction::active()) { return $calculate(); }
        $slot='ascla_statistics_'.get_current_blog_id().'_'.get_current_user_id();
        $signature=hash('sha256',wp_json_encode([2,get_option(self::REVISION,'0'),get_user_locale(),current_user_can('ascla_manage')]));
        $key=hash('sha256',wp_json_encode($context));$now=time();
        $cached=get_transient($slot);
        $entries=is_array($cached) && ($cached['signature']??'')===$signature?(array)($cached['entries']??[]):[];
        $entries=array_filter($entries,static fn($entry)=>is_array($entry) && (int)($entry['expires']??0)>$now && is_array($entry['data']??null));
        if (isset($entries[$key])) { return $entries[$key]['data']; }
        $data=$calculate();
        $entries[$key]=['expires'=>time()+self::TTL,'data'=>$data];
        $entries=array_slice($entries,-self::MAX_CONTEXTS,null,true);
        set_transient($slot,['signature'=>$signature,'entries'=>$entries],self::TTL);
        return $data;
    }

    public static function changed(string $table): void
    {
        if (in_array($table,['registrations','attendance','relations','audit','user_interests'],true)) { self::invalidate(); }
    }

    public static function invalidate(): void
    {
        Transaction::afterCommitOnce('statistics-cache',static function(){update_option(self::REVISION,wp_generate_uuid4(),false);});
    }

    public static function postChanged(int $id,\WP_Post $post): void
    {
        unset($id);
        if (str_starts_with($post->post_type,'ascla_')) { self::invalidate(); }
    }

    public static function metaChanged(mixed $metaId,int $objectId,string $key): void
    {
        unset($metaId,$objectId);
        if (in_array($key,['_ascla','_ascla_start','_ascla_end','_ascla_cancelled','_ascla_micro','_ascla_invitee','_ascla_attendance_complete'],true)) { self::invalidate(); }
    }

    public static function userMetaChanged(mixed $metaId,int $objectId,string $key): void
    {
        unset($metaId,$objectId);
        if (in_array($key,['_ascla_profile','_ascla_role_approval','_ascla_suspended','_ascla_membership_status','_ascla_membership_until'],true) || str_ends_with($key,'capabilities')) { self::invalidate(); }
    }
}
