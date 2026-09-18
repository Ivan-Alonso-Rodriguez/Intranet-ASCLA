<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** Query projection only: existing profile metadata remains the source of truth. */
final class InterestIndex
{
    public static function boot(): void
    {
        foreach(['added_user_meta','updated_user_meta','deleted_user_meta'] as $hook)add_action($hook,[self::class,'changed'],10,4);
        add_action('ascla_interest_index',[self::class,'batch']);
        self::resumePending();
        add_action('deleted_user',static fn($id)=>Store::delete('user_interests',['user_id'=>(int)$id]));
        add_action('delete_ascla_interest',static fn($id)=>Store::delete('user_interests',['term_id'=>(int)$id]));
    }
    private static function resumePending(): void
    {
        if(get_option('ascla_interest_index_cursor',false)!==false && !wp_next_scheduled('ascla_interest_index')){
            wp_schedule_single_event(time()+1,'ascla_interest_index');
        }
    }
    public static function changed($metaId,int $user,string $key,$value): void
    {
        if(in_array($key,['_ascla_profile','_ascla_forms_interests'],true) && (int)get_option('ascla_schema',0)>=13)self::sync($user);
    }
    public static function sync(int $user): void
    {
        global $wpdb;
        $profile=(array)get_user_meta($user,'_ascla_profile',true);$forms=(array)get_user_meta($user,'_ascla_forms_interests',true);
        $catalog=get_terms(['taxonomy'=>'ascla_interest','hide_empty'=>false,'fields'=>'ids']);$catalog=is_wp_error($catalog)?[]:array_map('intval',$catalog);
        $expected=[];foreach(['profile'=>(array)($profile['interests']??[]),'forms'=>(array)($forms['ids']??[])] as $source=>$ids){
            foreach(array_intersect(array_unique(array_map('intval',$ids)),$catalog) as $id)$expected[$source.':'.$id]=['user_id'=>$user,'term_id'=>$id,'source'=>$source];
        }
        $existing=Store::rows('user_interests','user_id=%d',[$user],'ORDER BY id ASC');
        foreach($existing as $row){$key=$row['source'].':'.$row['term_id'];if(isset($expected[$key]))unset($expected[$key]);else Store::delete('user_interests',['id'=>(int)$row['id']]);}
        foreach($expected as $row)Store::insert('user_interests',$row);
    }
    public static function batch(): void
    {
        if(get_option('ascla_interest_index_cursor',false)===false)return;
        Store::lock('interest-index',static function(){
            global $wpdb;$cursor=(int)get_option('ascla_interest_index_cursor',0);
            $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID>%d ORDER BY ID LIMIT 200",$cursor));
            foreach($ids as $id)Store::lock('profile-interests:'.$id,static fn()=>self::sync((int)$id));
            if(count($ids)<200){delete_option('ascla_interest_index_cursor');return;}
            update_option('ascla_interest_index_cursor',(int)end($ids),false);
            if(!wp_next_scheduled('ascla_interest_index'))wp_schedule_single_event(time()+5,'ascla_interest_index');
        });
    }
}
