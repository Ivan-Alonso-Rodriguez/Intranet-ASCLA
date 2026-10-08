<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** A member's active Contact request authorizes one administrative professional update. */
final class ProfessionalChanges
{
    public const FIELDS=['first_name','last_name','position','company','birth_date','phone','phone_visibility'];

    public static function changed(array $before,array $after): array
    {
        return array_values(array_filter(self::FIELDS,static fn($key)=>($before[$key]??'')!==($after[$key]??'')));
    }

    public static function boot(): void
    {
        add_action('user_profile_update_errors',static function($errors,$update,$user): void {
            if ($update && self::nativeChange((int)$user->ID,(array)$user)) {
                $errors->add('ascla_profile_request','Los datos personales del asociado se editan desde ASCLA mediante una solicitud de Contacto.');
            }
        },10,3);
    }

    public static function nativeChange(int $id,array $input): bool
    {
        if (!$id || $id===get_current_user_id() || !user_can($id,'ascla_access')) { return false; }
        $user=get_userdata($id);
        foreach (['first_name','last_name','display_name','description','user_url'] as $key) {
            if (array_key_exists($key,$input) && (string)$input[$key]!== (string)$user->$key) { return true; }
        }
        return false;
    }
    private static function eligible(\WP_Post $post,int $member): bool
    {
        SupportRequests::initialize($post->ID);
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        return $post->post_type==='ascla_contact' && $post->post_status==='private'
            && (int)$post->post_author===$member && SupportRequests::canHandle($post->ID)
            && in_array($meta['request_status']??'open',['open','progress'],true)
            && !get_post_meta($post->ID,'_ascla_professional_change',true);
    }

    public static function pending(int $member): array
    {
        Access::require(current_user_can('ascla_manage'));
        $requests=[];
        foreach (get_posts(['post_type'=>'ascla_contact','post_status'=>'private','author'=>$member,'numberposts'=>-1,'orderby'=>'date','order'=>'DESC']) as $post) {
            if (!self::eligible($post,$member)) { continue; }
            $requests[]=['id'=>$post->ID,'title'=>$post->post_title,'body'=>wp_strip_all_tags($post->post_content),'date'=>$post->post_date_gmt];
        }
        return $requests;
    }

    public static function apply(int $member,int $request,array $fields,callable $save): void
    {
        Access::require(current_user_can('ascla_manage') && current_user_can('edit_user',$member));
        Access::require($request>0,'Selecciona una solicitud del asociado en Contacto para cambiar sus datos personales o profesionales.',403);
        Store::atomic('contact:'.$request,static function()use($member,$request,$fields,$save){
            clean_post_cache($request);
            $post=get_post($request);
            Access::require($post && self::eligible($post,$member),'La solicitud debe pertenecer al asociado, estar activa y no haber sido utilizada.',409);
            $save();
            $record=['member'=>$member,'actor'=>get_current_user_id(),'at'=>gmdate('c'),'fields'=>array_values($fields)];
            // Separate metadata cannot be overwritten by a member editing their original request.
            \ASCLA\Core\Repositories\WordPressWrites::meta('post',$request,'_ascla_professional_change',$record);
            Audit::record('professional_profile_updated',$member,'request='.$request.'; fields='.implode(',',$fields));
        });
    }
}
