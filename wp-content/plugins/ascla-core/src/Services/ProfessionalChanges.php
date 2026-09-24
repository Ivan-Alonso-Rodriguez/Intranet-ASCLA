<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\Store;

/** A member's active Contact request authorizes one administrative professional update. */
final class ProfessionalChanges
{
    private static function eligible(\WP_Post $post,int $member): bool
    {
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        return $post->post_type==='ascla_contact' && $post->post_status==='private'
            && (int)$post->post_author===$member
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
        Access::require($request>0,'Selecciona una solicitud del asociado en Contacto para cambiar Cargo o Empresa.',403);
        Store::lock('contact:'.$request,static function()use($member,$request,$fields,$save){
            clean_post_cache($request);
            $post=get_post($request);
            Access::require($post && self::eligible($post,$member),'La solicitud debe pertenecer al asociado, estar activa y no haber sido utilizada.',409);
            $save();
            $record=['member'=>$member,'actor'=>get_current_user_id(),'at'=>gmdate('c'),'fields'=>array_values($fields)];
            // Separate metadata cannot be overwritten by a member editing their original request.
            update_post_meta($request,'_ascla_professional_change',$record);
            Audit::record('professional_profile_updated',$member,'request='.$request.'; fields='.implode(',',$fields));
        });
    }
}
