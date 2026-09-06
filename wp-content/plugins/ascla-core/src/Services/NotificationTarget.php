<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\Catalog;
use ASCLA\Core\Repositories\Store;

/** Resolve notification destinations against the recipient's current permissions. */
final class NotificationTarget
{
    private const TYPES = [
        'message'=>['Mensajes','mail','mensajeria','Abrir mensajería'],
        'comment'=>['Comunidad','hub','hub','Ver el Hub'],
        'reaction'=>['Comunidad','heart','hub','Ver el Hub'],
        'mention'=>['Comunidad','hub','hub','Ver el Hub'],
        'moderation'=>['Publicaciones','shield','hub','Ver mis publicaciones'],
        'event'=>['Eventos','calendar','eventos','Ver eventos'],
        'microevent'=>['Eventos','calendar','eventos','Ver eventos'],
        'resource'=>['Conocimiento','book','centro-conocimiento','Explorar recursos'],
        'networking'=>['Tu red','users','directorio','Explorar directorio'],
        'connection'=>['Tu red','users','directorio','Explorar directorio'],
        'job'=>['Asistente y contenidos','spark','asistente','Ver mis consultas'],
        'job_error'=>['Asistente y contenidos','spark','asistente','Revisar consulta'],
        'welcome'=>['Comunidad','users','intranet','Explorar la comunidad'],
    ];

    public static function view(array $row): array
    {
        [$category,$icon,$page,$action]=self::TYPES[$row['kind']]??['ASCLA','bell','intranet','Ir al inicio'];
        $view=['id'=>(int)$row['id'],'kind'=>$row['kind'],'title'=>$row['label'],'description'=>'','category'=>$category,'icon'=>$icon,'url'=>Catalog::url($page),'action_label'=>$action,'available'=>true,'read_at'=>$row['read_at']??null,'created_at'=>$row['created_at']];
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
        if (preg_match('/^(?:resource|event):(\d+)$/',$row['event_key']??'',$match)) { return ['type'=>'post','id'=>(int)$match[1]]; }
        return [];
    }

    private static function unavailable(array $view): array
    {
        $view['title']='Este contenido ya no está disponible';
        $view['description']='Puede haber sido retirado o haber cambiado sus permisos. Puedes continuar en la sección.';
        $view['available']=false;
        $view['url']=add_query_arg('notice','unavailable',$view['url']);
        return $view;
    }

    private static function actor(array $context): string
    {
        $user=get_userdata(absint($context['actor']??0));
        return $user?Access::text($user->display_name,80):'Un asociado';
    }

    private static function post(array $view,array $context): array
    {
        $post=get_post(absint($context['id']??0));
        if (!$post || in_array($post->post_status,['trash','auto-draft'],true) || !Content::canRead($post)) { return self::unavailable($view); }
        $meta=(array)get_post_meta($post->ID,'_ascla',true);
        $actor=empty($meta['chatham'])?self::actor($context):'Un asociado';
        $view['title']=match($view['kind']) {
            'comment'=>$actor.' comentó en tu publicación',
            'reaction'=>$actor.' reaccionó a tu publicación',
            'mention'=>empty($meta['chatham'])?$actor.' te mencionó en el Hub':'Te mencionaron en una conversación privada',
            'resource'=>'Un nuevo recurso para tus intereses',
            'event'=>'Tienes una invitación a un evento',
            'microevent'=>'Tu círculo ASCLA te espera',
            default=>$view['title'],
        };
        $view['description']=Access::text($post->post_title,180);
        $view['url']=Catalog::url(Content::page(substr($post->post_type,6)),['item'=>$post->ID]);
        $view['action_label']=match($post->post_type) {
            'ascla_event'=>'Ver encuentro e invitación',
            'ascla_resource'=>'Abrir recurso',
            default=>'Ver publicación',
        };
        return $view;
    }

    private static function conversation(array $view,array $context): array
    {
        $id=absint($context['id']??0);
        if (!Store::one('conversations',$id) || !Store::count('participants','conversation_id=%d AND user_id=%d',[$id,get_current_user_id()])) { return self::unavailable($view); }
        $other=Store::rows('participants','conversation_id=%d AND user_id<>%d',[$id,get_current_user_id()],'LIMIT 1')[0]??null;
        $view['title']=self::actor(['actor'=>$other['user_id']??0]).' te envió un mensaje';
        $view['description']='Continúa la conversación privada en Mensajería.';
        $view['url']=Catalog::url('mensajeria',['conversation'=>$id]);
        $view['action_label']='Leer mensaje';
        return $view;
    }

    private static function profile(array $view,array $context): array
    {
        try {
            $profile=Profiles::visible(absint($context['id']??0));
            if (Messaging::blocked(get_current_user_id(),(int)$profile['id'])) { return self::unavailable($view); }
            if ($view['kind']==='networking' && (empty($profile['networking']) || empty(Profiles::raw(get_current_user_id())['networking']))) { return self::unavailable($view); }
        } catch (\ASCLA\Core\Rest\ApiException $e) { return self::unavailable($view); }
        $name=Access::text($profile['name'],80);
        $view['title']=$view['kind']==='connection'?$name.' quiere conectar contigo':'Una conexión para ti: '.$name;
        $view['description']='Conoce su experiencia y encuentra temas para conversar.';
        $view['url']=Catalog::url('perfil',['member'=>$profile['id']]);
        $view['action_label']='Ver perfil';
        return $view;
    }

    private static function job(array $view,array $context): array
    {
        $job=Store::one('jobs',absint($context['id']??0));
        if (!$job || ((int)$job['user_id']!==get_current_user_id() && !current_user_can('ascla_moderate'))) { return self::unavailable($view); }
        $payload=json_decode($job['payload'],true)?:[];
        $result=json_decode($job['result']??'null',true)?:[];
        if ($job['kind']==='answer') {
            $view['title']=match($job['status']) { 'error'=>'Tu consulta necesita atención', 'completed'=>'Tu respuesta del asistente está lista', default=>'Estamos preparando tu respuesta' };
            $view['description']=Access::text($payload['question']??'Consulta al asistente',180);
            $view['url']=Catalog::url('asistente',['job'=>(int)$job['id']]);
            $view['action_label']=$job['status']==='error'?'Revisar y reintentar':'Leer respuesta';
            return $view;
        }
        $id=absint($result['resource_id']??$result['items'][0]['draft_id']??0);
        if ($job['status']==='completed' && $id) {
            $view['title']=$job['kind']==='video_metadata'?'Los datos del video están actualizados':'Tu contenido está listo para revisar';
            return self::post($view,['id'=>$id]);
        }
        $view['title']=$job['status']==='error'?'Una tarea necesita revisión':'Tu tarea se ha completado';
        $view['description']=['microevents'=>'Propuesta de círculos de conversación','social'=>'Curaduría de contenidos','multimedia'=>'Resumen y derivados multimedia','video_metadata'=>'Datos del video'][$job['kind']]??'Revisa el estado y el resultado.';
        $view['url']=self::activityUrl();
        $view['action_label']='Revisar actividad';
        return $view;
    }

    private static function activityUrl(): string
    {
        return current_user_can('ascla_moderate')?admin_url('admin.php?page=ascla-ia'):Catalog::url('asistente',['history'=>1]);
    }

    private static function legacy(array $view): array
    {
        if (in_array($view['kind'],['job','job_error'],true)) {
            $view['title']='Revisa tus consultas y tareas anteriores';
            $view['description']='Este aviso anterior no guardó un resultado concreto. Abre tu historial para encontrarlo.';
            $view['url']=self::activityUrl();
            $view['action_label']='Abrir historial';
        } elseif ($view['kind']==='welcome') {
            $view['title']='Bienvenido a tu comunidad ASCLA';
            $view['description']='Encuentra encuentros, recursos y nuevas conexiones desde el inicio.';
        } else {
            $view['description']='Aviso anterior sin referencia al contenido. Puedes continuar en su sección.';
        }
        return $view;
    }
}
