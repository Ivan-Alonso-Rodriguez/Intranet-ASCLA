<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Domain\Catalog;

/** RN-029 / RF-083..087: assignment, confidential history and ordered resolution. */
final class SupportRequests
{
    public const STATES=['open'=>'Pendiente','progress'=>'En revisión','resolved'=>'Resuelta','closed'=>'Cerrada'];
    private const META='_ascla_support_assignee';

    public static function staff(): array
    {
        $items=[];
        foreach (get_users(['capability'=>'ascla_access','orderby'=>'ID','order'=>'ASC']) as $user) {
            if (Access::member((int)$user->ID) && (user_can($user,'ascla_manage') || user_can($user,'ascla_publish'))) {
                $items[]=['id'=>(int)$user->ID,'name'=>$user->display_name,'admin'=>user_can($user,'ascla_manage')];
            }
        }
        return $items;
    }

    public static function initialize(int $id): void
    {
        if (get_post_type($id)!=='ascla_contact') { return; }
        $meta=(array)(get_post_meta($id,'_ascla',true)?:[]);
        if (($meta['request_version']??0)>=2) { return; }
        Store::lock('contact:'.$id,static fn()=>self::migrateRequest($id));
    }

    private static function migrateRequest(int $id): void
    {
        $meta=(array)(get_post_meta($id,'_ascla',true)?:[]);
        if (($meta['request_version']??0)>=2) { return; }
        $meta['request_status']=$meta['request_status']??'open';
        // In older releases closed meant "Resuelta"; preserve that meaning.
        if ($meta['request_status']==='closed') { $meta['request_status']='resolved'; }
        $meta['request_history']=self::normalizeHistory((array)($meta['request_history']??[]));
        $meta['request_version']=2;
        update_post_meta($id,'_ascla',$meta);
        if (!metadata_exists('post',$id,self::META)) { update_post_meta($id,self::META,self::defaultAssignee()); }
    }

    private static function normalizeHistory(array $history): array
    {
        foreach ($history as &$entry) {
            foreach (['from','to'] as $key) {
                if (($entry[$key]??'')==='closed') { $entry[$key]='resolved'; }
            }
        }
        unset($entry);
        return $history;
    }

    private static function defaultAssignee(): int
    {
        $admins=array_values(array_filter(self::staff(),static fn($staff)=>$staff['admin']));
        return (int)($admins[0]['id']??0);
    }

    public static function assignee(int $id): int
    {
        self::initialize($id);
        return (int)get_post_meta($id,self::META,true);
    }

    public static function canHandle(int $id): bool
    {
        return get_post_type($id)==='ascla_contact' && Access::member() && Access::canPublish() && self::assignee($id)===get_current_user_id();
    }

    public static function canRead(\WP_Post $post): bool
    {
        self::initialize($post->ID);
        return Access::member() && ($post->post_status==='private') &&
            ((int)$post->post_author===get_current_user_id() || self::canHandle($post->ID));
    }

    public static function assign(int $id,int $user): array
    {
        Access::require(Access::member() && current_user_can('ascla_manage'));
        $post=get_post($id);
        Access::require($post && $post->post_type==='ascla_contact' && $post->post_status==='private','Solicitud no encontrada.',404);
        Access::require(in_array($user,array_column(self::staff(),'id'),true),'Selecciona un administrador o ejecutivo activo.',400);
        return Store::lock('contact:'.$id,static function()use($id,$user,$post){
            $old=self::assignee($id);
            if ($old!==$user) {
                update_post_meta($id,self::META,$user);
                $meta=(array)get_post_meta($id,'_ascla',true);
                $meta['request_history'][]=['kind'=>'assignment','at'=>gmdate('c'),'actor'=>Profiles::publicName(get_current_user_id()),'actor_id'=>get_current_user_id(),'previous_assignee'=>$old,'assignee'=>$user];
                update_post_meta($id,'_ascla',$meta);
                Audit::record('contact_assigned',$id,'before='.$old.'; after='.$user);
                Notifications::send($user,'support_request','Se te asignó la solicitud #'.$id.'.',Catalog::url('administracion',['section'=>'solicitudes']),['type'=>'post','id'=>$id]);
            }
            return self::summary($post);
        });
    }

    public static function change(int $id,string $status,string $response=''): array
    {
        Access::require(self::canHandle($id),'Solo el responsable asignado puede atender esta solicitud.',403);
        Access::require(isset(self::STATES[$status]),'Estado no válido.',400);
        $response=trim(Access::text($response,10000));
        return Store::lock('contact:'.$id,static function()use($id,$status,$response){
            $post=Content::get($id);
            Access::require(self::canHandle($id),'La asignación cambió. Actualiza la vista.',403);
            $meta=(array)get_post_meta($id,'_ascla',true);$before=$meta['request_status']??'open';
            $next=['open'=>'progress','progress'=>'resolved','resolved'=>'closed'];
            Access::require($status===$before || ($next[$before]??'')===$status,'Respeta el orden Pendiente → En revisión → Resuelta → Cerrada.',409);
            Access::require($before!=='closed' || ($status===$before && $response===''),'La solicitud está cerrada.',409);
            Access::require($status!=='resolved' || $before==='resolved' || $response!=='','Registra la respuesta antes de resolver la solicitud.',400);
            if ($status!==$before || $response!=='') {
                $meta['request_status']=$status;$meta['request_updated_at']=gmdate('c');
                $meta['request_history'][]=['kind'=>'response','from'=>$before,'to'=>$status,'response'=>$response,'at'=>$meta['request_updated_at'],'actor'=>Profiles::publicName(get_current_user_id()),'actor_id'=>get_current_user_id()];
                update_post_meta($id,'_ascla',$meta);
                Audit::record('contact_status',$id,'before='.$before.'; after='.$status);
                Notifications::send((int)$post->post_author,'support_update','Tu solicitud #'.$id.' está '.self::STATES[$status].'.',Catalog::url('contacto'),['type'=>'post','id'=>$id,'actor'=>get_current_user_id()]);
            }
            return self::summary($post);
        });
    }

    public static function archive(int $id): array
    {
        Access::require(self::canHandle($id),'Solo el responsable asignado puede archivar esta solicitud.',403);
        return Store::lock('contact:'.$id,static function()use($id){
            Content::get($id);Access::require(self::canHandle($id),'La asignación cambió.',403);$meta=(array)get_post_meta($id,'_ascla',true);
            Access::require(($meta['request_status']??'open')==='closed','Cierra la solicitud antes de archivarla.',409);
            update_post_meta($id,'_ascla_request_archived',true);
            Audit::record('contact_archived',$id);
            return ['id'=>$id,'archived'=>true,'message'=>'Solicitud archivada. El historial sigue disponible.'];
        });
    }

    public static function summary(\WP_Post $post): array
    {
        self::initialize($post->ID);
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        $allowed=self::canRead($post);
        $item=$allowed?Content::serialize($post):['id'=>$post->ID,'date'=>get_post_time('c',true,$post),'meta'=>['request_status'=>$meta['request_status']??'open'],'redacted'=>true];
        $item['assignee']=self::assignee($post->ID);
        $item['can_handle']=self::canHandle($post->ID);
        $item['archived']=(bool)get_post_meta($post->ID,'_ascla_request_archived',true);
        return $item;
    }

    public static function listing(array $filter): array
    {
        Access::require(Access::member() && Access::canPublish());
        $state=Access::text($filter['state']??'',20);$q=trim(Access::text($filter['q']??'',120));
        Access::require($state==='' || isset(self::STATES[$state]),'Estado no válido.',400);
        $page=max(1,(int)($filter['page']??1));$items=[];$counts=array_fill_keys(array_keys(self::STATES),0);
        $admin=current_user_can('ascla_manage');$includeArchived=!empty($filter['archived']);
        foreach (get_posts(['post_type'=>'ascla_contact','post_status'=>'private','numberposts'=>-1,'orderby'=>'ID','order'=>'DESC']) as $post) {
            $entry=self::listingEntry($post,$admin,$includeArchived,$q);
            if ($entry===null) { continue; }
            $counts[$entry['state']]++;
            if ($state==='' || $state===$entry['state']) { $items[]=$entry['item']; }
        }
        $total=count($items);
        return ['items'=>array_slice($items,($page-1)*20,20),'page'=>$page,'pages'=>max(1,(int)ceil($total/20)),'total'=>$total,'counts'=>$counts,'staff'=>$admin?self::staff():[]];
    }

    private static function listingEntry(\WP_Post $post,bool $admin,bool $includeArchived,string $query): ?array
    {
        self::initialize($post->ID);
        $handle=self::canHandle($post->ID);
        if (!$admin && !$handle) { return null; }
        if (!$includeArchived && get_post_meta($post->ID,'_ascla_request_archived',true)) { return null; }
        // Searching private bodies must never disclose matches in unassigned cases.
        if ($query!=='' && (!$handle || mb_stripos($post->post_title.' '.$post->post_content,$query)===false)) { return null; }
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        return ['state'=>$meta['request_status']??'open','item'=>self::summary($post)];
    }

}
