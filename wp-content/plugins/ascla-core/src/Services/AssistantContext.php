<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\{Anonymizer,Catalog};
use ASCLA\Core\Frontend\Language;
use ASCLA\Core\Repositories\Store;

/**
 * Builds a small, permission-aware snapshot of the intranet for the assistant.
 * Only information the current member can already access is exposed to the AI provider.
 */
final class AssistantContext
{
    private const IDENTITIES_SEPARATOR='/[\n,;]+/u';

    public static function build(string $question): array
    {
        $plain=mb_strtolower(remove_accents($question));
        $intents=[
            'events'=>(bool)preg_match('/\b(evento|eventos|reunion|reuniones|encuentro|encuentros|capacitacion|capacitaciones|agenda|calendario|proximo|proximos|pronto|semana|mes|inscrito|inscripcion)\b/u',$plain),
            'recent'=>(bool)preg_match('/\b(reciente|recientes|nuevo|nuevos|nueva|nuevas|publicado|publicados|publicacion|publicaciones|novedad|novedades|contenido|contenidos|recurso|recursos|articulo|articulos|hub|conocimiento|ultimo|ultimos)\b/u',$plain),
            'people'=>(bool)preg_match('/\b(recomiend|recomendad|conectar|conexion|conexiones|persona|personas|asociado|asociados|networking|afinidad|contactar|conocer)\w*/u',$plain),
            'notifications'=>(bool)preg_match('/\b(notificacion|notificaciones|aviso|avisos|pendiente|pendientes|sin leer)\b/u',$plain),
        ];
        // English UI questions are supported too.
        $intents['events']=$intents['events']||(bool)preg_match('/\b(event|events|meeting|meetings|training|calendar|upcoming|soon|week|month|registered)\b/u',$plain);
        $intents['recent']=$intents['recent']||(bool)preg_match('/\b(recent|new|latest|published|posts|content|resources|knowledge)\b/u',$plain);
        $intents['people']=$intents['people']||(bool)preg_match('/\b(recommend|recommended|connect|connection|connections|people|member|members|networking|affinity|meet)\w*/u',$plain);
        $intents['notifications']=$intents['notifications']||(bool)preg_match('/\b(notification|notifications|unread|alerts?)\b/u',$plain);

        $live=[
            'current_datetime'=>current_datetime()->format(DATE_ATOM),
            'timezone'=>wp_timezone_string()?:'UTC',
            'language'=>Language::english()?'English':'Español',
            'unread_notifications'=>Notifications::summary()['unread_total'],
            'intents'=>$intents,
        ];
        $sources=[];

        if($intents['events']){
            [$events,$eventSources]=self::events();
            $live['upcoming_events']=$events;
            $sources=array_merge($sources,$eventSources);
        }
        if($intents['recent']){
            [$recent,$recentSources]=self::recent();
            $live['recent_content']=$recent;
            $sources=array_merge($sources,$recentSources);
        }
        if($intents['people']){
            $live['recommended_people']=self::people();
        }
        if($intents['notifications']){
            $feed=Notifications::feed(['filter'=>'unread','page'=>1]);
            $live['unread_items']=array_map(static fn($item)=>[
                'title'=>$item['title']??'',
                'description'=>$item['description']??'',
                'category'=>$item['category']??'',
                'created_at'=>$item['created_at']??'',
                'url'=>$item['url']??'',
            ],array_slice($feed['items']??[],0,5));
        }

        $seen=[];$safe=[];
        foreach($sources as $source){
            $id=(int)($source['id']??0);
            if($id<=0||isset($seen[$id]))continue;
            $seen[$id]=true;$safe[]=$source;
        }
        return ['live'=>$live,'sources'=>array_slice($safe,0,8),'answerable'=>in_array(true,$intents,true)];
    }

    private static function events(): array
    {
        $posts=get_posts([
            'post_type'=>'ascla_event','post_status'=>'publish','numberposts'=>30,
            'meta_key'=>'_ascla_start','orderby'=>'meta_value','order'=>'ASC',
        ]);
        $items=[];$sources=[];$now=time();$me=get_current_user_id();
        foreach($posts as $post){
            if(!Content::canRead($post))continue;
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            $end=strtotime((string)($meta['end']??''));
            if($end!==false&&$end<=$now)continue;
            $start=strtotime((string)($meta['start']??''));
            if($start===false)continue;
            $registration=Store::rows('registrations','event_id=%d AND user_id=%d',[$post->ID,$me],'LIMIT 1')[0]['status']??'none';
            [$title,$body]=self::safeText($post,$meta);
            $url=Content::serialize($post)['url'];
            $humanStart=wp_date('Y-m-d H:i T',$start,wp_timezone());
            $humanEnd=$end!==false?wp_date('Y-m-d H:i T',$end,wp_timezone()):'';
            $item=['id'=>(int)$post->ID,'title'=>$title,'start'=>$humanStart,'end'=>$humanEnd,'modality'=>$meta['modality']??'','location'=>$meta['location']??'','registration'=>$registration,'url'=>$url];
            $items[]=$item;
            $sources[]=['id'=>(int)$post->ID,'title'=>$title,'body'=>trim("Evento ASCLA. Inicio: {$humanStart}. Fin: {$humanEnd}. Modalidad: ".($meta['modality']??'').". Lugar: ".($meta['location']??'').". Estado de inscripción del usuario: {$registration}. ".$body),'score'=>100,'url'=>$url,'kind'=>'event'];
            if(count($items)>=8)break;
        }
        return [$items,$sources];
    }

    private static function recent(): array
    {
        $posts=get_posts(['post_type'=>['ascla_hub','ascla_resource','ascla_gallery'],'post_status'=>'publish','numberposts'=>24,'orderby'=>'date','order'=>'DESC']);
        $items=[];$sources=[];
        foreach($posts as $post){
            if(!Content::canRead($post))continue;
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            if(!empty($meta['generated'])&&empty($meta['reviewed']))continue;
            [$title,$body]=self::safeText($post,$meta);
            $url=Content::serialize($post)['url'];
            $date=$post->post_date_gmt?:get_gmt_from_date($post->post_date);
            $kind=substr($post->post_type,6);
            $items[]=['id'=>(int)$post->ID,'type'=>$kind,'title'=>$title,'date'=>$date,'url'=>$url];
            $sources[]=['id'=>(int)$post->ID,'title'=>$title,'body'=>trim('Contenido ASCLA publicado el '.$date.'. '.mb_substr(wp_strip_all_tags($body),0,4500)),'score'=>80,'url'=>$url,'kind'=>$kind];
            if(count($items)>=6)break;
        }
        return [$items,$sources];
    }

    private static function people(): array
    {
        try{$rows=Matching::recommendations();}catch(\Throwable $e){return [];}
        $items=[];
        foreach(array_slice($rows,0,5) as $row){
            $id=(int)($row['id']??0);if(!$id)continue;
            $items[]=[
                'id'=>$id,'name'=>$row['name']??Profiles::publicName($id),
                'position'=>$row['position']??'','company'=>$row['company']??'',
                'affinity'=>(int)($row['affinity']['score']??0),
                'shared'=>array_values(array_slice((array)($row['affinity']['shared']??[]),0,4)),
                'url'=>Catalog::url('perfil',['member'=>$id]),
            ];
        }
        return $items;
    }

    private static function safeText(\WP_Post $post,array $meta): array
    {
        $title=$post->post_title;$body=wp_strip_all_tags($post->post_content);
        if(!empty($meta['chatham'])){
            $identities=preg_split(self::IDENTITIES_SEPARATOR,$meta['identities']??'')?:[];
            $title=Anonymizer::redact($title,$identities);$body=Anonymizer::redact($body,$identities);
        }
        return [$title,$body];
    }
}
