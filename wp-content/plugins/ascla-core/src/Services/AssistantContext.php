<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\{EditorialPrivacy,EntityRedactor,Catalog};
use ASCLA\Core\Frontend\Language;

/**
 * Builds a small, permission-aware snapshot of the intranet for the assistant.
 * Only information the current member can already access is exposed to the AI provider.
 */
final class AssistantContext
{
    private const IDENTITIES_SEPARATOR='/[\n,;]+/u';

    public static function build(string $question,bool $videoQuestion=false): array
    {
        $plain=mb_strtolower(remove_accents($question));
        $intents=self::intents($plain);
        if ($videoQuestion) { $intents=array_fill_keys(array_keys($intents),false); }
        $live=[
            'current_datetime'=>current_datetime()->format(DATE_ATOM),
            'timezone'=>wp_timezone_string()?:'UTC',
            'language'=>Language::english()?'English':'Español',
            'organization'=>self::organization(),
            'unread_notifications'=>Notifications::summary()['unread_total'],
            'intents'=>$intents,
        ];
        $sources=[];
        self::appendIntentContext($live,$sources,$intents,$question);
        [$safe,$protected,$identities]=self::safeSources($sources);
        return ['live'=>$live,'sources'=>array_slice($safe,0,8),'answerable'=>in_array(true,$intents,true),'protected'=>$protected,'identities'=>$identities];
    }

    private static function intents(string $plain): array
    {
        $resourceTopic=preg_match('/\b(?:recursos?|resources?)\b.*?\b(?:sobre|acerca de|about|on)\s+\S/u',$plain);
        return [
            'organization'=>self::matchesAny($plain,[
                '/\b(?:cuentame|explicame|dime) (?:un poco |mas )?(?:sobre|acerca de|de) ascla\b/u',
                '/\b(?:que (?:es|significa|hace|ofrece|contiene|incluye)|que puedo (?:hacer|encontrar) en|para que sirve|a que se dedica|de que (?:trata|se trata)|hablame (?:de|sobre)) (?:la |esta )?(?:ascla|asociacion|intranet|plataforma|comunidad)\b/u',
                '/\b(?:que (?:hay|encuentro) en|que (?:secciones|modulos|servicios|herramientas) (?:hay|tiene|ofrece)) (?:la |esta )?(?:ascla|intranet|comunidad|plataforma)\b/u',
                '/\b(?:what is|what does|tell me about|what can i (?:do|find) (?:in|on)) (?:the )?(?:ascla|intranet|platform|association)\b/u',
                '/^\W*ascla\W*$/u',
            ]),
            'events'=>self::matchesAny($plain,[
                '/\b(evento|eventos|reunion|reuniones|encuentro|encuentros|capacitacion|capacitaciones|agenda|calendario|proximo|proximos|pronto|semana|mes|inscrito|inscripcion|cupos?|aforo|vacantes?|plazas?)\b/u',
                '/\b(event|events|meeting|meetings|training|calendar|upcoming|soon|week|month|registered|seats?|capacity|availability)\b/u',
            ]),
            'recent'=>!$resourceTopic && self::matchesAny($plain,[
                '/\b(reciente|recientes|nuevo|nuevos|nueva|nuevas|publicado|publicados|publicacion|publicaciones|novedad|novedades)\b/u',
                '/\b(contenido|contenidos|recurso|recursos|articulo|articulos|hub|conocimiento|ultimo|ultimos)\b/u',
                '/\b(recent|new|latest|published|posts|content|resources|knowledge)\b/u',
            ]),
            'people'=>self::matchesAny($plain,[
                '/\b(recomiend|recomendad|conectar|conexion|conexiones|persona|personas|asociado|asociados|networking|afinidad|contactar|conocer)\w*/u',
                '/\b(recommend|recommended|connect|connection|connections|people|member|members|networking|affinity|meet)\w*/u',
            ]),
            'notifications'=>self::matchesAny($plain,[
                '/\b(notificacion|notificaciones|aviso|avisos|pendiente|pendientes|sin leer)\b/u',
                '/\b(notification|notifications|unread|alerts?)\b/u',
            ]),
        ];
    }

    private static function organization(): array
    {
        $english=Language::english();
        $pages=Catalog::PAGES;
        if (Access::isAdministrator()) { unset($pages['intranet'],$pages['directorio'],$pages['mensajeria'],$pages['contacto']); }
        if (current_user_can('ascla_admin_area')) { $pages['admin']='Administración'; }
        return [
            'name'=>'Asociación de Secretarios Corporativos de América Latina',
            'overview'=>$english
                ? 'ASCLA brings together corporate secretaries in Latin America to exchange professional experience and knowledge about corporate governance. Its private intranet helps members learn, meet and collaborate.'
                : 'ASCLA reúne a profesionales de la secretaría corporativa de América Latina para intercambiar experiencias y conocimientos sobre gobierno corporativo. Su intranet es el espacio privado donde los asociados pueden formarse, encontrarse y colaborar.',
            'knowledge'=>$english
                ? 'The Knowledge Center contains member resources such as articles, documents and videos. Processed videos can include a summary, technical note and timestamped highlights.'
                : 'El Centro de Conocimiento reúne artículos, documentos y videos. Los videos procesados pueden incluir un resumen, una nota técnica y cápsulas con marcas de tiempo.',
            'sections'=>array_values(array_map([Language::class,'label'],$pages)),
        ];
    }

    public static function describe(array $live): string
    {
        $org=$live['organization'];
        $english=$live['language']==='English';
        return 'ASCLA '.($english?'stands for the ':'es la ').$org['name'].".\n\n".$org['overview']
            ."\n\n".($english?'Available sections':'Secciones disponibles').' · '.implode(', ',$org['sections']).".\n\n".$org['knowledge'];
    }

    private static function matchesAny(string $plain,array $patterns): bool
    {
        foreach($patterns as $pattern){
            if(preg_match($pattern,$plain)) {return true; }
        }
        return false;
    }

    private static function appendIntentContext(array &$live,array &$sources,array $intents,string $question): void
    {
        if($intents['events']){
            [$live['upcoming_events'],$eventSources]=self::events($question);
            $sources=array_merge($sources,$eventSources);
        }
        if($intents['recent']){
            [$live['recent_content'],$recentSources]=self::recent();
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
    }

    private static function safeSources(array $sources): array
    {
        $seen=[];$safe=[];$protected=false;$identities=[];
        foreach($sources as $source){
            $id=(int)($source['id']??0);
            if($id<=0||isset($seen[$id])) {continue; }
            $seen[$id]=true;$safe[]=$source;
            $meta=(array)get_post_meta($id,'_ascla',true);
            if (!empty($meta['chatham'])) {
                $protected=true;
                $identities=array_merge($identities,preg_split(self::IDENTITIES_SEPARATOR,(string)($meta['identities']??''))?:[]);
            }
        }
        return [$safe,$protected,array_values(array_unique($identities))];
    }

    private static function events(string $question): array
    {
        $matches=[];$upcoming=[];$now=time();$offset=0;
        $generic=['evento','eventos','reunion','reuniones','encuentro','encuentros','capacitacion','capacitaciones','cupos','cupo','disponibles','disponible','cuantos','cantidad','quedan','aforo','vacantes','plazas','proximos','proximo','pronto','semanas','semana','mes','todos','todas','detalle','detalles','seats','capacity','available','events','event','meeting','training'];
        $tokens=array_values(array_diff(\ASCLA\Core\Repositories\KnowledgeSearch::tokens($question),$generic));
        $historical=(bool)preg_match('/\b(?:anterior|anteriores|pasado|pasados|finalizado|finalizados|cancelado|cancelados|past|previous|cancelled|canceled)\b/u',mb_strtolower(remove_accents($question)));
        do {
            $posts=get_posts([
                'post_type'=>'ascla_event','post_status'=>'publish','numberposts'=>30,'offset'=>$offset,
                'meta_key'=>'_ascla_start','orderby'=>['meta_value'=>$historical?'DESC':'ASC','ID'=>'ASC'],
            ]);
            $offset+=30;
            foreach($posts as $post){
                if (!Content::canRead($post)) { continue; }
                $meta=(array)get_post_meta($post->ID,'_ascla',true);
                $view=EditorialPrivacy::reader($post->post_title,wp_strip_all_tags($post->post_content),$meta);
                $score=\ASCLA\Core\Repositories\KnowledgeSearch::score($view['title'],$view['body'].' '.($view['meta']['agenda']??''),$tokens);
                if ($score>0) { $matches[]=['post'=>$post,'score'=>$score]; }
                $end=strtotime((string)($meta['end']??''));
                if (count($upcoming)<8 && $end && ($historical?($end<=$now || !empty($meta['cancelled'])):($end>$now && empty($meta['cancelled'])))) {
                    $upcoming[]=['post'=>$post,'score'=>0];
                }
            }
        } while (count($posts)===30 && ($tokens || count($upcoming)<8));
        if ($matches) { usort($matches,static fn($a,$b)=>$b['score']<=>$a['score']); }
        $items=[];$sources=[];
        foreach (array_slice($matches?:$upcoming,0,8) as $selected) {
            [$item,$source]=self::eventData($selected['post']);
            $items[]=$item;$sources[]=$source;
        }
        return [$items,$sources];
    }

    public static function eventData(\WP_Post $post): array
    {
        $detail=Events::detail($post->ID);
        $meta=$detail['meta'];
        $humanStart=!empty($meta['start'])?wp_date('Y-m-d H:i T',strtotime($meta['start']),wp_timezone()):'';
        $humanEnd=!empty($meta['end'])?wp_date('Y-m-d H:i T',strtotime($meta['end']),wp_timezone()):'';
        $item=[
            'id'=>(int)$post->ID,'title'=>$detail['title'],'description'=>$detail['body'],
            'start'=>$humanStart,'end'=>$humanEnd,'timezone'=>wp_timezone_string(),
            'modality'=>$meta['modality']??'Virtual','location'=>$meta['location']??'',
            'meeting_url'=>$meta['url']??'','agenda'=>$meta['agenda']??'',
            'discussion'=>$meta['agenda_ai']??null,'topics'=>array_column($detail['tags'],'name'),
            'capacity'=>$detail['capacity'],'unlimited'=>$detail['capacity']===0,
            'attending'=>$detail['attending'],'reserved'=>$detail['reserved'],'remaining'=>$detail['remaining'],
            'waitlist_count'=>$detail['waitlist_count'],'registration'=>$detail['registered'],
            'registration_closed'=>$detail['registration_closed'],'is_past'=>$detail['is_past'],
            'cancelled'=>$detail['cancelled'],'cancellation_reason'=>$meta['cancellation_reason']??'',
            'registration_deadline'=>$meta['registration_deadline']??'','priority_until'=>!empty($meta['public_at'])?gmdate('c',(int)$meta['public_at']):'',
            'micro'=>!empty($meta['micro']),'chatham'=>!empty($meta['chatham']),'url'=>$detail['url'],
        ];
        $facts=[
            'Título'=>$item['title'],'Inicio'=>$humanStart,'Fin'=>$humanEnd,'Modalidad'=>$item['modality'],
            'Lugar'=>$item['location'],'Enlace autorizado para asistir'=>$item['meeting_url'],
            'Capacidad'=>$item['unlimited']?'Sin límite de cupos':$item['capacity'],
            'Cupos disponibles'=>$item['unlimited']?'Sin límite':$item['remaining'],
            'Asistencias confirmadas'=>$item['attending'],'Cupos ocupados o reservados'=>$item['reserved'],
            'Personas en lista de espera'=>$item['waitlist_count'],'Inscripción del usuario'=>$item['registration'],
            'Inscripciones cerradas'=>$item['registration_closed']?'Sí':'No','Finalizado'=>$item['is_past']?'Sí':'No',
            'Cancelado'=>$item['cancelled']?'Sí':'No','Motivo de cancelación'=>$item['cancellation_reason'],
            'Cierre de inscripciones'=>$item['registration_deadline'],'Programa'=>$item['agenda'],
            'Temas'=>implode(', ',$item['topics']),
        ];
        $text=[];
        foreach ($facts as $label=>$value) { if ($value!=='') { $text[]=$label.': '.$value; } }
        if ($item['discussion']) { $text[]=wp_json_encode($item['discussion'],JSON_UNESCAPED_UNICODE); }
        $text[]=$item['description'];
        $source=['id'=>$item['id'],'title'=>$item['title'],'body'=>implode("\n",$text),'score'=>100,'url'=>$item['url'],'kind'=>'event'];
        $source['event_digest']=hash('sha256',wp_json_encode($item));
        return [$item,$source];
    }

    private static function recent(): array
    {
        $posts=get_posts(['post_type'=>['ascla_hub','ascla_resource','ascla_gallery'],'post_status'=>'publish','numberposts'=>24,'orderby'=>'date','order'=>'DESC']);
        $items=[];$sources=[];
        foreach($posts as $post){
            if(!Content::canRead($post)) {continue; }
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            if(!empty($meta['generated'])&&empty($meta['reviewed'])) {continue; }
            [$title,$body,$digest]=self::safeText($post,$meta);
            $url=Content::serialize($post)['url'];
            $date=$post->post_date_gmt?:get_gmt_from_date($post->post_date);
            $kind=substr($post->post_type,6);
            $items[]=['id'=>(int)$post->ID,'type'=>$kind,'title'=>$title,'date'=>$date,'url'=>$url];
            $sources[]=['id'=>(int)$post->ID,'title'=>$title,'body'=>trim('Contenido ASCLA publicado el '.$date.'. '.mb_substr(wp_strip_all_tags($body),0,4500)),'score'=>80,'url'=>$url,'kind'=>$kind,'video_digest'=>$digest];
            if(count($items)>=6) {break; }
        }
        return [$items,$sources];
    }

    private static function people(): array
    {
        try{$rows=Matching::recommendations();}catch(\Throwable $e){return [];}
        $items=[];
        foreach(array_slice($rows,0,5) as $row){
            $id=(int)($row['id']??0);if(!$id) {continue; }
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

    public static function safeText(\WP_Post $post,array $meta): array
    {
        $title=$post->post_title;$body=wp_strip_all_tags($post->post_content);
        if(!empty($meta['chatham'])){
            $identities=preg_split(self::IDENTITIES_SEPARATOR,$meta['identities']??'')?:[];
            $title=EditorialPrivacy::title($title,$meta);$body=EntityRedactor::redact($body,$identities,[$title]);
        }
        $evidence=$post->post_type==='ascla_resource'?VideoEvidence::text($meta):'';
        if ($evidence!=='') { $body=trim($body."\n".$evidence); }
        return [$title,$body,$evidence===''?'':hash('sha256',$evidence)];
    }
}
