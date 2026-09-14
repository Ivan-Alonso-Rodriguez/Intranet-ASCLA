<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Integrations\{AIProviderInterface,MockAIProvider,RealAIProvider,OpenAIProvider,MockVideoProvider,YouTubeVideoProvider};
use ASCLA\Core\Domain\{Anonymizer,EntityRedactor,Grounding};
final class Knowledge
{
    private const IDENTITIES_SEPARATOR='/[\n,;]+/u';
    public static function provider(): AIProviderInterface
    {
        return match(Settings::get()['ai_provider']??'mock') {
            'gemini'=>new RealAIProvider(),
            'openai'=>new OpenAIProvider(),
            default=>new MockAIProvider(),
        };
    }
    public static function answer(string $question,array $history=[]): array
    {
        $question=Access::text($question,2000);
        $tokens=\ASCLA\Core\Repositories\KnowledgeSearch::tokens($question);
        $ranked=[]; $protected=false; $allIdentities=[];
        $posts=\ASCLA\Core\Repositories\KnowledgeSearch::candidates($tokens);
        foreach ($posts as $post) {
            if (!Content::canRead($post)) { continue; }
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            if (!empty($meta['generated'])&&empty($meta['reviewed'])) { continue; }
            $body=wp_strip_all_tags($post->post_content);
            if (!empty($meta['chatham'])) {
                $identities=preg_split(self::IDENTITIES_SEPARATOR,$meta['identities']??'')?:[];
                $protected=true; $allIdentities=array_merge($allIdentities,$identities);
                $body=Anonymizer::redact($body,$identities);
            }
            $title=!empty($meta['chatham'])?Anonymizer::redact($post->post_title,$identities):$post->post_title;
            $score=\ASCLA\Core\Repositories\KnowledgeSearch::score($title,$body,$tokens);
            if($score>0) $ranked[]=['id'=>$post->ID,'title'=>$title,'body'=>\ASCLA\Core\Repositories\KnowledgeSearch::excerpt($body,$tokens),'score'=>$score,'url'=>Content::serialize($post)['url'],'kind'=>'resource'];
        }
        usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['id']<=>$a['id']));

        $history=array_values(array_slice(array_filter($history,static fn($turn)=>is_array($turn)&&trim((string)($turn['question']??''))!==''&&trim((string)($turn['answer']??''))!==''),-6));
        $contextQuestion=$question;
        if($history && self::followUp($question)){
            $last=end($history);$contextQuestion.=' '.Access::text($last['question']??'',1000);
        }
        $context=AssistantContext::build($contextQuestion);
        $sources=[];$seen=[];
        // Live intranet context comes first for operational questions such as upcoming events.
        foreach(array_merge($context['sources'],array_slice($ranked,0,6)) as $source){
            $id=(int)($source['id']??0);if($id<=0||isset($seen[$id]))continue;
            $seen[$id]=true;$sources[]=$source;if(count($sources)>=8)break;
        }
        $conversation=self::conversational($question);
        if (!$sources && empty($context['answerable']) && !$conversation) {
            return ['answer'=>'No existe suficiente información en ASCLA para responder esta consulta. Prueba con una pregunta sobre eventos, publicaciones o recursos de la comunidad.','sources'=>[],'mode'=>self::provider()->mode(),'grounding'=>['policy'=>Grounding::POLICY,'context_source_ids'=>[],'valid_references'=>0,'ignored_references'=>0,'live_context_used'=>false,'conversation_only'=>false]];
        }
        $inputQuestion=$protected?EntityRedactor::redact($question,$allIdentities):$question;
        if($protected){ $history=EntityRedactor::tree($history,$allIdentities); }
        $result=self::provider()->generate('answer',['question'=>$inputQuestion,'sources'=>$sources,'live_context'=>$context['live'],'history'=>$history]);
        if($protected){ $result=EntityRedactor::tree($result,$allIdentities); }
        $verified=Grounding::answer($result,$sources);
        $verified['grounding']['live_context_used']=!empty($context['answerable']);
        $verified['grounding']['conversation_only']=$conversation&&!$sources&&!$context['answerable'];
        $verified['grounding']['snapshot_at']=current_datetime()->format(DATE_ATOM);
        if (!$verified['answer']) { return ['answer'=>'No pude preparar una respuesta verificable con la información disponible en ASCLA. Intenta reformular la pregunta.','sources'=>[],'mode'=>self::provider()->mode(),'grounding'=>$verified['grounding']]; }
        $answer=$protected?EntityRedactor::redact($verified['answer'],$allIdentities):$verified['answer'];
        if($protected){ Access::require(EntityRedactor::validateRedaction($answer,$allIdentities)['valid'],'La respuesta requiere revisión de anonimización.',502); }
        return ['answer'=>Access::text($answer,20000),'sources'=>array_map(static fn($source)=>['id'=>$source['id'],'title'=>$source['title'],'url'=>$source['url'],'kind'=>$source['kind']??'content'],$verified['sources']),'mode'=>self::provider()->mode(),'grounding'=>$verified['grounding']];
    }

    private static function conversational(string $question): bool
    {
        $plain=mb_strtolower(remove_accents(trim($question)));
        return (bool)preg_match('/^(hola|hello|hi|buenas|buenos dias|buenas tardes|buenas noches|gracias|thanks)\b|\b(que puedes hacer|como me ayudas|ayuda|help|what can you do)\b/u',$plain);
    }

    private static function followUp(string $question): bool
    {
        $plain=mb_strtolower(remove_accents(trim($question)));
        return mb_strlen($plain)<=120 && (bool)preg_match('/^(y|pero|entonces)\b|\b(cual|cuales|cuando|hora|donde|virtual|presencial|ese|esa|eso|este|esta|primero|primera|segundo|segunda|tambien|more|which|when|where|what time|that one|the first|the second)\b/u',$plain);
    }

    /** Revalidate saved answers against the currently readable, reviewed evidence. */
    public static function storedAnswer(array $result): array
    {
        $sources=[];$identities=[];$protected=false;
        $originalIds=array_values(array_unique(array_filter(array_map('intval',$result['grounding']['context_source_ids']??array_column($result['sources']??[],'id')),static fn($id)=>$id>0)));
        foreach ($originalIds as $sourceId) {
            $post=get_post($sourceId);
            if (!$post || $post->post_status!=='publish' || !Content::canRead($post)) { continue; }
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            if (!empty($meta['generated']) && empty($meta['reviewed'])) { continue; }
            $body=wp_strip_all_tags($post->post_content);$title=$post->post_title;
            if (!empty($meta['chatham'])) {
                $protected=true;$known=preg_split(self::IDENTITIES_SEPARATOR,$meta['identities']??'')?:[];
                $identities=array_merge($identities,$known);
                $body=EntityRedactor::redact($body,$known);$title=EntityRedactor::redact($title,$known);
            }
            $sources[]=['id'=>$post->ID,'body'=>$body,'title'=>$title,'url'=>Content::serialize($post)['url']];
        }
        if(!$originalIds){
            if(!empty($result['grounding']['live_context_used'])||!empty($result['grounding']['conversation_only'])){
                return ['answer'=>Access::text($result['answer']??'',20000),'sources'=>[],'mode'=>$result['mode']??'ASCLA','grounding'=>$result['grounding']??[]];
            }
            return ['answer'=>'No existe suficiente información en ASCLA para responder esta consulta.','sources'=>[],'mode'=>$result['mode']??'Fuentes actualizadas'];
        }
        if(count($sources)!==count($originalIds)) return ['answer'=>'Las fuentes de esta respuesta ya no están disponibles. Vuelve a consultar al Asistente ASCLA.','sources'=>[],'mode'=>$result['mode']??'Fuentes actualizadas'];
        $candidate=['answer'=>$result['answer']??'','source_ids'=>array_column($result['sources']??[],'id')];
        if($protected){ $candidate=EntityRedactor::tree($candidate,$identities); }
        $verified=Grounding::answer($candidate,$sources);
        $answer=$verified['answer']?:'No existe suficiente información verificable en las fuentes actuales. Puedes volver a consultar al Asistente ASCLA.';
        $verified['grounding']=array_merge($result['grounding']??[],$verified['grounding']);
        return ['answer'=>$answer,'sources'=>array_map(static fn($source)=>['id'=>$source['id'],'title'=>$source['title'],'url'=>$source['url']],$verified['sources']),'mode'=>$result['mode']??'Fuentes actualizadas','grounding'=>$verified['grounding']];
    }

    public static function videoMetadata(int $id): array
    {
        Access::require(Access::canPublish());
        $post=Content::get($id); $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_resource' && !empty($meta['video_id']),'Seleccione un recurso con video de YouTube.',400);
        $provider=Settings::get()['youtube_mode']==='real'?new YouTubeVideoProvider():new MockVideoProvider();
        $data=$provider->metadata($meta['video_id']);
        $meta['duration_seconds']=max(0,min(604800,(int)$data['duration_seconds']));
        $meta['thumbnail_url']=YouTubeVideoProvider::thumbnail($meta['video_id']);
        $meta['video_metadata_mode']=$data['mode'];
        update_post_meta($id,'_ascla',$meta); Audit::record('video_metadata_updated',$id,$data['mode']);
        return ['resource_id'=>$id,'mode'=>$data['mode'],'duration_seconds'=>$meta['duration_seconds'],'message'=>'Duración y miniatura actualizadas.'];
    }
    public static function multimedia(int $id,?AIProviderInterface $ai=null): array
    {
        Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador pueden generar recursos.',403);
        $ai=$ai??self::provider();
        $post=Content::get($id); Access::require($post->post_type==='ascla_resource','Seleccione un recurso.',400);
        $meta=(array)get_post_meta($id,'_ascla',true);
        [$transcript,$videoMode,$identities]=self::editorialContext($meta);
        $result=$ai->generate('multimedia',['source_id'=>$id,'transcript'=>$transcript,'chatham'=>!empty($meta['chatham'])]);
        if (!empty($meta['chatham'])) { $result=EntityRedactor::tree($result,$identities); }
        $result=Grounding::multimedia($result,$transcript);
        $summary=Access::text($result['summary']??'',15000); $note=Access::text($result['technical_note']??'',30000);
        Access::require($summary!==''&&$note!=='','La IA no devolvió resumen y nota técnica válidos.',502);
        if (!empty($meta['chatham'])) { $summary=Anonymizer::redact($summary,$identities); $note=Anonymizer::redact($note,$identities); }
        $derived=['resource_type'=>'Nota técnica','generated'=>true,'reviewed'=>false,'chatham'=>!empty($meta['chatham']),'source_id'=>$id,'summary'=>$summary,'copyright'=>Settings::get()['copyright'],'ai_mode'=>$ai->mode(),'video_mode'=>$videoMode,'infographic'=>$result['infographic']??[],'moments'=>$result['moments']??[],'excerpts'=>$result['excerpts']??[],'frameworks'=>$result['frameworks']??[],'norms'=>$result['norms']??[],'conclusions'=>$result['conclusions']??[],'concepts'=>$result['concepts']??[],'tags'=>$result['tags']??[],'demo_source_note'=>$meta['demo_source_note']??'','grounding'=>$result['grounding'],'redaction'=>['policy'=>'pre-and-post-entities-v1','review_required'=>true]];
        $derived=self::cleanGenerated($derived,!empty($meta['chatham']),$identities);
        // Copyright and provenance are controlled metadata, not model-generated prose.
        $derived['copyright']='© ASCLA – Asociación de Secretarios Corporativos de América Latina';
        $derived['ai_mode']=$ai->mode();
        if (!empty($meta['chatham'])) {
            $summary=EntityRedactor::redact($summary,$identities);$note=EntityRedactor::redact($note,$identities);
            Access::require(EntityRedactor::validateRedaction($summary.' '.$note.' '.wp_json_encode($result,JSON_UNESCAPED_UNICODE),$identities)['valid'],'El borrador requiere revisión adicional de identidades.',502);
        }
        // Keep only timed excerpts grounded in timestamps actually present in the source.
        $grounded=\ASCLA\Core\Domain\Transcript::moments($transcript,array_merge((array)($result['moments']??[]),(array)($result['excerpts']??[])));
        $grounded=array_map(static function($clip) use($id,$meta) {
            $clip['duration']=$clip['end']-$clip['start'];$clip['source_id']=$id;
            $clip['description']=$clip['title'];$clip['reason']=$clip['selection'];
            $clip['youtube_url']=empty($meta['video_id'])?'':'https://www.youtube.com/watch?v='.$meta['video_id'].'&t='.$clip['start'].'s';
            return $clip;
        },$grounded);
        $derived['moments']=$grounded; $derived['excerpts']=$grounded;
        $derived['video_id']=$meta['video_id']??'';
        $derived['thumbnail_url']=YouTubeVideoProvider::thumbnail($derived['video_id']);
        $derived['duration_seconds']=(int)($meta['duration_seconds']??0);
        $new=wp_insert_post(wp_slash(['post_type'=>'ascla_resource','post_title'=>'Nota técnica · '.(!empty($meta['chatham'])?'Sesión ASCLA':$post->post_title),'post_content'=>$note,'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
        if (is_wp_error($new)) { throw new \RuntimeException('No fue posible guardar el borrador.'); }
        update_post_meta($new,'_ascla',$derived);
        $topics=wp_get_object_terms($id,'ascla_interest',['fields'=>'ids']);
        if (!is_wp_error($topics)) { wp_set_object_terms($new,array_map('intval',$topics),'ascla_interest'); }
        $suggested=Access::text($result['suggested_hub']??$summary,10000);
        if (!empty($meta['chatham'])) { $suggested=Anonymizer::redact($suggested,$identities); }
        $hub=wp_insert_post(wp_slash(['post_type'=>'ascla_hub','post_title'=>'Ideas para conversar · Sesión ASCLA','post_content'=>$suggested,'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
        if (!is_wp_error($hub)) { update_post_meta($hub,'_ascla',['generated'=>true,'reviewed'=>false,'chatham'=>$derived['chatham'],'source_id'=>$new,'ai_mode'=>$ai->mode()]); }
        $capsules=self::capsules($grounded,$id,$meta,$derived,$summary,$topics,$ai);
        Audit::record('ai_generated',$new); return ['resource_id'=>$new,'capsule_ids'=>$capsules,'hub_id'=>is_wp_error($hub)?0:$hub,'mode'=>$ai->mode(),'video_mode'=>$videoMode,'message'=>'Borradores creados. Revise fuentes, anonimización y derechos antes de publicar.'];
    }
    private static function editorialContext(array $meta): array
    {
        $transcript=$meta['transcript']??''; $videoMode='Transcripción manual';
        if (!$transcript) {
            $provider=Settings::get()['youtube_mode']==='real'?new YouTubeVideoProvider():new MockVideoProvider();
            $video=$provider->transcript($meta['video_id']??''); $transcript=$video['text']; $videoMode=$video['mode'];
        }
        Access::require(mb_strlen($transcript)>=30,'La transcripción es insuficiente.',400);
        $identities=preg_split(self::IDENTITIES_SEPARATOR,$meta['identities']??'')?:[];
        if (!empty($meta['chatham'])) {
            foreach (get_users(['capability'=>'ascla_access']) as $user) {
                $p=(array)get_user_meta($user->ID,'_ascla_profile',true);
                $identities[]=$user->display_name; $identities[]=$p['company']??'';
            }
            $transcript=Anonymizer::redact($transcript,$identities);
        }
        return [$transcript,$videoMode,$identities];
    }
    private static function cleanGenerated(mixed $value,bool $chatham,array $identities): mixed
    {
        if (is_array($value)) { return array_map(static fn($item)=>self::cleanGenerated($item,$chatham,$identities),array_slice($value,0,80)); }
        if (is_string($value)) {
            $text=mb_substr(sanitize_textarea_field($value),0,10000);
            return $chatham?EntityRedactor::redact($text,$identities):$text;
        }
        return is_scalar($value)?$value:null;
    }
    private static function capsules(array $grounded,int $id,array $meta,array $derived,string $summary,array|\WP_Error $topics,AIProviderInterface $ai): array
    {
        $capsules=[];
        foreach (array_slice($grounded,0,3) as $index=>$clip) {
            $capsule=wp_insert_post(wp_slash(['post_type'=>'ascla_resource','post_title'=>'Cápsula '.($index+1).' · Sesión ASCLA','post_content'=>$summary,'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
            if (!is_wp_error($capsule)) {
                update_post_meta($capsule,'_ascla',['resource_type'=>'Podcast','generated'=>true,'reviewed'=>false,'chatham'=>$derived['chatham'],'source_id'=>$id,'video_id'=>$meta['video_id']??'','clip'=>$clip,'demo_source_note'=>$meta['demo_source_note']??'','duration_seconds'=>$clip['end']-$clip['start'],'thumbnail_url'=>YouTubeVideoProvider::thumbnail($meta['video_id']??''),'copyright'=>Settings::get()['copyright'],'ai_mode'=>$ai->mode()]);
                if (!is_wp_error($topics)) { wp_set_object_terms($capsule,array_map('intval',$topics),'ascla_interest'); }
                $capsules[]=$capsule;
            }
        }
        return $capsules;
    }

}
