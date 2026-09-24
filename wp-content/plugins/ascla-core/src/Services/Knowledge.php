<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Integrations\{AIProviderInterface,MockAIProvider,RealAIProvider,OpenAIProvider,MockVideoProvider,YouTubeVideoProvider};
use ASCLA\Core\Domain\{Anonymizer,EntityRedactor,Grounding};
final class Knowledge
{
    private const UPDATED_SOURCES_LABEL='Fuentes actualizadas';
    private const ABSTENTION='No existe suficiente información en ASCLA para responder esta consulta. Prueba con una pregunta sobre eventos, publicaciones o recursos de la comunidad.';
    private const IDENTITIES_SEPARATOR='/[\n,;]+/u';
    private const VIDEO_TOPICS=[
        'Gestión de riesgos'=>['/\bgesti[oó]n de riesgos?\b/iu','/\briesgos?\b/iu','/\brisk management\b/iu'],
        'Gobierno corporativo'=>['/\bgobierno corporativo\b/iu','/\bgobernanza corporativa\b/iu','/\bcorporate governance\b/iu'],
        'Inteligencia artificial'=>['/\binteligencia artificial\b/iu','/(?<![\p{L}\p{N}])IA(?![\p{L}\p{N}])/iu','/\bartificial intelligence\b/iu','/\bmachine learning\b/iu'],
        'Juntas directivas'=>['/\bjuntas? directivas?\b/iu','/\bdirectorio\b/iu','/\bconsejo de administraci[oó]n\b/iu','/\bboard of directors\b/iu'],
        'Sostenibilidad'=>['/\bsostenibilidad\b/iu','/\bsustentabilidad\b/iu','/(?<![\p{L}\p{N}])ESG(?![\p{L}\p{N}])/iu','/(?<![\p{L}\p{N}])ASG(?![\p{L}\p{N}])/iu'],
        'Transformación digital'=>['/\btransformaci[oó]n digital\b/iu','/\bdigitalizaci[oó]n\b/iu','/\binnovaci[oó]n digital\b/iu','/\btecnolog[ií]a digital\b/iu'],
    ];
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
            if($score>0) { $ranked[]=['id'=>$post->ID,'title'=>$title,'body'=>\ASCLA\Core\Repositories\KnowledgeSearch::excerpt($body,$tokens),'score'=>$score,'url'=>Content::serialize($post)['url'],'kind'=>'resource']; }
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
            $id=(int)($source['id']??0);if($id<=0||isset($seen[$id])) {continue; }
            $seen[$id]=true;$sources[]=$source;if(count($sources)>=8) {break; }
        }
        $conversation=self::conversational($question);
        if (!$sources && empty($context['answerable']) && !$conversation) {
            return ['answer'=>self::ABSTENTION,'sources'=>[],'mode'=>self::provider()->mode(),'grounding'=>['policy'=>Grounding::POLICY,'context_source_ids'=>[],'valid_references'=>0,'ignored_references'=>0,'live_context_used'=>false,'conversation_only'=>false]];
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
        return (bool)preg_match('/(?:^(hola|hello|hi|buenas|buenos dias|buenas tardes|buenas noches|gracias|thanks)\b)|(?:\b(que puedes hacer|como me ayudas|ayuda|help|what can you do)\b)/u',$plain);
    }

    private static function followUp(string $question): bool
    {
        $plain=mb_strtolower(remove_accents(trim($question)));
        return mb_strlen($plain)<=120 && (bool)preg_match('/(?:^(y|pero|entonces)\b)|(?:\b(cual|cuales|cuando|hora|donde|virtual|presencial|ese|esa|eso|este|esta|primero|primera|segundo|segunda|tambien|more|which|when|where|what time|that one|the first|the second)\b)/u',$plain);
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
            return ['answer'=>self::ABSTENTION,'sources'=>[],'mode'=>$result['mode']??self::UPDATED_SOURCES_LABEL];
        }
        if(count($sources)!==count($originalIds)) { return ['answer'=>'Las fuentes de esta respuesta ya no están disponibles. Vuelve a consultar al Asistente ASCLA.','sources'=>[],'mode'=>$result['mode']??self::UPDATED_SOURCES_LABEL]; }
        $candidate=['answer'=>$result['answer']??'','source_ids'=>array_column($result['sources']??[],'id')];
        if($protected){ $candidate=EntityRedactor::tree($candidate,$identities); }
        $verified=Grounding::answer($candidate,$sources);
        $answer=$verified['answer']?:'No existe suficiente información verificable en las fuentes actuales. Puedes volver a consultar al Asistente ASCLA.';
        $verified['grounding']=array_merge($result['grounding']??[],$verified['grounding']);
        return ['answer'=>$answer,'sources'=>array_map(static fn($source)=>['id'=>$source['id'],'title'=>$source['title'],'url'=>$source['url']],$verified['sources']),'mode'=>$result['mode']??self::UPDATED_SOURCES_LABEL,'grounding'=>$verified['grounding']];
    }

    public static function videoMetadata(int $id): array
    {
        Access::require(Access::canPublish());
        return self::syncVideoMetadata($id,true);
    }
    /** Refresh video duration/thumbnail without making content saves depend on an external API. */
    public static function autoVideoMetadata(int $id): void
    {
        if (!Access::canPublish()) { return; }
        try { self::syncVideoMetadata($id,false); } catch (\Throwable $e) { /* best effort */ }
    }
    /** Store a duration obtained from YouTube's official IFrame Player API in the authenticated browser. */
    public static function browserVideoDuration(int $id,int $seconds): array
    {
        Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador pueden actualizar datos del video.',403);
        Access::require($seconds>0 && $seconds<=604800,'Duración de video no válida.',400);
        $post=Content::get($id); $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_resource' && !empty($meta['video_id']),'Seleccione un recurso con video de YouTube.',400);
        $meta['duration_seconds']=$seconds;
        $meta['video_metadata_mode']='YouTube Player API (navegador)';
        $meta['video_metadata_checked_at']=current_datetime()->format(DATE_ATOM);
        $meta['thumbnail_url']=YouTubeVideoProvider::thumbnail((string)$meta['video_id']);
        update_post_meta($id,'_ascla',$meta);
        Audit::record('video_metadata_updated',$id,'YouTube Player API (navegador)');
        return ['resource_id'=>$id,'mode'=>$meta['video_metadata_mode'],'duration_seconds'=>$seconds,'message'=>'Duración verificada directamente con el reproductor de YouTube.'];
    }
    private static function syncVideoMetadata(int $id,bool $strict): array
    {
        $post=Content::get($id); $meta=(array)get_post_meta($id,'_ascla',true);
        Access::require($post->post_type==='ascla_resource' && !empty($meta['video_id']),'Seleccione un recurso con video de YouTube.',400);
        $duration=0; $mode=''; $title='';
        $previousMode=(string)($meta['video_metadata_mode']??'');
        $previousDuration=str_contains($previousMode,'transcripci')?0:max(0,min(604800,(int)($meta['duration_seconds']??0)));
        $apiError=null;
        // Metadata is independent from transcript mode: if this account has YouTube OAuth,
        // always prefer the official videos.list contentDetails.duration value.
        try {
            $data=(new YouTubeVideoProvider())->metadata($meta['video_id']);
            $duration=max(0,min(604800,(int)($data['duration_seconds']??0)));
            $mode=(string)($data['mode']??'API REAL YouTube');
            $title=sanitize_text_field($data['source_title']??'');
        } catch (\Throwable $e) { $apiError=$e; }
        if ($duration<=0) {
            try {
                $data=YouTubeVideoProvider::publicMetadata($meta['video_id']);
                $duration=max(0,min(604800,(int)($data['duration_seconds']??0)));
                $mode=(string)($data['mode']??'Metadatos públicos de YouTube');
            } catch (\Throwable $e) {
                if ($strict && $previousDuration<=0) {
                    throw new \RuntimeException('No se pudo verificar la duración directamente desde YouTube. Conecta YouTube OAuth y vuelve a intentar; ASCLA no estimará la duración usando la transcripción.');
                }
            }
        }
        if ($duration<=0) { $duration=$previousDuration; }
        if ($duration>0) { $meta['duration_seconds']=$duration; }
        else { unset($meta['duration_seconds']); if (str_contains($previousMode,'transcripci')) { unset($meta['video_metadata_mode']); } }
        $meta['thumbnail_url']=YouTubeVideoProvider::thumbnail($meta['video_id']);
        if ($mode!=='') { $meta['video_metadata_mode']=$mode; }
        if ($title!=='') { $meta['video_source_title']=$title; }
        update_post_meta($id,'_ascla',$meta);
        if ($mode!=='') { Audit::record('video_metadata_updated',$id,$mode); }
        return ['resource_id'=>$id,'mode'=>$mode?:($meta['video_metadata_mode']??'Datos conservados'),'duration_seconds'=>$duration,'message'=>$duration>0?'Duración y miniatura actualizadas automáticamente.':'No fue posible detectar la duración; se conservaron los datos disponibles.'];
    }
    public static function multimedia(int $id,?AIProviderInterface $ai=null): array
    {
        Access::require(Access::canPublish(),'Solo un Ejecutivo o un administrador pueden generar recursos.',403);
        $ai=$ai??self::provider();
        $post=Content::get($id); Access::require($post->post_type==='ascla_resource','Seleccione un recurso.',400);
        $meta=(array)get_post_meta($id,'_ascla',true);
        [$transcript,$videoMode,$identities]=self::editorialContext($id,$meta);

        // Before generating excerpts, refresh the real YouTube duration whenever OAuth/API is available.
        self::autoVideoMetadata($id);
        $meta=(array)get_post_meta($id,'_ascla',true);
        $durationSeconds=(int)($meta['duration_seconds']??0);
        // Never infer the video length from captions. If YouTube cannot verify it, keep it unknown and do not create timed capsules.
        if ($durationSeconds<=0) { $durationSeconds=0; }

        $result=$ai->generate('multimedia',['source_id'=>$id,'transcript'=>$transcript,'chatham'=>!empty($meta['chatham']),'duration_seconds'=>$durationSeconds]);
        if (!empty($meta['chatham'])) { $result=EntityRedactor::tree($result,$identities); }
        $result=Grounding::multimedia($result,$transcript);
        $summary=self::naturalizeVideoLanguage(Access::text($result['summary']??'',15000));
        $note=self::naturalizeVideoLanguage(Access::text($result['technical_note']??'',30000));
        Access::require($summary!==''&&$note!=='','La IA no devolvió resumen y nota técnica válidos.',502);
        if (!empty($meta['chatham'])) { $summary=Anonymizer::redact($summary,$identities); $note=Anonymizer::redact($note,$identities); }

        $structured=[];
        foreach (['frameworks','norms','conclusions','concepts','tags'] as $field) {
            $structured[$field]=self::cleanStructuredList((array)($result[$field]??[]));
        }
        $info=is_array($result['infographic']??null)?$result['infographic']:[];
        foreach (['sections','key_points'] as $field) {
            if (isset($info[$field])) { $info[$field]=self::cleanStructuredList((array)$info[$field]); }
        }

        // Keep only timed excerpts grounded in timestamps actually present in the source and inside the detected duration.
        $grounded=$durationSeconds>0
            ? \ASCLA\Core\Domain\Transcript::moments($transcript,array_merge((array)($result['moments']??[]),(array)($result['excerpts']??[])),$durationSeconds)
            : [];
        $grounded=array_map(static function($clip) use($id,$meta) {
            $clip['duration']=$clip['end']-$clip['start'];$clip['source_id']=$id;
            $clip['description']=$clip['title'];$clip['reason']=$clip['selection'];
            return $clip;
        },$grounded);

        // Classify only against the six official ASCLA knowledge topics.
        // Existing manual topics are preserved; only topics previously added by AI are replaced on reprocessing.
        $autoTopics=self::inferVideoTopics($result,$transcript,$summary,$note);
        $topicIds=self::topicIds($autoTopics);

        // Enrich the original publication. Do not create a second technical-note resource, Hub draft or capsule posts.
        $generatedFields=[
            'ai_enriched'=>true,
            'generated_sections'=>true,
            'ai_reviewed'=>true,
            'summary'=>$summary,
            'technical_note'=>$note,
            'ai_mode'=>$ai->mode(),
            'video_mode'=>$videoMode,
            'infographic'=>$info,
            'moments'=>$grounded,
            'excerpts'=>$grounded,
            'frameworks'=>$structured['frameworks'],
            'norms'=>$structured['norms'],
            'conclusions'=>$structured['conclusions'],
            'concepts'=>$structured['concepts'],
            'tags'=>$structured['tags'],
            'ai_topics'=>$autoTopics,
            'ai_topic_ids'=>$topicIds,
            'grounding'=>$result['grounding'],
            'redaction'=>['policy'=>'pre-and-post-entities-v1','review_required'=>false],
            'duration_seconds'=>$durationSeconds,
            'capsule_policy'=>['duration_seconds'=>$durationSeconds,'max_capsules'=>\ASCLA\Core\Domain\Transcript::capsuleLimit($durationSeconds)],
            'generated_at'=>current_datetime()->format(DATE_ATOM),
        ];
        // Sanitize only generated material. Original source URLs, video IDs and editorial metadata must remain untouched.
        $generatedFields=self::cleanGenerated($generatedFields,!empty($meta['chatham']),$identities);
        foreach(['moments','excerpts'] as $field){$generatedFields[$field]=\ASCLA\Core\Domain\Transcript::videoLinks($generatedFields[$field],(string)($meta['video_id']??''));}
        $updated=array_merge($meta,$generatedFields);
        $updated['copyright']='© ASCLA – Asociación de Secretarios Corporativos de América Latina';
        $updated['ai_mode']=$ai->mode();
        $updated['resource_type']=$meta['resource_type']??'Video';
        $updated['video_id']=$meta['video_id']??'';
        $updated['youtube_url']=$meta['youtube_url']??'';
        $updated['thumbnail_url']=YouTubeVideoProvider::thumbnail($updated['video_id']);
        if (!empty($meta['chatham'])) {
            $summary=EntityRedactor::redact($summary,$identities);$note=EntityRedactor::redact($note,$identities);
            Access::require(EntityRedactor::validateRedaction($summary.' '.$note.' '.wp_json_encode($result,JSON_UNESCAPED_UNICODE),$identities)['valid'],'El borrador requiere revisión adicional de identidades.',502);
        }
        update_post_meta($id,'_ascla',$updated);
        if ($structured['tags']) { Content::tags($id,$structured['tags']); }
        self::applyVideoTopics($id,$topicIds,(array)($meta['ai_topic_ids']??[]));
        Audit::record('ai_enriched',$id,$ai->mode());
        return ['resource_id'=>$id,'capsule_ids'=>[],'hub_id'=>0,'mode'=>$ai->mode(),'video_mode'=>$videoMode,'duration_seconds'=>$durationSeconds,'topics'=>$autoTopics,'message'=>'Resumen y nota técnica integrados en la publicación original.'.($autoTopics?' Temas activados: '.implode(', ',$autoTopics).'.':'')];
    }
    private static function naturalizeVideoLanguage(string $text): string
    {
        if ($text==='') { return ''; }
        $text=preg_replace('/(^|[.!?]\s+)La transcripci[oó]n\b/u','$1El video',$text)??$text;
        $text=preg_replace('/\b(?:la|esta) transcripci[oó]n\b/iu','el video',$text)??$text;
        $text=preg_replace('/\btranscripci[oó]n\b/iu','contenido del video',$text)??$text;
        return trim($text);
    }
    private static function cleanStructuredList(array $values): array
    {
        $clean=[];
        foreach (array_slice($values,0,40) as $value) {
            if (!is_string($value)) { continue; }
            $value=trim(preg_replace('/\s+/u',' ',sanitize_text_field($value)));
            if ($value==='') { continue; }
            $plain=mb_strtolower(remove_accents(trim($value," \t\n\r\0\x0B.,;:–—-")));
            if (in_array($plain,['participante','participantes','una persona','persona','dato reservado','informacion reservada','identidad reservada','organizacion reservada'],true)) { continue; }
            $clean[]=$value;
        }
        return array_values(array_unique($clean));
    }
    private static function editorialContext(int $id,array $meta): array
    {
        $transcript=trim((string)($meta['transcript']??'')); $videoMode='Transcripción manual autorizada';
        if ($transcript==='') {
            if (empty($meta['video_id'])) {
                self::rememberTranscriptFailure($id,$meta,'El recurso no tiene video de YouTube ni transcripción autorizada.');
                Access::require(false,'No hay una transcripción disponible para analizar este recurso. Añade una transcripción autorizada antes de generar el resumen.',400);
            }
            if ((Settings::get()['youtube_mode']??'mock')!=='real') {
                self::rememberTranscriptFailure($id,$meta,'La obtención real de subtítulos de YouTube no está habilitada.');
                Access::require(false,'No se pudo obtener una transcripción verificable. Conecta YouTube OAuth o pega una transcripción autorizada. ASCLA no generará información sin una transcripción válida.',409);
            }
            try {
                $video=(new YouTubeVideoProvider())->transcript((string)$meta['video_id']);
                $transcript=trim((string)($video['text']??'')); $videoMode=(string)($video['mode']??'API REAL YouTube');
            } catch (\Throwable $e) {
                self::rememberTranscriptFailure($id,$meta,$e->getMessage());
                throw new \RuntimeException('No se pudo obtener una transcripción autorizada desde YouTube. Puede que el video no tenga subtítulos accesibles para esta cuenta. Pega una transcripción manual antes de generar el resumen; ASCLA no inventará contenido.');
            }
        }
        if (mb_strlen($transcript)<30) {
            self::rememberTranscriptFailure($id,$meta,'La transcripción disponible es demasiado corta para verificar el contenido.');
            Access::require(false,'La transcripción disponible es insuficiente para generar contenido confiable. ASCLA no generará información sin evidencia suficiente.',400);
        }
        $meta['transcript_status']=($meta['transcript']??'')!==''?'manual':'youtube';
        $meta['transcript_mode']=$videoMode;
        $meta['transcript_checked_at']=current_datetime()->format(DATE_ATOM);
        unset($meta['transcript_error']);
        update_post_meta($id,'_ascla',$meta);
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
    private static function rememberTranscriptFailure(int $id,array $meta,string $reason): void
    {
        $meta['transcript_status']='unavailable';
        $meta['transcript_checked_at']=current_datetime()->format(DATE_ATOM);
        $meta['transcript_error']=mb_substr(sanitize_text_field($reason),0,500);
        update_post_meta($id,'_ascla',$meta);
        Audit::record('video_transcript_unavailable',$id,$meta['transcript_error']);
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
    private static function inferVideoTopics(array $result,string $transcript,string $summary,string $note): array
    {
        $topics=[];
        $approved=array_keys(self::VIDEO_TOPICS);
        foreach ((array)($result['topics']??[]) as $candidate) {
            if (!is_string($candidate)) { continue; }
            foreach ($approved as $name) {
                if (strcasecmp(remove_accents(trim($candidate)),remove_accents($name))===0) { $topics[]=$name; break; }
            }
        }
        $haystack=$transcript."\n".$summary."\n".$note."\n".implode("\n",array_merge((array)($result['tags']??[]),(array)($result['concepts']??[]),(array)($result['frameworks']??[]),(array)($result['conclusions']??[])));
        foreach (self::VIDEO_TOPICS as $name=>$patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern,$haystack)) { $topics[]=$name; break; }
            }
        }
        return array_values(array_unique($topics));
    }
    private static function topicIds(array $topics): array
    {
        $ids=[];
        foreach ($topics as $name) {
            $term=term_exists($name,'ascla_interest');
            if (is_array($term)) { $term=$term['term_id']??0; }
            if (is_int($term) || ctype_digit((string)$term)) { $ids[]=(int)$term; }
        }
        return array_values(array_unique(array_filter($ids)));
    }
    private static function applyVideoTopics(int $id,array $newIds,array $previousAiIds): void
    {
        $current=wp_get_object_terms($id,'ascla_interest',['fields'=>'ids']);
        if (is_wp_error($current)) { $current=[]; }
        $manual=array_values(array_diff(array_map('intval',$current),array_map('intval',$previousAiIds)));
        $final=array_values(array_unique(array_merge($manual,array_map('intval',$newIds))));
        wp_set_object_terms($id,$final,'ascla_interest');
    }

    private static function capsules(array $grounded,int $id,array $meta,array $derived,string $summary,array|\WP_Error $topics,AIProviderInterface $ai): array
    {
        $capsules=[];
        foreach (array_slice($grounded,0,3) as $index=>$clip) {
            $clipBody=Access::text((string)($clip['description']??$clip['title']??$summary),10000);
            $capsule=wp_insert_post(wp_slash(['post_type'=>'ascla_resource','post_title'=>'Cápsula sugerida '.($index+1).' · Sesión ASCLA','post_content'=>$clipBody,'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
            if (!is_wp_error($capsule)) {
                update_post_meta($capsule,'_ascla',['resource_type'=>'Video','generated'=>true,'reviewed'=>false,'chatham'=>$derived['chatham'],'source_id'=>$id,'video_id'=>$meta['video_id']??'','clip'=>$clip,'demo_source_note'=>$meta['demo_source_note']??'','duration_seconds'=>$clip['end']-$clip['start'],'thumbnail_url'=>YouTubeVideoProvider::thumbnail($meta['video_id']??''),'copyright'=>Settings::get()['copyright'],'ai_mode'=>$ai->mode()]);
                if (!is_wp_error($topics)) { wp_set_object_terms($capsule,array_map('intval',$topics),'ascla_interest'); }
                $capsules[]=$capsule;
            }
        }
        return $capsules;
    }

}
