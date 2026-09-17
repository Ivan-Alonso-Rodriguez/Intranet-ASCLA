<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Frontend\Language;
use ASCLA\Core\Frontend\App;
use ASCLA\Core\Repositories\Store;

/** Resolve notification destinations against the recipient's current permissions. */
final class NotificationTarget
{
    private const HUB_ACTION='Ver el Hub';
    private const TYPES = [
        'message'=>['Mensajes','mail','mensajeria','Abrir mensajería'],
        'conversation_group'=>['Mensajes','mail','mensajeria','Abrir grupo'],
        'conversation_request'=>['Tu red','mail','directorio','Revisar solicitud'],
        'conversation_accepted'=>['Tu red','mail','directorio','Abrir conversación'],
        'comment'=>['Comunidad','hub','hub',self::HUB_ACTION],
        'comment_reply'=>['Comunidad','reply','hub',self::HUB_ACTION],
        'reaction'=>['Comunidad','heart','hub',self::HUB_ACTION],
        'mention'=>['Comunidad','hub','hub',self::HUB_ACTION],
        'moderation'=>['Publicaciones','shield','hub','Ver mis publicaciones'],
        'event'=>['Eventos','calendar','eventos','Ver eventos'],
        'event_waitlist_available'=>['Eventos','calendar','eventos','Confirmar cupo'],
        'event_cancelled'=>['Eventos','calendar','eventos','Ver evento cancelado'],
        'event_updated'=>['Eventos','calendar','eventos','Ver cambios del evento'],
        'microevent'=>['Eventos','calendar','eventos','Ver eventos'],
        'resource'=>['Conocimiento','book','centro-conocimiento','Explorar recursos'],
        'networking'=>['Tu red','users','directorio','Explorar directorio'],
        'connection_accepted'=>['Tu red','users','directorio','Ver conexión'],
        'connection'=>['Tu red','users','directorio','Explorar directorio'],
        'support_request'=>['Soporte','contact','contacto','Revisar solicitud'],
        'support_received'=>['Soporte','contact','contacto','Ver mis solicitudes'],
        'support_update'=>['Soporte','contact','contacto','Ver estado'],
        'job'=>['Asistente y contenidos','spark','asistente','Ver mis consultas'],
        'job_error'=>['Asistente y contenidos','spark','asistente','Revisar consulta'],
        'welcome'=>['Comunidad','users','intranet','Explorar la comunidad'],
        'birthday'=>['Comunidad','users','directorio','Ver perfil'],
    ];

    private static function tr(string $es,string $en): string { return Language::text($es,$en); }

    public static function view(array $row): array
    {
        try { return self::resolve($row); }
        catch (\Throwable $error) {
            // A corrupt legacy row must not prevent other notices from being read.
            return ['id'=>(int)($row['id']??0),'kind'=>'unknown','title'=>self::tr('Aviso no disponible','Notification unavailable'),'label'=>self::tr('Aviso no disponible','Notification unavailable'),'description'=>self::tr('Abre la sección para consultar la actividad.','Open the section to review this activity.'),'category'=>'ASCLA','icon'=>'bell','url'=>Catalog::url('intranet'),'action_label'=>self::tr('Ir al inicio','Go to Home'),'available'=>false,'read_at'=>$row['read_at']??null,'created_at'=>$row['created_at']??null];
        }
    }

    private static function resolve(array $row): array
    {
        [$category,$icon,$page,$action]=self::TYPES[$row['kind']]??['ASCLA','bell','intranet','Ir al inicio'];
        $view=['id'=>(int)$row['id'],'kind'=>$row['kind'],'title'=>Language::label((string)$row['label']),'description'=>'','category'=>Language::label($category),'icon'=>$icon,'url'=>Catalog::url($page),'action_label'=>Language::label($action),'available'=>true,'read_at'=>$row['read_at']??null,'created_at'=>$row['created_at']];
        $context=self::context($row);
        $view=match($context['type']??'') {
            'post'=>self::post($view,$context),
            'conversation'=>self::conversation($view,$context),
            'profile'=>self::profile($view,$context),
            'job'=>self::job($view,$context),
            default=>self::legacy($view),
        };
        $view['url']=add_query_arg('notification',$view['id'],$view['url']);
        $view['label']=$view['title']; // Existing API consumers retain their label field.
        return $view;
    }

    private static function context(array $row): array
    {
        $context=json_decode($row['context']??'null',true);
        if (is_array($context) && !empty($context['type'])) { return $context; }
        parse_str((string)wp_parse_url($row['url']??'',PHP_URL_QUERY),$query);
        foreach (['item'=>'post','conversation'=>'conversation','member'=>'profile','job'=>'job'] as $key=>$type) {
            if (!empty($query[$key]) && is_scalar($query[$key])) { return ['type'=>$type,'id'=>absint($query[$key])]; }
        }
        return preg_match('/^(?:resource|event):(\d+)$/',$row['event_key']??'',$match)?['type'=>'post','id'=>(int)$match[1]]:[];
    }

    private static function unavailable(array $view): array
    {
        $view['title']=self::tr('Este contenido ya no está disponible','This content is no longer available');
        $view['description']=self::tr('Puede haber sido retirado o haber cambiado sus permisos. Puedes continuar en la sección.','It may have been removed or its permissions may have changed. You can continue in the section.');
        $view['available']=false;
        $view['url']=add_query_arg('notice','unavailable',$view['url']);
        return $view;
    }

    private static function actor(array $context): string
    {
        $user=get_userdata(absint($context['actor']??0));
        return $user?Access::excerpt(Profiles::publicName((int)$user->ID),80):self::tr('Un asociado','A member');
    }

    private static function post(array $view,array $context): array
    {
        $post=get_post(absint($context['id']??0));
        if (!$post || in_array($post->post_status,['trash','auto-draft'],true) || !Content::canRead($post)) { return self::unavailable($view); }
        if ($post->post_type==='ascla_contact') { return self::support($view,$context,$post); }
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        $actor=empty($meta['chatham'])?self::actor($context):self::tr('Un asociado','A member');
        $view['title']=match($view['kind']) {
            'comment'=>Language::english()?$actor.' commented on your post':$actor.' comentó en tu publicación',
            'comment_reply'=>Language::english()?$actor.' replied to your comment':$actor.' respondió a tu comentario',
            'reaction'=>Language::english()?$actor.' reacted to your post':$actor.' reaccionó a tu publicación',
            'mention'=>empty($meta['chatham'])?(Language::english()?$actor.' mentioned you in the Hub':$actor.' te mencionó en el Hub'):self::tr('Te mencionaron en una conversación privada','You were mentioned in a private conversation'),
            'resource'=>self::tr('Un nuevo recurso para tus intereses','A new resource for your interests'),
            'event'=>self::tr('Tienes una invitación a un evento','You have an event invitation'),
            'event_waitlist_available'=>self::tr('Se liberó un cupo para ti','A spot is available for you'),
            'event_cancelled'=>self::tr('Un evento fue cancelado','An event was cancelled'),
            'event_updated'=>self::tr('Un evento cambió información importante','An event has important updates'),
            'microevent'=>self::tr('Tu círculo ASCLA te espera','Your ASCLA circle is waiting'),
            default=>$view['title'],
        };
        $view['description']=Access::excerpt($post->post_title,180);
        if ($view['kind']==='event_updated') {
            $changes=is_array($context['changes']??null)?$context['changes']:[];
            if ($changes) {
                $labelsEs=['title'=>'nombre','start'=>'inicio','end'=>'finalización','modality'=>'modalidad','location'=>'ubicación','url'=>'enlace','capacity'=>'aforo'];
                $labelsEn=['title'=>'name','start'=>'start time','end'=>'end time','modality'=>'format','location'=>'location','url'=>'meeting link','capacity'=>'capacity'];
                $labels=Language::english()?$labelsEn:$labelsEs;
                $visible=array_values(array_filter(array_map(static fn($key)=>$labels[$key]??'',array_slice($changes,0,4))));
                if ($visible) {
                    $prefix=Language::english()?'Updated: ':'Cambios: ';
                    $view['description']=Access::excerpt($post->post_title.' · '.$prefix.implode(', ',$visible).(count($changes)>4?'…':''),180);
                }
            } elseif (!empty($context['note'])) {
                // Compatibility with notices created by a previous plugin version.
                $view['description']=Access::excerpt($post->post_title.' · '.$context['note'],180);
            }
        }
        $view['url']=Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$post->ID]);
        $view['action_label']=match($post->post_type) {
            'ascla_event'=>match($view['kind']) { 'event_waitlist_available'=>self::tr('Confirmar cupo','Confirm spot'), 'event_cancelled'=>self::tr('Ver evento cancelado','View cancelled event'), 'event_updated'=>self::tr('Revisar cambios','Review updates'), default=>self::tr('Ver encuentro e invitación','View event and invitation') },
            'ascla_resource'=>self::tr('Abrir recurso','Open resource'),
            default=>self::tr('Ver publicación','View post'),
        };
        return $view;
    }

    private static function support(array $view,array $context,\WP_Post $post): array
    {
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        $state=(string)($meta['request_status']??'open');
        $stateEs=['open'=>'Recibida','progress'=>'En atención','closed'=>'Resuelta'][$state]??'Recibida';
        $stateEn=['open'=>'Received','progress'=>'In progress','closed'=>'Resolved'][$state]??'Received';
        $stateLabel=Language::english()?$stateEn:$stateEs;
        $number='#'.$post->ID;
        if ($view['kind']==='support_request') {
            $actor=self::actor($context);
            $view['title']=Language::english()?$actor.' sent a new support request':$actor.' envió una nueva solicitud de soporte';
            $view['description']=$number.' · '.Access::excerpt($post->post_title,160);
            $view['url']=current_user_can('ascla_moderate')?App::adminUrl(['page'=>'ascla-solicitudes']):Catalog::url('contacto');
            $view['action_label']=self::tr('Revisar solicitud','Review request');
            return $view;
        }
        if ($view['kind']==='support_received') {
            $view['title']=self::tr('Recibimos tu solicitud de soporte','We received your support request');
            $view['description']=$number.' · '.Access::excerpt($post->post_title,140).' · '.$stateLabel;
            $view['url']=Catalog::url('contacto');
            $view['action_label']=self::tr('Ver mis solicitudes','View my requests');
            return $view;
        }
        if ($view['kind']==='support_update') {
            $view['title']=Language::english()?'Your request '.$number.' is now '.$stateLabel:'Tu solicitud '.$number.' ahora está '.$stateLabel;
            $view['description']=Access::excerpt($post->post_title,180);
            $view['url']=Catalog::url('contacto');
            $view['action_label']=self::tr('Ver estado','View status');
            return $view;
        }
        return $view;
    }

    private static function conversation(array $view,array $context): array
    {
        $id=absint($context['id']??0);
        try { $conversation=Messaging::conversation($id); } catch (\ASCLA\Core\Rest\ApiException $e) { return self::unavailable($view); }
        $actor=self::actor($context);
        if (($conversation['kind']??'direct')==='group') {
            $title=Access::excerpt((string)($conversation['title']??self::tr('Grupo ASCLA','ASCLA group')),100);
            $view['title']=$view['kind']==='conversation_group'
                ? (Language::english()?$actor.' added you to '.$title:$actor.' te añadió a '.$title)
                : (Language::english()?$actor.' wrote in '.$title:$actor.' escribió en '.$title);
            $view['description']=self::tr('Abre el chat grupal para ver la conversación.','Open the group chat to view the conversation.');
            $view['action_label']=self::tr('Abrir grupo','Open group');
        } else {
            if (empty($context['actor']) && !empty($conversation['other']['id'])) $actor=self::actor(['actor'=>$conversation['other']['id']]);
            if ($view['kind']==='conversation_request') {
                $view['title']=Language::english()?$actor.' sent you a conversation request':$actor.' te envió una solicitud de conversación';
                $view['description']=self::tr('Lee el primer mensaje y decide si deseas aceptar la conversación.','Read the first message and decide whether to accept the conversation.');
                $view['action_label']=self::tr('Revisar solicitud','Review request');
            } elseif ($view['kind']==='conversation_accepted') {
                $view['title']=Language::english()?$actor.' accepted your conversation request':$actor.' aceptó tu solicitud de conversación';
                $view['description']=self::tr('Ya puedes continuar la conversación en Mensajería.','You can now continue the conversation in Messages.');
                $view['action_label']=self::tr('Continuar conversación','Continue conversation');
            } else {
                $view['title']=Language::english()?$actor.' sent you a message':$actor.' te envió un mensaje';
                $view['description']=self::tr('Continúa la conversación privada en Mensajería.','Continue the private conversation in Messages.');
                $view['action_label']=self::tr('Leer mensaje','Read message');
            }
        }
        $view['url']=Catalog::url('mensajeria',['conversation'=>$id]);
        return $view;
    }

    private static function profile(array $view,array $context): array
    {
        try {
            $profile=Profiles::visible(absint($context['id']??0));
            $blocked=Messaging::blocked(get_current_user_id(),(int)$profile['id']);
            $optedOut=$view['kind']==='networking' && (empty($profile['networking']) || empty(Profiles::raw(get_current_user_id())['networking']));
            if ($blocked || $optedOut) { return self::unavailable($view); }
        } catch (\ASCLA\Core\Rest\ApiException $e) { return self::unavailable($view); }
        $name=Access::excerpt($profile['name'],80);
        if ($view['kind']==='birthday') {
            $view['title']=Language::english()?'🎉 Today is '.$name.'’s birthday':'🎉 Hoy cumple años '.$name;
            $view['description']=self::tr('Puedes abrir su perfil y enviarle un saludo desde ASCLA.','Open their profile and send a birthday greeting from ASCLA.');
            $view['url']=Catalog::url('perfil',['member'=>$profile['id']]);
            $view['action_label']=self::tr('Ver perfil','View profile');
            return $view;
        }
        if (in_array($view['kind'],['conversation_request','conversation_accepted'],true)) {
            $state=ConversationRequests::between(get_current_user_id(),(int)$profile['id']);
            $view['title']=match($state['state']) {
                'incoming_pending'=>Language::english()?$name.' wants to start a conversation with you':$name.' quiere iniciar una conversación contigo',
                'outgoing_pending'=>Language::english()?'Your conversation request to '.$name.' is pending':'Tu solicitud de conversación a '.$name.' está pendiente',
                'allowed'=>Language::english()?'You can now message '.$name:'Ya puedes conversar con '.$name,
                default=>self::tr('Solicitud de conversación cerrada','Conversation request closed'),
            };
            $view['description']=self::tr('Puedes aceptar la solicitud sin crear una conexión profesional.','You can accept the request without creating a professional connection.');
            $view['url']=Catalog::url('perfil',['member'=>$profile['id']]);
            $view['action_label']=$state['state']==='incoming_pending'?self::tr('Aceptar o rechazar solicitud','Accept or reject request'):($state['state']==='allowed'?self::tr('Enviar mensaje','Send message'):self::tr('Ver perfil','View profile'));
            return $view;
        }
        $state=Connections::between(get_current_user_id(),(int)$profile['id']);
        $view['title']=in_array($view['kind'],['connection','connection_accepted'],true)?match($state['state']) { 'incoming_pending'=>Language::english()?$name.' wants to connect with you':$name.' quiere conectar contigo', 'outgoing_pending'=>Language::english()?'Your request to '.$name.' is pending':'Tu solicitud a '.$name.' está pendiente', 'connected'=>Language::english()?'You are now connected with '.$name:'Ya estás conectado con '.$name, default=>self::tr('Solicitud de conexión cerrada','Connection request closed') }:(Language::english()?'A connection for you: '.$name:'Una conexión para ti: '.$name);
        $view['description']=self::tr('Conoce su experiencia y encuentra temas para conversar.','Explore their experience and find topics to discuss.');
        $view['url']=Catalog::url('perfil',['member'=>$profile['id']]);
        $view['action_label']=$state['state']==='incoming_pending'?self::tr('Aceptar o rechazar solicitud','Accept or reject request'):self::tr('Ver perfil','View profile');
        return $view;
    }

    private static function job(array $view,array $context): array
    {
        $job=Store::one('jobs',absint($context['id']??0));
        if (!$job || ((int)$job['user_id']!==get_current_user_id() && !current_user_can('ascla_manage'))) { return self::unavailable($view); }
        $payload=json_decode($job['payload'],true)?:[];
        $result=json_decode($job['result']??'null',true)?:[];
        if ($job['kind']==='answer') {
            $view['title']=match($job['status']) { 'error'=>self::tr('Tu consulta necesita atención','Your question needs attention'), 'completed'=>self::tr('Tu respuesta del asistente está lista','Your assistant answer is ready'), default=>self::tr('Estamos preparando tu respuesta','We are preparing your answer') };
            $view['description']=Access::excerpt($payload['question']??self::tr('Consulta al asistente','Assistant question'),180);
            $view['url']=Catalog::url('asistente',['job'=>(int)$job['id']]);
            $view['action_label']=$job['status']==='error'?self::tr('Revisar y reintentar','Review and retry'):self::tr('Leer respuesta','Read answer');
            return $view;
        }
        $id=absint($result['resource_id']??$result['items'][0]['draft_id']??0);
        return self::jobResult($view,$job,$id);
    }

    private static function jobResult(array $view,array $job,int $id): array
    {
        if ($job['status']==='completed' && $id) {
            $view['title']=$job['kind']==='video_metadata'?self::tr('Los datos del video están actualizados','The video data has been updated'):self::tr('Tu contenido está listo para revisar','Your content is ready to review');
            return self::post($view,['id'=>$id]);
        }
        $view['title']=$job['status']==='error'?self::tr('Una tarea necesita revisión','A task needs review'):self::tr('Tu tarea se ha completado','Your task is complete');
        $view['description']=Language::english()?(['microevents'=>'Conversation circle proposal','social'=>'Content curation','multimedia'=>'Summary and multimedia derivatives','video_metadata'=>'Video data'][$job['kind']]??'Review the status and result.'):(['microevents'=>'Propuesta de círculos de conversación','social'=>'Curaduría de contenidos','multimedia'=>'Resumen y derivados multimedia','video_metadata'=>'Datos del video'][$job['kind']]??'Revisa el estado y el resultado.');
        $view['url']=self::activityUrl();
        $view['action_label']=self::tr('Revisar actividad','Review activity');
        return $view;
    }

    private static function activityUrl(): string
    {
        return current_user_can('ascla_moderate')?App::adminUrl(['page'=>'ascla-ia']):Catalog::url('asistente',['history'=>1]);
    }

    private static function legacy(array $view): array
    {
        if (in_array($view['kind'],['job','job_error'],true)) {
            $view['title']=self::tr('Revisa tus consultas y tareas anteriores','Review your previous questions and tasks');
            $view['description']=self::tr('Este aviso anterior no guardó un resultado concreto. Abre tu historial para encontrarlo.','This older notification did not store a specific result. Open your history to find it.');
            $view['url']=self::activityUrl();
            $view['action_label']=self::tr('Abrir historial','Open history');
        } elseif ($view['kind']==='welcome') {
            $view['title']=self::tr('Bienvenido a tu comunidad ASCLA','Welcome to your ASCLA community');
            $view['description']=self::tr('Encuentra encuentros, recursos y nuevas conexiones desde el inicio.','Find events, resources and new connections from Home.');
        } else {
            $view['description']=self::tr('Aviso anterior sin referencia al contenido. Puedes continuar en su sección.','Older notification without a content reference. You can continue in its section.');
        }
        return $view;
    }
}
