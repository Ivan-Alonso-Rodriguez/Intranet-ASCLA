<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\{Store,Transaction,WordPressWrites};

/** Manual removals always take priority over subsequent automatic topic selection. */
final class AutomaticInterests
{
    public static function excluded(int $user): array
    {
        $choices=(array)get_user_meta($user,'_ascla_interest_choices',true);
        return array_values(array_unique(array_map('intval',(array)($choices['excluded']??[]))));
    }

    public static function manual(int $user,array $old,array $selected): void
    {
        $choices=(array)get_user_meta($user,'_ascla_interest_choices',true);
        $removed=array_diff(array_map('intval',$old),array_map('intval',$selected));
        $choices['excluded']=array_values(array_diff(array_unique(array_merge(self::excluded($user),$removed)),$selected));
        $choices['automatic']=array_values(array_intersect((array)($choices['automatic']??[]),$selected));
        WordPressWrites::meta('user',$user,'_ascla_interest_choices',$choices);
        KnowledgeRecommendations::invalidateActivity($user);
    }

    public static function synchronize(int $user): void
    {
        if (!Access::member($user)) { return; }
        Store::atomic('profile-interests:'.$user,static function()use($user){
            // A request may have waited while another tab saved a manual exclusion.
            wp_cache_delete($user,'user_meta');LearnedInterests::invalidate($user);
            $choices=(array)get_user_meta($user,'_ascla_interest_choices',true);
            $profile=(array)get_user_meta($user,'_ascla_profile',true);
            $selected=array_values(array_unique(array_map('intval',(array)($profile['interests']??[]))));
            $add=[];
            foreach (LearnedInterests::estimate($user)['topics'] as $topic) {
                // Only canonical interest choices can be checked. Categories/tags never create new choices.
                if ($topic['taxonomy']==='interest' && $topic['score']>=4 && !in_array($topic['id'],self::excluded($user),true)) { $add[]=$topic['id']; }
            }
            $add=array_values(array_diff($add,$selected));
            if (!$add) { return; }
            $profile['interests']=array_values(array_unique(array_merge($selected,$add)));
            $profile['revision']=(int)($profile['revision']??0)+1;
            $choices['automatic']=array_values(array_unique(array_merge((array)($choices['automatic']??[]),$add)));
            WordPressWrites::meta('user',$user,'_ascla_interest_choices',$choices);
            WordPressWrites::meta('user',$user,'_ascla_profile',$profile);
            WordPressWrites::bumpProfileRevision();
            Transaction::afterCommit(static fn()=>Matching::refreshBatch($user));
        });
    }
}
