<?php
namespace ASCLA\Core\Rest;
use ASCLA\Core\Services\{Access,Profiles,Matching,Messaging,ConversationRequests,Content,Events,Notifications,Settings,Media,Knowledge,Audit,Account,Locations};
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Jobs\Queue;
use ASCLA\Core\Integrations\GoogleOAuth;
final class Router
{
    public static function boot(): void { add_action('rest_api_init',[self::class,'routes']); }
    private static function permission(string $cap): true|\WP_Error
    {
        if (Access::member() && current_user_can($cap)) { return true; }
        return new \WP_Error('ascla_forbidden','Inicie sesión con una cuenta autorizada.',['status'=>is_user_logged_in()?403:401]);
    }
    private static function dispatch(\WP_REST_Request $request,callable $callback): mixed
    {
        try {
            if (strlen((string)$request->get_body())>1048576) { throw new ApiException('Solicitud demasiado grande.',413); }
            if ($request->get_method()!=='GET') { Access::limit('write',100); }
            return rest_ensure_response($callback($request));
        } catch (ApiException $e) {
            return new \WP_Error('ascla_error',$e->getMessage(),['status'=>$e->getCode()?:400]);
        } catch (\Throwable $e) {
            Audit::record('request_failed',0,get_class($e));
            return new \WP_Error('ascla_error',$e instanceof \RuntimeException?$e->getMessage():'No se pudo completar la operación. Reintente o contacte al administrador.',['status'=>500]);
        }
    }
    public static function route(string $path,string $methods,callable $callback,string $cap='ascla_access'): void
    {
        register_rest_route('ascla/v1',$path,[
            'methods'=>$methods,
            'permission_callback'=>static fn()=>self::permission($cap),
            'callback'=>static fn(\WP_REST_Request $request)=>self::dispatch($request,$callback),
        ]);
    }
    public static function routes(): void
    {
        RouterRoutes::community();
        RouterRoutes::notifications();
        RouterRoutes::jobs();
        RouterRoutes::administration();
    }
    private static function admin(): array
    {
        return RouterAdmin::build();
    }
}

final class RouterRoutes
{
    public static function community(): void
    {
        Router::route('/bootstrap','GET',static fn()=>['me'=>Profiles::visible(get_current_user_id()),'profile_completion'=>Profiles::completion(get_current_user_id()),'can_create'=>array_combine(array_keys(\ASCLA\Core\Domain\Catalog::TYPES),array_map([Content::class,'canCreate'],array_keys(\ASCLA\Core\Domain\Catalog::TYPES))),'birthday'=>\ASCLA\Core\Services\Birthdays::info(get_current_user_id()),'catalogs'=>Profiles::catalogs(),'admin_area'=>current_user_can('ascla_admin_area'),'moderator'=>current_user_can('ascla_moderate'),'executive'=>current_user_can('ascla_publish'),'admin'=>current_user_can('ascla_manage'),'demo'=>Settings::get()['demo'],'ai_mode'=>Knowledge::provider()->mode(),'google_connected'=>\ASCLA\Core\Integrations\Secrets::get('google_calendar_'.get_current_user_id())!=='','notification_cursor'=>Notifications::latestId()]);
        Router::route('/resource-authors','GET',static fn()=>\ASCLA\Core\Repositories\ContentQuery::authors());
        Router::route('/profiles','GET',static fn($r)=>Profiles::directory($r->get_params()));
        Router::route('/locations/countries','GET',static fn()=>Locations::countries());
        Router::route('/locations/cities','GET',static fn($r)=>Locations::cities((string)$r['country'],(string)($r['q']??''),rest_sanitize_boolean($r['exact']??false)));
        Router::route('/profiles/me','POST',static fn($r)=>Profiles::save($r->get_json_params()?:[]),'ascla_write');
        Router::route('/account/password','POST',static fn($r)=>Account::changePassword($r->get_json_params()?:[]),'ascla_write');
        Router::route('/account/password-reset','POST',static fn()=>Account::sendPasswordReset(),'ascla_write');
        Router::route('/profiles/(?P<id>\d+)','GET',static fn($r)=>\ASCLA\Core\Services\Connections::profile((int)$r['id']));
        Router::route('/matching','GET',static fn()=>Matching::recommendations());
        Router::route('/matching/(?P<id>\d+)','GET',static fn($r)=>Matching::between(get_current_user_id(),(int)$r['id'],$r->has_param('explain')?rest_sanitize_boolean($r['explain']):true));
        Router::route('/matching/(?P<id>\d+)/intro','GET',static fn($r)=>Matching::intro((int)$r['id']));
        Router::route('/connections','GET',static fn()=>\ASCLA\Core\Services\Connections::listing());
        Router::route('/connections/(?P<id>\d+)/respond','POST',static fn($r)=>\ASCLA\Core\Services\Connections::respond((int)$r['id'],(string)$r['decision']),'ascla_write');
        Router::route('/conversation-requests','GET',static fn()=>ConversationRequests::listing());
        Router::route('/conversation-requests','POST',static fn($r)=>ConversationRequests::request((int)$r['target'],(string)$r['body']),'ascla_write');
        Router::route('/conversation-requests/(?P<id>\d+)/respond','POST',static fn($r)=>ConversationRequests::respond((int)$r['id'],(string)$r['decision']),'ascla_write');
        Router::route('/conversation-requests/(?P<target>\d+)/cancel','POST',static fn($r)=>ConversationRequests::cancel((int)$r['target']),'ascla_write');
        Router::route('/relations','POST',static fn($r)=>Messaging::relation((int)$r['target'],(string)$r['kind'],rest_sanitize_boolean($r['active'])),'ascla_write');
        Router::route('/content/(?P<type>[a-z]+)','GET',static fn($r)=>Content::listing($r['type'],$r->get_params()));
        Router::route('/content/(?P<type>[a-z]+)','POST',static fn($r)=>Content::save($r['type'],$r->get_json_params()?:[]),'ascla_write');
        Router::route('/content/(?P<type>[a-z]+)/(?P<id>\d+)','POST',static fn($r)=>Content::save($r['type'],$r->get_json_params()?:[],(int)$r['id']),'ascla_write');
        Router::route('/items/(?P<id>\d+)','GET',static fn($r)=>Content::serialize(Content::get((int)$r['id'])));
        Router::route('/items/(?P<id>\d+)/video-duration','POST',static fn($r)=>Knowledge::browserVideoDuration((int)$r['id'],(int)($r['duration_seconds']??0)),'ascla_admin_area');
        Router::route('/items/(?P<id>\d+)','DELETE',static fn($r)=>Content::remove((int)$r['id']),'ascla_write');
        Router::route('/comments/(?P<id>\d+)','DELETE',static fn($r)=>Content::removeComment((int)$r['id']),'ascla_write');
        Router::route('/items/(?P<id>\d+)/comments','GET',static fn($r)=>Content::comments((int)$r['id']));
        Router::route('/items/(?P<id>\d+)/comments','POST',static fn($r)=>Content::comment((int)$r['id'],(string)$r['body'],(int)($r['parent']??0)),'ascla_write');
        Router::route('/comments/(?P<id>\d+)/reaction','POST',static fn($r)=>Content::reactComment((int)$r['id'],rest_sanitize_boolean($r['active'])),'ascla_write');
        Router::route('/comments/(?P<id>\d+)/report','POST',static fn($r)=>Content::reportComment((int)$r['id'],(string)$r['reason'],(string)($r['detail']??'')),'ascla_write');
        Router::route('/items/(?P<id>\d+)/reaction','POST',static fn($r)=>Content::react((int)$r['id'],(string)$r['kind'],rest_sanitize_boolean($r['active'])),'ascla_write');
        Router::route('/items/(?P<id>\d+)/report','POST',static fn($r)=>Content::report((int)$r['id'],(string)$r['reason'],(string)($r['detail']??'')),'ascla_write');
        Router::route('/items/(?P<id>\d+)/moderate','POST',static fn($r)=>Content::moderate((int)$r['id'],(string)$r['decision'],(string)$r['reason'],rest_sanitize_boolean($r['reviewed'])),'ascla_admin_area');
        Router::route('/conversations','GET',static fn($r)=>Messaging::conversations(Access::text($r['q']??'',120)));
        Router::route('/conversations','POST',static fn($r)=>Messaging::start((int)$r['target']),'ascla_write');
        Router::route('/conversations/group','POST',static fn($r)=>Messaging::createGroup((string)$r['title'],(array)($r['users']??[]),(int)($r['photo_id']??0),(string)($r['description']??'')),'ascla_write');
        Router::route('/conversations/(?P<id>\d+)/messages','GET',static fn($r)=>Messaging::messages((int)$r['id'],(int)$r['before'],$r->has_param('after')?(int)$r['after']:null));
        Router::route('/conversations/(?P<id>\d+)/messages','POST',static fn($r)=>Messaging::send((int)$r['id'],(string)$r['body']),'ascla_write');
        Router::route('/conversations/(?P<id>\d+)/messages/(?P<message>\d+)','DELETE',static fn($r)=>Messaging::removeMessage((int)$r['id'],(int)$r['message']),'ascla_write');
        Router::route('/events/(?P<id>\d+)','GET',static fn($r)=>Events::detail((int)$r['id']));
        Router::route('/events/(?P<id>\d+)/invite','POST',static fn($r)=>Events::invite((int)$r['id'],(array)$r['users']),'ascla_publish');
        Router::route('/events/(?P<id>\d+)/cancel','POST',static fn($r)=>Events::cancel((int)$r['id']),'ascla_publish');
        Router::route('/events/(?P<id>\d+)/register','POST',static fn($r)=>Events::register((int)$r['id'],(string)$r['status']),'ascla_write');
    }

    public static function notifications(): void
    {        Router::route('/notifications','GET',static fn()=>Notifications::list());
        Router::route('/notifications/feed','GET',static fn($r)=>Notifications::feed($r->get_params()));
        Router::route('/notifications/toasts','GET',static fn($r)=>Notifications::toastFeed((int)($r['after']??0)));
        Router::route('/notifications/summary','GET',static fn()=>Notifications::summary());
        Router::route('/notifications/read-all','POST',static fn()=>Notifications::readAll());
        Router::route('/notifications/(?P<id>\d+)/open','POST',static fn($r)=>Notifications::open((int)$r['id']));
        Router::route('/answers','GET',static fn()=>Queue::answers());
        Router::route('/conversations/(?P<id>\d+)','GET',static fn($r)=>Messaging::conversation((int)$r['id']));
        Router::route('/conversations/(?P<id>\d+)/group','POST',static fn($r)=>Messaging::updateGroup((int)$r['id'],(string)$r['title'],(string)($r['description']??''),(int)($r['photo_id']??0)),'ascla_write');
        Router::route('/conversations/(?P<id>\d+)','DELETE',static fn($r)=>Messaging::removeGroup((int)$r['id']),'ascla_write');
        Router::route('/notifications/(?P<id>\d+)/read','POST',static fn($r)=>Notifications::read((int)$r['id']));
        Router::route('/notifications/(?P<id>\d+)','DELETE',static fn($r)=>Notifications::delete((int)$r['id']));
    }

    public static function jobs(): void
    {        Router::route('/media','GET',static fn($r)=>Media::listing($r->get_params()));
        Router::route('/media/(?P<id>\d+)/discard','POST',static fn($r)=>Media::discard((int)$r['id']),'ascla_write');
        Router::route('/media/(?P<id>\d+)','DELETE',static fn($r)=>Media::remove((int)$r['id']),'ascla_write');
        Router::route('/media','POST',static function($r) { $files=$r->get_file_params(); return Media::upload($files['file']??[],$r->get_params()); },'ascla_write');
        Router::route('/ask','POST',static function($r) { Access::limit('ask',12,300); $question=trim(Access::text($r['question']??'',2000)); Access::require(mb_strlen($question)>=2,'Escriba una pregunta.',400); $thread=Queue::threadKey((string)($r['thread']??'')); Access::require($thread!=='','Conversación no válida.',400); return Queue::enqueue('answer',['question'=>$question,'thread'=>$thread]); });
        Router::route('/assistant/thread','GET',static fn($r)=>Queue::thread((string)($r['thread']??'')));
        Router::route('/jobs/(?P<id>\d+)','GET',static fn($r)=>Queue::get((int)$r['id']));
        Router::route('/jobs/(?P<id>\d+)/retry','POST',static fn($r)=>Queue::retry((int)$r['id']));
        Router::route('/jobs','POST',static function($r) {
            $kind=(string)$r['kind']; Access::require(in_array($kind,['multimedia','microevents','social','video_metadata'],true),'Tipo de trabajo no válido.',400);
            if ($kind==='microevents') { Access::require(Access::canPublish()); }
            if ($kind==='social') { Access::require(current_user_can('ascla_moderate')); }
            if (in_array($kind,['multimedia','video_metadata'],true)) { Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador pueden gestionar recursos.',403); Content::get((int)$r['resource_id']); }
            Access::limit('admin_job',10,300); return Queue::enqueue($kind,['resource_id'=>(int)$r['resource_id']]);
        },'ascla_admin_area');
        Router::route('/ai/test','POST',static function(){
            $provider=Settings::get()['ai_provider']??'mock';
            Access::require($provider!=='mock','Selecciona Google Gemini u OpenAI antes de probar la conexión.',400);
            return $provider==='openai'?\ASCLA\Core\Integrations\OpenAIProvider::test():\ASCLA\Core\Integrations\RealAIProvider::test();
        },'ascla_manage');
        Router::route('/admin/users','GET',static fn($r)=>\ASCLA\Core\Services\Administration::users($r->get_params()),'ascla_manage');
        Router::route('/admin/users','POST',static fn($r)=>\ASCLA\Core\Services\Administration::createUser($r->get_json_params()?:[]),'ascla_manage');
        Router::route('/admin/users/(?P<id>\d+)','GET',static fn($r)=>\ASCLA\Core\Services\Administration::user((int)$r['id']),'ascla_manage');
        Router::route('/admin/users/(?P<id>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Administration::updateUser((int)$r['id'],$r->get_json_params()?:[]),'ascla_manage');
        Router::route('/admin/users/(?P<id>\d+)','DELETE',static fn($r)=>\ASCLA\Core\Services\Administration::deleteUser((int)$r['id']),'ascla_manage');
        Router::route('/admin/interest-catalog','POST',static fn($r)=>!empty($r['seed'])?\ASCLA\Core\Domain\InterestCatalog::seed():\ASCLA\Core\Domain\InterestCatalog::create(Access::text($r['name']??'',100)),'ascla_manage');
        Router::route('/admin/interest-imports/ai-test','POST',static fn()=>\ASCLA\Core\Services\InterestImports::diagnoseAI(),'ascla_manage');
        Router::route('/admin/interest-imports','GET',static fn($r)=>\ASCLA\Core\Services\InterestImports::history((int)($r['page']??1)),'ascla_manage');
        Router::route('/admin/interest-imports/inspect','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::inspect($r->get_params()),'ascla_manage');
        Router::route('/admin/interest-imports','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::start($r->get_params()),'ascla_manage');
        Router::route('/admin/interest-imports/(?P<id>\d+)','GET',static fn($r)=>\ASCLA\Core\Services\InterestImports::detail((int)$r['id'],(int)($r['page']??1)),'ascla_manage');
        Router::route('/admin/interest-imports/(?P<id>\d+)/process','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::process((int)$r['id']),'ascla_manage');
        Router::route('/admin/interest-imports/(?P<id>\d+)/confirm','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::confirm((int)$r['id']),'ascla_manage');
        Router::route('/admin/interest-imports/(?P<id>\d+)/apply','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::apply((int)$r['id']),'ascla_manage');
        Router::route('/admin/interest-imports/(?P<id>\d+)/cancel','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::cancel((int)$r['id']),'ascla_manage');
        Router::route('/admin/interest-imports/(?P<id>\d+)/rows/(?P<row>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::review((int)$r['id'],(int)$r['row'],$r->get_params()),'ascla_manage');
        Router::route('/admin/interest-imports/(?P<id>\d+)/rows/(?P<row>\d+)/ai','POST',static fn($r)=>\ASCLA\Core\Services\InterestImports::classify((int)$r['id'],(int)$r['row']),'ascla_manage');
    }

    public static function administration(): void
    {        Router::route('/admin/statistics','GET',static fn($r)=>\ASCLA\Core\Services\Statistics::dashboard($r->get_params()),'ascla_publish');
        Router::route('/admin/statistics/topics','GET',static fn($r)=>\ASCLA\Core\Services\TopicInsights::summary($r->get_params()),'ascla_publish');
        Router::route('/admin/statistics/topics','POST',static fn($r)=>\ASCLA\Core\Services\TopicInsights::analyze($r->get_params()),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)','GET',static fn($r)=>\ASCLA\Core\Services\Attendance::roster((int)$r['id'],(int)($r['page']??1)),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Attendance::save((int)$r['id'],$r->get_params()),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)/complete','POST',static fn($r)=>\ASCLA\Core\Services\Attendance::complete((int)$r['id'],rest_sanitize_boolean($r['complete'])),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)/settings','POST',static fn($r)=>\ASCLA\Core\Services\Attendance::configure((int)$r['id'],$r->get_params()),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)/imports','GET',static fn($r)=>\ASCLA\Core\Services\Attendance::history((int)$r['id'],(int)($r['page']??1)),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)/imports/(?P<import>\d+)','GET',static fn($r)=>\ASCLA\Core\Services\Attendance::importDetail((int)$r['id'],(int)$r['import'],(int)($r['page']??1)),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)/imports/(?P<import>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Attendance::apply((int)$r['id'],(int)$r['import']),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)/preview','POST',static fn($r)=>\ASCLA\Core\Services\Attendance::preview((int)$r['id'],\ASCLA\Core\Domain\ZoomCsv::input($r['csv']??''),$r->get_params()),'ascla_publish');
        Router::route('/admin/attendance/(?P<id>\d+)/import','POST',static fn($r)=>\ASCLA\Core\Services\Attendance::import((int)$r['id'],\ASCLA\Core\Domain\ZoomCsv::input($r['csv']??''),Access::text($r['token']??'',64),$r->get_params()),'ascla_publish');
        Router::route('/admin/contacts','GET',static fn($r)=>\ASCLA\Core\Services\Administration::contacts($r->get_params()),'ascla_moderate');
        Router::route('/mail/test','POST',static fn()=>\ASCLA\Core\Integrations\Mailer::test(),'ascla_manage');
        Router::route('/settings','GET',static fn()=>Settings::status(),'ascla_manage');
        Router::route('/settings','POST',static fn($r)=>Settings::save($r->get_json_params()?:[]),'ascla_manage');
        Router::route('/admin','GET',static fn()=>RouterAdmin::build(),'ascla_admin_area');
        Router::route('/admin/reports/(?P<id>\d+)','DELETE',static fn($r)=>\ASCLA\Core\Services\Reports::remove((int)$r['id']),'ascla_moderate');
        Router::route('/admin/contact/(?P<id>\d+)','DELETE',static fn($r)=>\ASCLA\Core\Services\Administration::deleteContact((int)$r['id']),'ascla_moderate');
        Router::route('/admin/reports/(?P<id>\d+)/review','POST',static fn($r)=>Content::reviewReport((int)$r['id']),'ascla_moderate');
        Router::route('/admin/comments/(?P<id>\d+)','POST',static function($r) {
            $comment=get_comment((int)$r['id']); Access::require($comment && Content::get((int)$comment->comment_post_ID),'Comentario no válido.',404);
            $status=$r['decision']==='approve'?'approve':'hold'; wp_set_comment_status($comment->comment_ID,$status); Audit::record('comment_moderation',(int)$comment->comment_ID,$status); return ['ok'=>true];
        },'ascla_moderate');
        Router::route('/admin/contact/(?P<id>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Administration::contactStatus((int)$r['id'],(string)$r['status']),'ascla_moderate');
        Router::route('/admin/member/(?P<id>\d+)','POST',static fn($r)=>\ASCLA\Core\Services\Administration::suspend((int)$r['id'],rest_sanitize_boolean($r['suspended'])),'ascla_manage');
        Router::route('/demo','POST',static fn($r)=>\ASCLA\Core\Services\Demo::seed((string)$r['password']),'ascla_manage');
        Router::route('/google/connect','POST',static fn($r)=>GoogleOAuth::connect((string)$r['service']));
        Router::route('/google/disconnect','POST',static fn($r)=>GoogleOAuth::disconnect((string)$r['service']));
        Router::route('/events/(?P<id>\d+)/google','POST',static fn($r)=>GoogleOAuth::calendar((int)$r['id'],(string)$r['operation']));
        }
}


final class RouterAdmin
{
    public static function build(): array
    {
        $moderator=current_user_can('ascla_moderate');$admin=current_user_can('ascla_manage');
        $pending=self::pending();
        $comments=self::comments($moderator);
        $jobs=self::jobs($admin);
        $reports=self::reports($moderator,$admin);
        $audit=self::audit($admin);
        return ['pending'=>$pending,'comments'=>$comments,'reports'=>$reports,'jobs'=>$jobs,'audit'=>$audit,'counts'=>['members'=>count(get_users(['capability'=>'ascla_access','fields'=>'ID'])),'pending'=>count($pending)]];
    }

    private static function pending(): array
    {
        $pending=[];
        foreach (\ASCLA\Core\Domain\Catalog::TYPES as $key=>$label) {
            foreach (get_posts(['post_type'=>'ascla_'.$key,'post_status'=>['pending','draft'],'numberposts'=>50]) as $post) {
                if (Content::canRead($post)) { $pending[]=Content::serialize($post); }
            }
        }
        return $pending;
    }

    private static function comments(bool $moderator): array
    {
        $comments=[];
        foreach ($moderator?get_comments(['status'=>'hold','number'=>100]):[] as $comment) {
            $post=get_post($comment->comment_post_ID);
            if ($post && Content::canRead($post)) {
                $comments[]=['id'=>(int)$comment->comment_ID,'body'=>$comment->comment_content,'can_delete'=>Content::canDeleteComment($comment),'author'=>$comment->user_id?Profiles::publicName((int)$comment->user_id):'Comunidad ASCLA'];
            }
        }
        return $comments;
    }

    private static function jobs(bool $admin): array
    {
        $jobs=$admin?Store::rows('jobs'):Store::rows('jobs','user_id=%d',[get_current_user_id()]);
        foreach ($jobs as &$job) { unset($job['payload'],$job['result']); }
        unset($job);
        return $jobs;
    }

    private static function reports(bool $moderator,bool $admin): array
    {
        $reports=self::contentReports($moderator,$admin);
        $reports=array_merge($reports,self::commentReports($moderator,$admin));
        self::decorateReportReviewState($reports,$moderator);
        usort($reports,static function ($a,$b) {
            $reviewedOrder=($a['reviewed']?1:0)<=>($b['reviewed']?1:0);
            return $reviewedOrder!==0?$reviewedOrder:strcmp((string)($b['created_at']??''),(string)($a['created_at']??''));
        });
        return $reports;
    }

    private static function contentReports(bool $moderator,bool $admin): array
    {
        $reports=$moderator?Store::rows('relations',"kind='report'"):[];
        $reports=array_values(array_filter($reports,static function($report)use($admin){
            $post=get_post((int)$report['target_id']);
            return $admin || ($post&&Content::canRead($post));
        }));
        foreach ($reports as &$report) { self::decorateContentReport($report); }
        unset($report);
        return $reports;
    }

    private static function commentReports(bool $moderator,bool $admin): array
    {
        $reports=[];
        foreach ($moderator?Store::rows('relations',"kind='comment_report'"):[] as $report) {
            $decorated=self::commentReport($report,$admin);
            if ($decorated!==null) { $reports[]=$decorated; }
        }
        return $reports;
    }

    private static function decorateReportReviewState(array &$reports,bool $moderator): void
    {
        foreach ($reports as &$report) {
            $report['reviewed']=!empty($report['reviewed_at']);
            $report['can_delete']=$moderator && $report['reviewed'];
            $report['reviewed_by_name']=!empty($report['reviewed_by'])?Profiles::publicName((int)$report['reviewed_by']):'';
        }
        unset($report);
    }

    private static function decorateContentReport(array &$report): void
    {
        $post=get_post((int)$report['target_id']);
        $report['title']=$post?get_the_title($post):'Contenido no disponible';
        $report['reason_label']=Content::reportLabel((string)($report['reason']??''));
        $report['report_type']='content';
    }

    private static function commentReport(array $report,bool $admin): ?array
    {
        $comment=get_comment((int)$report['target_id']);
        $post=$comment?get_post((int)$comment->comment_post_ID):null;
        if (!$admin && (!$post || !Content::canRead($post))) { return null; }
        $author=$comment && (int)$comment->user_id>0?Profiles::publicName((int)$comment->user_id):'Comunidad ASCLA';
        $report['comment_id']=$comment?(int)$comment->comment_ID:0;
        $report['target_id']=$post?(int)$post->ID:0;
        $report['title']=$post?'Comentario de '.$author.' en “'.get_the_title($post).'”':'Comentario no disponible';
        $report['excerpt']=$comment?Access::excerpt((string)$comment->comment_content,180):'';
        $report['reason_label']=Content::reportLabel((string)($report['reason']??''));
        $report['report_type']='comment';
        return $report;
    }

    private static function audit(bool $admin): array
    {
        $audit=$admin?Store::rows('audit'):[];
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
        return $audit;
    }
}

