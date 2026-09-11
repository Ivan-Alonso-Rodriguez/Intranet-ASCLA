<?php
namespace ASCLA\Core\Rest;
use ASCLA\Core\Services\{Access,Profiles,Matching,Messaging,Content,Events,Notifications,Settings,Media,Knowledge,Audit};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Integrations\GoogleOAuth;
final class Router
{
    public static function boot(): void { add_action('rest_api_init',[self::class,'routes']); }
    private static function route(string $path,string $methods,callable $callback,string $cap='ascla_access'): void
    {
        register_rest_route('ascla/v1',$path,['methods'=>$methods,'permission_callback'=>static function () use($cap) {
            return Access::member()&&current_user_can($cap)?true:new \WP_Error('ascla_forbidden','Inicie sesión con una cuenta autorizada.',['status'=>is_user_logged_in()?403:401]);
        },'callback'=>static function (\WP_REST_Request $request) use($callback) {
            try {
                if (strlen((string)$request->get_body())>1048576) { throw new ApiException('Solicitud demasiado grande.',413); }
                if ($request->get_method()!=='GET') { Access::limit('write',100); }
                return rest_ensure_response($callback($request));
            } catch (ApiException $e) { return new \WP_Error('ascla_error',$e->getMessage(),['status'=>$e->getCode()?:400]); }
            catch (\Throwable $e) { Audit::record('request_failed',0,get_class($e)); return new \WP_Error('ascla_error',get_class($e)===\RuntimeException::class?$e->getMessage():'No se pudo completar la operación. Reintente o contacte al administrador.',['status'=>500]); }
        }]);
    }
    public static function routes(): void
    {
        self::route('/bootstrap','GET',static fn()=>['me'=>Profiles::visible(get_current_user_id()),'catalogs'=>Profiles::catalogs(),'moderator'=>current_user_can('ascla_moderate'),'admin'=>current_user_can('ascla_manage'),'demo'=>Settings::get()['demo'],'ai_mode'=>Knowledge::provider()->mode(),'google_connected'=>\ASCLA\Core\Integrations\Secrets::get('google_calendar_'.get_current_user_id())!=='']);
        self::route('/resource-authors','GET',static fn()=>\ASCLA\Core\Repositories\ContentQuery::authors());
        self::route('/profiles','GET',static fn($r)=>Profiles::directory($r->get_params()));
        self::route('/profiles/me','POST',static fn($r)=>Profiles::save($r->get_json_params()?:[]),'ascla_write');
        self::route('/profiles/(?P<id>\d+)','GET',static fn($r)=>Profiles::visible((int)$r['id']));
        self::route('/matching','GET',static fn()=>Matching::recommendations());
        self::route('/matching/(?P<id>\d+)','GET',static fn($r)=>Matching::between(get_current_user_id(),(int)$r['id']));
        self::route('/matching/(?P<id>\d+)/intro','GET',static fn($r)=>Matching::intro((int)$r['id']));
        self::route('/relations','POST',static fn($r)=>Messaging::relation((int)$r['target'],(string)$r['kind'],rest_sanitize_boolean($r['active'])),'ascla_write');
        self::route('/content/(?P<type>[a-z]+)','GET',static fn($r)=>Content::listing($r['type'],$r->get_params()));
        self::route('/content/(?P<type>[a-z]+)','POST',static fn($r)=>Content::save($r['type'],$r->get_json_params()?:[]),'ascla_write');
        self::route('/content/(?P<type>[a-z]+)/(?P<id>\d+)','POST',static fn($r)=>Content::save($r['type'],$r->get_json_params()?:[],(int)$r['id']),'ascla_write');
        self::route('/items/(?P<id>\d+)','GET',static fn($r)=>Content::serialize(Content::get((int)$r['id'])));
        self::route('/items/(?P<id>\d+)/comments','GET',static fn($r)=>Content::comments((int)$r['id']));
        self::route('/items/(?P<id>\d+)/comments','POST',static fn($r)=>Content::comment((int)$r['id'],(string)$r['body']),'ascla_write');
        self::route('/items/(?P<id>\d+)/reaction','POST',static fn($r)=>Content::react((int)$r['id'],(string)$r['kind'],rest_sanitize_boolean($r['active'])),'ascla_write');
        self::route('/items/(?P<id>\d+)/moderate','POST',static fn($r)=>Content::moderate((int)$r['id'],(string)$r['decision'],(string)$r['reason'],rest_sanitize_boolean($r['reviewed'])),'ascla_moderate');
        self::route('/conversations','GET',static fn($r)=>Messaging::conversations(Access::text($r['q']??'',120)));
        self::route('/conversations','POST',static fn($r)=>Messaging::start((int)$r['target']),'ascla_write');
        self::route('/conversations/(?P<id>\d+)/messages','GET',static fn($r)=>Messaging::messages((int)$r['id'],(int)$r['before']));
        self::route('/conversations/(?P<id>\d+)/messages','POST',static fn($r)=>Messaging::send((int)$r['id'],(string)$r['body']),'ascla_write');
        self::route('/events/(?P<id>\d+)','GET',static fn($r)=>Events::detail((int)$r['id']));
        self::route('/events/(?P<id>\d+)/invite','POST',static fn($r)=>Events::invite((int)$r['id'],(array)$r['users']),'ascla_moderate');
        self::route('/events/(?P<id>\d+)/register','POST',static fn($r)=>Events::register((int)$r['id'],(string)$r['status']),'ascla_write');
        self::route('/notifications','GET',static fn()=>Notifications::list());
        self::route('/notifications/feed','GET',static fn($r)=>Notifications::feed($r->get_params()));
        self::route('/notifications/summary','GET',static fn()=>Notifications::summary());
        self::route('/notifications/read-all','POST',static fn()=>Notifications::readAll());
        self::route('/notifications/(?P<id>\d+)/open','POST',static fn($r)=>Notifications::open((int)$r['id']));
        self::route('/answers','GET',static fn()=>Queue::answers());
        self::route('/conversations/(?P<id>\d+)','GET',static fn($r)=>Messaging::conversation((int)$r['id']));
        self::route('/notifications/(?P<id>\d+)/read','POST',static fn($r)=>Notifications::read((int)$r['id']));
        self::route('/media','POST',static function($r) { $files=$r->get_file_params(); return Media::upload($files['file']??[]); },'ascla_write');
        self::route('/ask','POST',static function($r) { Access::limit('ask',6,300); $question=trim(Access::text($r['question']??'',2000)); Access::require(mb_strlen($question)>=4,'Escriba una pregunta más específica.',400); return Queue::enqueue('answer',['question'=>$question]); });
        self::route('/jobs/(?P<id>\d+)','GET',static fn($r)=>Queue::get((int)$r['id']));
        self::route('/jobs/(?P<id>\d+)/retry','POST',static fn($r)=>Queue::retry((int)$r['id']));
        self::route('/jobs','POST',static function($r) {
            $kind=(string)$r['kind']; Access::require(in_array($kind,['multimedia','microevents','social','video_metadata'],true),'Tipo de trabajo no válido.',400);
            if ($kind==='microevents') { Access::require(current_user_can('ascla_manage')); }
            if (in_array($kind,['multimedia','video_metadata'],true)) { Content::get((int)$r['resource_id']); }
            Access::limit('admin_job',10,300); return Queue::enqueue($kind,['resource_id'=>(int)$r['resource_id']]);
        },'ascla_moderate');
        self::route('/settings','GET',static fn()=>Settings::status(),'ascla_manage');
        self::route('/settings','POST',static fn($r)=>Settings::save($r->get_json_params()?:[]),'ascla_manage');
        self::route('/admin','GET',static fn()=>self::admin(),'ascla_moderate');
        self::route('/admin/comments/(?P<id>\d+)','POST',static function($r) {
            $comment=get_comment((int)$r['id']); Access::require($comment && Content::get((int)$comment->comment_post_ID),'Comentario no válido.',404);
            $status=$r['decision']==='approve'?'approve':'hold'; wp_set_comment_status($comment->comment_ID,$status); Audit::record('comment_moderation',(int)$comment->comment_ID,$status); return ['ok'=>true];
        },'ascla_moderate');
        self::route('/admin/contact/(?P<id>\d+)','POST',static function($r) {
            $post=Content::get((int)$r['id']); Access::require($post->post_type==='ascla_contact','Solicitud no válida.',400);
            $status=(string)$r['status']; Access::require(in_array($status,['open','progress','closed'],true),'Estado no válido.',400); $meta=(array)get_post_meta($post->ID,'_ascla',true); $meta['request_status']=$status; update_post_meta($post->ID,'_ascla',$meta); Audit::record('contact_status',$post->ID,$status); return ['ok'=>true];
        },'ascla_moderate');
        self::route('/admin/member/(?P<id>\d+)','POST',static function($r) {
            $id=(int)$r['id']; Access::require($id!==get_current_user_id()&&!user_can($id,'manage_options')&&user_can($id,'ascla_access'),'Cuenta no disponible.',400); update_user_meta($id,'_ascla_suspended',rest_sanitize_boolean($r['suspended'])); Audit::record('member_suspended',$id); return ['ok'=>true];
        },'ascla_manage');
        self::route('/demo','POST',static fn($r)=>\ASCLA\Core\Services\Demo::seed((string)$r['password']),'ascla_manage');
        self::route('/google/connect','POST',static fn($r)=>GoogleOAuth::connect((string)$r['service']));
        self::route('/google/disconnect','POST',static fn($r)=>GoogleOAuth::disconnect((string)$r['service']));
        self::route('/events/(?P<id>\d+)/google','POST',static fn($r)=>GoogleOAuth::calendar((int)$r['id'],(string)$r['operation']));
    }
    private static function admin(): array
    {
        $pending=[];
        foreach (\ASCLA\Core\Domain\Catalog::TYPES as $key=>$label) {
            foreach (get_posts(['post_type'=>'ascla_'.$key,'post_status'=>['pending','draft'],'numberposts'=>50]) as $post) { $pending[]=Content::serialize($post); }
        }
        $comments=[];
        foreach (get_comments(['status'=>'hold','number'=>100]) as $c) { $post=get_post($c->comment_post_ID); if ($post&&str_starts_with($post->post_type,'ascla_')) { $comments[]=['id'=>(int)$c->comment_ID,'body'=>$c->comment_content,'author'=>$c->user_id?Profiles::publicName((int)$c->user_id):'Comunidad ASCLA']; } }
        $jobs=Store::rows('jobs'); foreach ($jobs as &$job) { unset($job['payload'],$job['result']); } unset($job);
        return ['pending'=>$pending,'comments'=>$comments,'reports'=>Store::rows('relations',"kind='report'"),'jobs'=>$jobs,'audit'=>Store::rows('audit'),'counts'=>['members'=>count(get_users(['capability'=>'ascla_access','fields'=>'ID'])),'pending'=>count($pending)]];
    }
}
