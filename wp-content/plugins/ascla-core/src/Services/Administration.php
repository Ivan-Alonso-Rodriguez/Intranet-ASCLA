<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;

/** Administration reads and decisions reuse WordPress users, posts and existing metadata. */
final class Administration
{
    private const REQUEST_STATES=['open','progress','closed'];
    private static function requestStateLabel(string $state): string
    {
        return ['open'=>'Recibida','progress'=>'En atención','closed'=>'Resuelta'][$state]??$state;
    }
    private static function contactArgs(string $state,string $q): array
    {
        $args=['post_type'=>'ascla_contact','post_status'=>'private','s'=>$q,'orderby'=>['date'=>'DESC','ID'=>'DESC'],'posts_per_page'=>20];
        if($state==='open') $args['meta_query']=[['relation'=>'OR',['key'=>'_ascla_request_status','compare'=>'NOT EXISTS'],['key'=>'_ascla_request_status','value'=>'open']]];
        elseif(in_array($state,self::REQUEST_STATES,true)) $args['meta_query']=[['key'=>'_ascla_request_status','value'=>$state]];
        return $args;
    }
    public static function contacts(array $filter): array
    {
        Access::require(current_user_can('ascla_moderate'));
        // Backfill only the search index of older contact metadata; no schema migration.
        if(!get_option('ascla_contacts_indexed_v1')) {
            foreach(get_posts(['post_type'=>'ascla_contact','post_status'=>'private','numberposts'=>-1,'fields'=>'ids']) as $id) { $meta=(array)get_post_meta($id,'_ascla',true);update_post_meta($id,'_ascla_request_status',$meta['request_status']??'open'); }
            update_option('ascla_contacts_indexed_v1',true,false);
        }
        $state=Access::text($filter['state']??'',20);Access::require($state===''||in_array($state,self::REQUEST_STATES,true),'Estado no válido.',400);
        $q=Access::text($filter['q']??'',120);$page=max(1,min(10000,(int)($filter['page']??1)));
        $query=new \WP_Query(self::contactArgs($state,$q)+['paged'=>$page]);$counts=[];
        foreach(self::REQUEST_STATES as $key) {$args=self::contactArgs($key,'');$args['posts_per_page']=1;$args['fields']='ids';$counts[$key]=(int)(new \WP_Query($args))->found_posts;}
        return ['items'=>array_map([Content::class,'serialize'],$query->posts),'page'=>$page,'pages'=>(int)$query->max_num_pages,'total'=>(int)$query->found_posts,'counts'=>$counts];
    }
    public static function contactStatus(int $id,string $status): array
    {
        Access::require(current_user_can('ascla_moderate'));$post=Content::get($id);
        Access::require($post->post_type==='ascla_contact','Solicitud no válida.',400);
        Access::require(in_array($status,self::REQUEST_STATES,true),'Estado no válido.',400);
        return Store::lock('contact:'.$id,static function()use($id,$status,$post){
            $meta=(array)get_post_meta($id,'_ascla',true);$previous=$meta['request_status']??'open';
            if($previous!==$status) {
                $meta['request_status']=$status;$meta['request_updated_at']=gmdate('c');
                $history=(array)($meta['request_history']??[]);$history[]=['from'=>$previous,'to'=>$status,'at'=>$meta['request_updated_at'],'actor'=>Profiles::publicName(get_current_user_id())];
                $meta['request_history']=array_slice($history,-20);update_post_meta($id,'_ascla',$meta);
                Audit::record('contact_status',$id,$status);
                $author=(int)$post->post_author;
                if($author>0 && Access::member($author)) {
                    Notifications::send(
                        $author,
                        'support_update',
                        'Tu solicitud #'.$id.' ahora está '.self::requestStateLabel($status).'.',
                        \ASCLA\Core\Domain\Catalog::url('contacto'),
                        ['type'=>'post','id'=>$id,'actor'=>get_current_user_id()]
                    );
                }
            }
            update_post_meta($id,'_ascla_request_status',$status);
            return Content::serialize($post);
        });
    }
    public static function users(array $filter): array
    {
        Access::require(current_user_can('ascla_manage'));
        $q=Access::text($filter['q']??'',100);$role=Access::text($filter['role']??'',60);$state=Access::text($filter['state']??'',20);
        Access::require($role===''||isset(wp_roles()->roles[$role]),'Rol no válido.',400);
        Access::require(in_array($state,['','active','suspended'],true),'Estado no válido.',400);
        $page=max(1,min(10000,(int)($filter['page']??1)));$args=['number'=>20,'paged'=>$page,'orderby'=>'display_name','order'=>'ASC'];
        if($q!=='') {$args['search']='*'.str_replace('*','',$q).'*';$args['search_columns']=['user_login','user_email','display_name'];}
        if($role!=='')$args['role']=$role;
        if($state==='suspended')$args['meta_query']=[['key'=>'_ascla_suspended','value'=>'1']];
        elseif($state==='active')$args['meta_query']=[['relation'=>'OR',['key'=>'_ascla_suspended','compare'=>'NOT EXISTS'],['key'=>'_ascla_suspended','value'=>'1','compare'=>'!=']]];
        $query=new \WP_User_Query($args);$items=[];
        foreach($query->get_results() as $user) {
            $id=(int)$user->ID;$card=Profiles::card($id);
            $items[]=['id'=>$id,'name'=>$user->display_name,'login'=>$user->user_login,'email'=>$user->user_email,'roles'=>array_values($user->roles),'registered'=>$user->user_registered,'suspended'=>(bool)get_user_meta($id,'_ascla_suspended',true),'photo_url'=>$card['photo_url'],'profile_url'=>$card['profile_url'],'edit_url'=>current_user_can('edit_user',$id)?get_edit_user_link($id):'','can_suspend'=>$id!==get_current_user_id()&&!user_can($id,'manage_options')&&user_can($id,'ascla_access')];
        }
        $roles=[];foreach(wp_roles()->roles as $id=>$roleData)$roles[]=['id'=>$id,'name'=>['administrator'=>'Administrador','ascla_member'=>'Asociado ASCLA','ascla_executive'=>'Ejecutivo ASCLA','ascla_moderator'=>'Moderador ASCLA'][$id]??translate_user_role($roleData['name'])];
        return ['items'=>$items,'page'=>$page,'total'=>$query->get_total(),'pages'=>(int)ceil($query->get_total()/20),'roles'=>$roles,'create_url'=>current_user_can('create_users')?admin_url('user-new.php'):''];
    }
    public static function suspend(int $id,bool $suspended): array
    {
        Access::require(current_user_can('ascla_manage'));
        Access::require($id>0 && $id!==get_current_user_id()&&!user_can($id,'manage_options')&&user_can($id,'ascla_access'),'Cuenta no disponible.',400);
        update_user_meta($id,'_ascla_suspended',$suspended);Audit::record($suspended?'member_suspended':'member_reactivated',$id);
        return ['id'=>$id,'suspended'=>$suspended];
    }
}
