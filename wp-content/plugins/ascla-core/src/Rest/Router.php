<?php
namespace ASCLA\Core\Rest;
use ASCLA\Core\Services\{Access,Profiles,Matching,Messaging,ConversationRequests,Content,Events,Notifications,Settings,Media,Knowledge,Audit,Account,Locations};
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
        self::route('/bootstrap','GET',static fn()=>['me'=>Profiles::visible(get_current_user_id()),'profile_completion'=>Profiles::completion(get_current_user_id()),'birthday'=>\ASCLA\Core\Services\Birthdays::info(get_current_user_id()),'catalogs'=>Profiles::catalogs(),'moderator'=>current_user_can('ascla_moderate'),'executive'=>current_user_can('ascla_publish'),'admin'=>current_user_can('ascla_manage'),'demo'=>Settings::get()['demo'],'ai_mode'=>Knowledge::provider()->mode(),'google_connected'=>\ASCLA\Core\Integrations\Secrets::get('google_calendar_'.get_current_user_id())!=='']);
        self::route('/resource-authors','GET',static fn()=>\ASCLA\Core\Repositories\ContentQuery::authors());
        self::route('/profiles','GET',static fn($r)=>Profiles::directory($r->get_params()));
        self::route('/locations/countries','GET',static fn()=>Locations::countries());
        self::route('/locations/cities','GET',static fn($r)=>Locations::cities((string)$r['country'],(string)($r['q']??''),rest_sanitize_boolean($r['exact']??false)));
        self::route('/profiles/me','POST',static fn($r)=>Profiles::save($r->get_json_params()?:[]),'ascla_write');
        self::route('/account/password','POST',static fn($r)=>Account::changePassword($r->get_json_params()?:[]),'ascla_write');
        self::route('/account/password-reset','POST',static fn()=>Account::sendPasswordReset(),'ascla_write');
        self::route('/profiles/(?P<id>\d+)','GET',static fn($r)=>\ASCLA\Core\Services\Connections::profile((int)$r['id']));
        self::route('/matching','GET',static fn()=>Matching::recommendations());
        self::route('/matching/(?P<id>\d+)','GET',static fn($r)=>Matching::between(get_current_user_id(),(int)$r['id']));
        self::route('/matching/(?P<id>\d+)/intro','GET',static fn($r)=>Matching::intro((int)$r['id']));
        self::route('/connections','GET',static fn()=>\ASCLA\Core\Services\Connections::listing());
        self::route('/connections/(?P<id>\d+)/respond','POST',static fn($r)=>\ASCLA\Core\Services\Connections::respond((int)$r['id'],(string)$r['decision']),'ascla_write');
        self::route('/conversation-requests','GET',static fn()=>ConversationRequests::listing());
        self::route('/conversation-requests','POST',static fn($r)=>ConversationRequests::request((int)$r['target'],(string)$r['body']),'ascla_write');
        self::route('/conversation-requests/(?P<id>\d+)/respond','POST',static fn($r)=>ConversationRequests::respond((int)$r['id'],(string)$r['decision']),'ascla_write');
        self::route('/conversation-requests/(?P<target>\d+)/cancel','POST',static fn($r)=>ConversationRequests::cancel((int)$r['target']),'ascla_write');
        self::route('/relations','POST',static fn($r)=>Messaging::relation((int)$r['target'],(string)$r['kind'],rest_sanitize_boolean($r['active'])),'ascla_write');
        self::route('/content/(?P<type>[a-z]+)','GET',static fn($r)=>Content::listing($r['type'],$r->get_params()));
        self::route('/content/(?P<type>[a-z]+)','POST',static fn($r)=>Content::save($r['type'],$r->get_json_params()?:[]),'ascla_write');
        self::route('/content/(?P<type>[a-z]+)/(?P<id>\d+)','POST',static fn($r)=>Content::save($r['type'],$r->get_json_params()?:[],(int)$r['id']),'ascla_write');
        self::route('/items/(?P<id>\d+)','GET',static fn($r)=>Content::serialize(Content::get((int)$r['id'])));
        self::route('/items/(?P<id>\d+)/video-duration','POST',static fn($r)=>Knowledge::browserVideoDuration((int)$r['id'],(int)($r['duration_seconds']??0)),'ascla_admin_area');
        self::route('/items/(?P<id>\d+)','DELETE',static fn($r)=>Content::remove((int)$r['id']),'ascla_write');
        self::route('/comments/(?P<id>\d+)','DELETE',static fn($r)=>Content::removeComment((int)$r['id']),'ascla_write');
        self::route('/items/(?P<id>\d+)/comments','GET',static fn($r)=>Content::comments((int)$r['id']));
        self::route('/items/(?P<id>\d+)/comments','POST',static fn($r)=>Content::comment((int)$r['id'],(string)$r['body'],(int)($r['parent']??0)),'ascla_write');
        self::route('/comments/(?P<id>\d+)/reaction','POST',static fn($r)=>Content::reactComment((int)$r['id'],rest_sanitize_boolean($r['active'])),'ascla_write');
        self::route('/comments/(?P<id>\d+)/report','POST',static fn($r)=>Content::reportComment((int)$r['id'],(string)$r['reason'],(string)($r['detail']??'')),'ascla_write');
        self::route('/items/(?P<id>\d+)/reaction','POST',static fn($r)=>Content::react((int)$r['id'],(string)$r['kind'],rest_sanitize_boolean($r['active'])),'ascla_write');
        self::route('/items/(?P<id>\d+)/report','POST',static fn($r)=>Content::report((int)$r['id'],(string)$r['reason'],(string)($r['detail']??'')),'ascla_write');
        self::route('/items/(?P<id>\d+)/moderate','POST',static fn($r)=>Content::moderate((int)$r['id'],(string)$r['decision'],(string)$r['reason'],rest_sanitize_boolean($r['reviewed'])),'ascla_moderate');
        self::route('/conversations','GET',static fn($r)=>Messaging::conversations(Access::text($r['q']??'',120)));
        self::route('/conversations','POST',static fn($r)=>Messaging::start((int)$r['target']),'ascla_write');
        self::route('/conversations/group','POST',static fn($r)=>Messaging::createGroup((string)$r['title'],(array)($r['users']??[]),(int)($r['photo_id']??0),(string)($r['description']??'')),'ascla_write');
        self::route('/conversations/(?P<id>\d+)/messages','GET',static fn($r)=>Messaging::messages((int)$r['id'],(int)$r['before'],$r->has_param('after')?(int)$r['after']:null));
        self::route('/conversations/(?P<id>\d+)/messages','POST',static fn($r)=>Messaging::send((int)$r['id'],(string)$r['body']),'ascla_write');
        self::route('/conversations/(?P<id>\d+)/messages/(?P<message>\d+)','DELETE',static fn($r)=>Messaging::removeMessage((int)$r['id'],(int)$r['message']),'ascla_write');
        self::route('/events/(?P<id>\d+)','GET',static fn($r)=>Events::detail((int)$r['id']));
        self::route('/events/(?P<id>\d+)/invite','POST',static fn($r)=>Events::invite((int)$r['id'],(array)$r['users']),'ascla_moderate');
        self::route('/events/(?P<id>\d+)/cancel','POST',static fn($r)=>Events::cancel((int)$r['id']),'ascla_publish');
        self::route('/events/(?P<id>\d+)/register','POST',static fn($r)=>Events::register((int)$r['id'],(string)$r['status']),'ascla_write');
        self::route('/notifications','GET',static fn()=>Notifications::list());
        self::route('/notifications/feed','GET',static fn($r)=>Notifications::feed($r->get_params()));
        self::route('/notifications/summary','GET',static fn()=>Notifications::summary());
        self::route('/notifications/read-all','POST',static fn()=>Notifications::readAll());
        self::route('/notifications/(?P<id>\d+)/open','POST',static fn($r)=>Notifications::open((int)$r['id']));
        self::route('/answers','GET',static fn()=>Queue::answers());
        self::route('/conversations/(?P<id>\d+)','GET',static fn($r)=>Messaging::conversation((int)$r['id']));
        self::route('/conversations/(?P<id>\d+)/group','POST',static fn($r)=>Messaging::updateGroup((int)$r['id'],(string)$r['title'],(string)($r['description']??''),(int)($r['photo_id']??0)),'ascla_write');
        self::route('/conversations/(?P<id>\d+)','DELETE',static fn($r)=>Messaging::removeGroup((int)$r['id']),'ascla_write');
        self::route('/notifications/(?P<id>\d+)/read','POST',static fn($r)=>Notifications::read((int)$r['id']));
        self::route('/notifications/(?P<id>\d+)','DELETE',static fn($r)=>Notifications::delete((int)$r['id']));
        self::route('/media','GET',static fn($r)=>Media::listing($r->get_params()));
        self::route('/media/(?P<id>\d+)','DELETE',static fn($r)=>Media::remove((int)$r['id']),'ascla_write');
        self::route('/media','POST',static function($r) { $files=$r->get_file_params(); return Media::upload($files['file']??[],$r->get_params()); },'ascla_write');
        self::route('/ask','POST',static function($r) { Access::limit('ask',12,300); $question=trim(Access::text($r['question']??'',2000)); Access::require(mb_strlen($question)>=2,'Escriba una pregunta.',400); $thread=Queue::threadKey((string)($r['thread']??'')); Access::require($thread!=='','Conversación no válida.',400); return Queue::enqueue('answer',['question'=>$question,'thread'=>$thread]); });
        self::route('/assistant/thread','GET',static fn($r)=>Queue::thread((string)($r['thread']??'')));
        self::route('/jobs/(?P<id>\d+)','GET',static fn($r)=>Queue::get((int)$r['id']));
        self::route('/jobs/(?P<id>\d+)/retry','POST',static fn($r)=>Queue::retry((int)$r['id']));
        self::route('/jobs','POST',static function($r) {
            $kind=(string)$r['kind']; Access::require(in_array($kind,['multimedia','microevents','social','video_metadata'],true),'Tipo de trabajo no válido.',400);
            if ($kind==='microevents') { Access::require(current_user_can('ascla_manage')); }
            if ($kind==='social') { Access::require(current_user_can('ascla_moderate')); }
            if (in_array($kind,['multimedia','video_metadata'],true)) { Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador pueden gestionar recursos.',403); Content::get((int)$r['resource_id']); }
            Access::limit('admin_job',10,300); return Queue::enqueue($kind,['resource_id'=>(int)$r['resource_id']]);
        },'ascla_admin_area');
        self::route('/ai/test','POST',static function(){
            $provider=Settings::get()['ai_provider']??'mock';
            Access::require($provider!=='mock','Selecciona Google Gemini u OpenAI antes de probar la conexión.',400);
            return $provider==='openai'?\ASCLA\Core\Integrations\OpenAIProvider::test():\ASCLA\Core\Integrations\RealAIProvider::test();
        },'ascla_manage');
        self::route('/admin/users','GET',static fn($r)=>\ASCLA\Core\Services\Administration::users($r->get_params()),'ascla_manage');
        self::route('/admin/users','POST',static fn($r)=>\ASCLA\Core\Services\Administration::createUser($r->get_json_params()?:[]),'ascla_manage');
        self::route('/admin/users/(?P<id>\d+)','GET',static fn($r)=>\ASCLA\Core\Services\Administration::user((int)$r['id']),'ascla_manage');
        self::route('/admin/users/(?P<id>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Administration::updateUser((int)$r['id'],$r->get_json_params()?:[]),'ascla_manage');
        self::route('/admin/users/(?P<id>\d+)','DELETE',static fn($r)=>\ASCLA\Core\Services\Administration::deleteUser((int)$r['id']),'ascla_manage');
        self::route('/admin/contacts','GET',static fn($r)=>\ASCLA\Core\Services\Administration::contacts($r->get_params()),'ascla_moderate');
        self::route('/mail/test','POST',static fn()=>\ASCLA\Core\Integrations\Mailer::test(),'ascla_manage');
        self::route('/settings','GET',static fn()=>Settings::status(),'ascla_manage');
        self::route('/settings','POST',static fn($r)=>Settings::save($r->get_json_params()?:[]),'ascla_manage');
        self::route('/admin','GET',static fn()=>self::admin(),'ascla_admin_area');
        self::route('/admin/reports/(?P<id>\d+)/review','POST',static fn($r)=>Content::reviewReport((int)$r['id']),'ascla_moderate');
        self::route('/admin/comments/(?P<id>\d+)','POST',static function($r) {
            $comment=get_comment((int)$r['id']); Access::require($comment && Content::get((int)$comment->comment_post_ID),'Comentario no válido.',404);
            $status=$r['decision']==='approve'?'approve':'hold'; wp_set_comment_status($comment->comment_ID,$status); Audit::record('comment_moderation',(int)$comment->comment_ID,$status); return ['ok'=>true];
        },'ascla_moderate');
        self::route('/admin/contact/(?P<id>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Administration::contactStatus((int)$r['id'],(string)$r['status']),'ascla_moderate');
        self::route('/admin/member/(?P<id>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Administration::suspend((int)$r['id'],rest_sanitize_boolean($r['suspended'])),'ascla_manage');
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
        foreach (get_comments(['status'=>'hold','number'=>100]) as $c) { $post=get_post($c->comment_post_ID); if ($post&&str_starts_with($post->post_type,'ascla_')) { $comments[]=['id'=>(int)$c->comment_ID,'body'=>$c->comment_content,'can_delete'=>current_user_can('ascla_manage')||(int)$c->user_id===get_current_user_id(),'author'=>$c->user_id?Profiles::publicName((int)$c->user_id):'Comunidad ASCLA']; } }
        $jobs=Store::rows('jobs'); foreach ($jobs as &$job) { unset($job['payload'],$job['result']); } unset($job);
        $reports=Store::rows('relations',"kind='report'");
        foreach ($reports as &$report) {
            $post=get_post((int)$report['target_id']);
            $report['title']=$post?get_the_title($post):'Contenido no disponible';
            $report['reason_label']=Content::reportLabel((string)($report['reason']??''));
            $report['report_type']='content';
        }
        unset($report);
        foreach (Store::rows('relations',"kind='comment_report'") as $report) {
            $comment=get_comment((int)$report['target_id']);
            $post=$comment?get_post((int)$comment->comment_post_ID):null;
            $author=$comment && (int)$comment->user_id>0?Profiles::publicName((int)$comment->user_id):'Comunidad ASCLA';
            $report['comment_id']=$comment?(int)$comment->comment_ID:0;
            $report['target_id']=$post?(int)$post->ID:0;
            $report['title']=$post?'Comentario de '.$author.' en “'.get_the_title($post).'”':'Comentario no disponible';
            $report['excerpt']=$comment?Access::excerpt((string)$comment->comment_content,180):'';
            $report['reason_label']=Content::reportLabel((string)($report['reason']??''));
            $report['report_type']='comment';
            $reports[]=$report;
        }
        foreach ($reports as &$report) { $report['reviewed']=!empty($report['reviewed_at']); $report['reviewed_by_name']=!empty($report['reviewed_by'])?Profiles::publicName((int)$report['reviewed_by']):''; } unset($report);
        usort($reports,static fn($a,$b)=>(($a['reviewed']?1:0)<=>($b['reviewed']?1:0)) ?: strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
        $audit=Store::rows('audit');
        $actorNames=[];
        foreach ($audit as $row) {
            $actorId=(int)($row['actor_id']??0);
            if ($actorId>0 && !array_key_exists($actorId,$actorNames)) {
                $user=get_userdata($actorId);
                $actorNames[$actorId]=$user?Profiles::publicName($actorId):'Usuario eliminado';
            }
        }
        foreach ($audit as &$row) {
            $actorId=(int)($row['actor_id']??0);
            $row['actor_name']=$actorId>0?($actorNames[$actorId]??'Usuario'):'Sistema';
        }
        unset($row);
        return ['pending'=>$pending,'comments'=>$comments,'reports'=>$reports,'jobs'=>$jobs,'audit'=>$audit,'counts'=>['members'=>count(get_users(['capability'=>'ascla_access','fields'=>'ID'])),'pending'=>count($pending)]];
    }
}
