<?php
namespace ASCLA\Core\Integrations;
final class MockAIProvider implements AIProviderInterface
{
    public function mode(): string { return 'DEMO MODE'; }
    public function generate(string $task,array $context): array
    {
        return match($task) {
            'form_interests'=>$this->formInterests($context),
            'interest_classification'=>$this->interestClassification($context),
            'answer'=>$this->answer($context),
            'matching','intro'=>$this->matching($context),
            'microagenda'=>$this->microagenda($context),
            'microevent_copy'=>[
                'objective'=>(string)($context['event']['description']??$context['event']['title']??'Conversar sobre intereses compartidos.'),
                'icebreaker'=>'¿Qué experiencia te gustaría aportar a “'.($context['event']['title']??'este encuentro').'”?',
                'closing_question'=>'¿Qué acción aplicarías después de “'.($context['event']['title']??'este encuentro').'”?',
                'mode'=>$this->mode(),
            ],
            'social'=>$this->social($context),
            default=>$this->multimedia($context),
        };
    }

    private function formInterests(array $context): array
    {
        $terms=$context['catalog']??[];
        $text=mb_strtolower(remove_accents($context['text']??''));
        $ids=[];
        foreach ($terms as $term) {
            $name=mb_strtolower(remove_accents($term['name']));
            $direct=str_contains($text,$name);
            $aiTopic=(str_contains($name,'gobernanza de la ia')||str_contains($name,'inteligencia artificial'))
                && preg_match('/\b(chatgpt|gemini|llm|ia generativa)\b/',$text);
            $cyber=str_contains($name,'ciberseguridad') && preg_match('/hackeo|cyber|seguridad informatica/',$text);
            if ($direct || $aiTopic || $cyber) { $ids[]=(int)$term['id']; }
        }
        return ['intereses'=>array_slice(array_values(array_unique($ids)),0,3),'confianza'=>$ids?'media':'baja'];
    }

    private function interestClassification(array $context): array
    {
        $aliases=[
            'inteligencia artificial'=>'/\b(ia|ai|llm|chatgpt|gemini|inteligencia artificial|machine learning)\b/u',
            'gestion de riesgos'=>'/\b(riesgo|riesgos|risk|coso)\b/u',
            'gobierno corporativo'=>'/\b(gobierno corporativo|gobernanza|corporate governance)\b/u',
            'juntas directivas'=>'/\b(juntas? directivas?|directorio|directorios|board)\b/u',
            'sostenibilidad'=>'/\b(sostenibilidad|sustentabilidad|esg|asg)\b/u',
            'transformacion digital'=>'/\b(digital|digitalizacion|transformacion digital)\b/u',
        ];
        $assignments=[];
        foreach ($context['items']??[] as $item) {
            $text=mb_strtolower(remove_accents($item['title']));
            $topic=$this->classifiedTopic($text,$context['topics']??[],$aliases);
            $assignments[]=['id'=>(int)$item['id'],'topic_id'=>$topic];
        }
        return ['assignments'=>$assignments,'mode'=>$this->mode()];
    }

    private function classifiedTopic(string $text,array $topics,array $aliases): int
    {
        $topic=0;
        foreach ($topics as $term) {
            $label=mb_strtolower(remove_accents($term['name']));
            if (preg_match($aliases[$label]??('/'.preg_quote($label,'/').'/u'),$text)) {
                $topic=(int)$term['id'];
                break;
            }
        }
        return $topic;
    }

    private function answer(array $context): array
    {
        $sources=$context['sources']??[];
        $live=(array)($context['live_context']??[]);
        $question=mb_strtolower(remove_accents((string)($context['question']??'')));
        $english=(($live['language']??'')==='English');
        if (!empty($live['intents']['gallery'])) {
            if (!$sources) { return ['answer'=>$english?'I could not find a published gallery you can access. Try its title.':'No encontré una galería publicada a la que tengas acceso. Prueba con su título.','source_ids'=>[],'mode'=>$this->mode()]; }
            if (($live['gallery_request']??'')==='choose') {
                return ['answer'=>($english?'Which gallery would you like to explore?':'¿Qué galería quieres consultar?')."\n".implode("\n",array_map(static fn($s)=>'• '.$s['title'],$sources)),'source_ids'=>array_column($sources,'id'),'mode'=>$this->mode()];
            }
            $source=$sources[0];
            return ['answer'=>$source['title']."\n\n".$source['body']."\n\n".($english?'This gallery contains '.count($source['files']??[]).' files. Demo mode can read the description, but cannot visually analyze photos. Enable an image-capable AI provider to analyze them.':'Esta galería contiene '.count($source['files']??[]).' archivos. En modo demo puedo consultar su descripción, pero no analizar visualmente las fotos. Activa un proveedor de IA con visión para analizarlas.'),'source_ids'=>[$source['id']],'mode'=>$this->mode()];
        }
        if (!empty($live['intents']['organization'])) {
            return ['answer'=>\ASCLA\Core\Services\AssistantContext::describe($live),'source_ids'=>[],'mode'=>$this->mode()];
        }
        if (($live['video_request']??'')==='choose') {
            $options=array_slice($sources,0,3);
            return ['answer'=>($english?'Which video do you mean?':'¿Sobre cuál de estos videos quieres preguntar?')."\n".implode("\n",array_map(static fn($s)=>'• '.$s['title'],$options)),'source_ids'=>array_column($options,'id'),'mode'=>$this->mode()];
        }
        if (($live['video_request']??'')==='answer' && $sources) {
            return $this->sourceAnswer([$sources[0]],$english);
        }
        $liveAnswer=$this->liveAnswer($live,$english);
        if ($liveAnswer!==null) { return $liveAnswer; }
        $isGreeting=(bool)preg_match('/^(?:hola|hello|hi|buenas)/u',$question);
        $asksCapabilities=(bool)preg_match('/\b(?:que puedes hacer|como me ayudas|help|what can you do)\b/u',$question);
        if ($isGreeting || $asksCapabilities) {
            return ['answer'=>$english?'Hello! I can help you check upcoming ASCLA events, recent posts, community resources, galleries, notifications, and member recommendations. Ask me naturally.':'¡Hola! Puedo ayudarte a consultar eventos próximos, publicaciones recientes, recursos de la comunidad, galerías, notificaciones y recomendaciones de asociados. Pregúntame con naturalidad.','source_ids'=>[],'mode'=>$this->mode()];
        }
        return $this->sourceAnswer($sources,$english);
    }

    private function liveAnswer(array $live,bool $english): ?array
    {
        $intent=match(true) {
            !empty($live['intents']['events'])=>'events',
            !empty($live['intents']['recent'])=>'recent',
            !empty($live['intents']['people'])=>'people',
            !empty($live['intents']['notifications'])=>'notifications',
            default=>'',
        };
        return match($intent) {
            'events'=>$this->eventsAnswer((array)($live['upcoming_events']??[]),$english),
            'recent'=>$this->recentAnswer((array)($live['recent_content']??[]),$english),
            'people'=>$this->peopleAnswer((array)($live['recommended_people']??[]),$english),
            'notifications'=>$this->notificationsAnswer((int)($live['unread_notifications']??0),$english),
            default=>null,
        };
    }

    private function eventsAnswer(array $events,bool $english): array
    {
        if (!$events) {
            return ['answer'=>$english?'I do not see any upcoming ASCLA events available to you right now.':'No veo eventos próximos de ASCLA disponibles para ti en este momento.','source_ids'=>[],'mode'=>$this->mode()];
        }
        $lines=[];
        foreach (array_slice($events,0,3) as $event) {
            $availability=!empty($event['unlimited'])?($english?'Unlimited capacity':'Sin límite de cupos'):($english?'Available seats: ':'Cupos disponibles: ').(int)($event['remaining']??0);
            $status=!empty($event['cancelled'])?($english?'Cancelled':'Cancelado'):(!empty($event['is_past'])?($english?'Finished':'Finalizado'):(!empty($event['registration_closed'])?($english?'Registration closed':'Inscripciones cerradas'):''));
            $lines[]='• '.($event['title']??'Evento')."\n".($event['start']??'').' · '.($event['modality']??'')."\n".$availability.($status!==''?' · '.$status:'')
                ."\n".($event['description']??'').(!empty($event['agenda'])?"\n".$event['agenda']:'');
        }
        return ['answer'=>($english?'These are the ASCLA events I can see:':'Estos son los eventos de ASCLA que puedo consultar:')."\n".implode("\n\n",$lines),'source_ids'=>array_map('intval',array_column(array_slice($events,0,3),'id')),'mode'=>$this->mode()];
    }

    private function recentAnswer(array $recent,bool $english): array
    {
        if (!$recent) {
            return ['answer'=>$english?'I do not see recent published content available to you right now.':'No veo contenido publicado recientemente disponible para ti en este momento.','source_ids'=>[],'mode'=>$this->mode()];
        }
        $lines=[];
        foreach (array_slice($recent,0,4) as $item) { $lines[]='• '.($item['title']??'Contenido'); }
        return ['answer'=>($english?'Here is the most recent content I can see in ASCLA:':'Esto es lo más reciente que veo publicado en ASCLA:')."\n".implode("\n",$lines),'source_ids'=>array_map('intval',array_column(array_slice($recent,0,4),'id')),'mode'=>$this->mode()];
    }

    private function peopleAnswer(array $people,bool $english): array
    {
        if (!$people) {
            return ['answer'=>$english?'I do not have member recommendations above the configured affinity threshold right now.':'Ahora mismo no tengo recomendaciones de asociados que superen el mínimo de afinidad configurado.','source_ids'=>[],'mode'=>$this->mode()];
        }
        $lines=[];
        foreach (array_slice($people,0,4) as $person) { $lines[]='• '.($person['name']??'Asociado').' · '.((int)($person['affinity']??0)).'%'; }
        return ['answer'=>($english?'These are your strongest current ASCLA recommendations:':'Estas son tus recomendaciones actuales con mayor afinidad en ASCLA:')."\n".implode("\n",$lines),'source_ids'=>[],'mode'=>$this->mode()];
    }

    private function notificationsAnswer(int $count,bool $english): array
    {
        if ($english) {
            $suffix=$count===1?'':'s';
            $answer='You have '.$count.' unread ASCLA notification'.$suffix.'.';
        } else {
            $suffix=$count===1?'':'es';
            $answer='Tienes '.$count.' notificación'.$suffix.' sin leer en ASCLA.';
        }
        return ['answer'=>$answer,'source_ids'=>[],'mode'=>$this->mode()];
    }

    private function sourceAnswer(array $sources,bool $english): array
    {
        $quotes=[];$used=[];
        foreach ($sources as $source) {
            $sentences=array_slice(\ASCLA\Core\Domain\Grounding::sentences($source['body']),0,3);
            if ($sentences) { $quotes=array_merge($quotes,$sentences);$used[]=(int)$source['id']; }
        }
        if (!$quotes) {
            return ['answer'=>$english?'I do not have enough information in ASCLA to verify that yet. Try asking about events, posts, or community resources.':'No existe suficiente información en ASCLA para verificar eso todavía. Prueba con eventos, publicaciones o recursos de la comunidad.','source_ids'=>[],'mode'=>$this->mode()];
        }
        return ['answer'=>implode("\n\n",$quotes),'source_ids'=>array_values(array_unique($used)),'mode'=>$this->mode()];
    }

    private function matching(array $context): array
    {
        $shared=implode(' y ',array_slice($context['shared']??[],0,3));
        $signals=(array)($context['signals']??[]);
        $topic='sus objetivos de networking';
        if ($shared!=='') { $topic=$shared; }
        elseif (in_array('experience',$signals,true)) { $topic='experiencia profesional relacionada'; }
        $proposal='¿Qué experiencia les gustaría compartir sobre '.$topic.'?';
        $details=[];
        if ($shared) { $details[]='Comparten interés en '.$shared.'.'; }
        if (in_array('experience',$signals,true)) { $details[]='También tienen experiencia profesional relacionada.'; }
        if (in_array('participation',$signals,true)) { $details[]='Su actividad pública en la comunidad muestra temas en común.'; }
        if (!$details) { $details[]='Completen sus intereses y experiencia para descubrir afinidades.'; }
        return ['explanation'=>implode(' ',$details),'text'=>'Hola '.($context['right']['name']??'asociado').', vi que podemos conversar sobre '.$topic.'. '.$proposal,'conversation_proposal'=>$proposal,'mode'=>$this->mode()];
    }

    private function microagenda(array $context): array
    {
        $theme=$context['selected_topic']??(implode(' y ',array_slice($context['interests']??[],0,2))?:'gobierno corporativo');
        $format=$context['meeting_format']??'';
        $activities=[
            'caso práctico'=>['Contrastar un caso anonimizado de '.$theme.'.','Presentar el caso','Comparar alternativas de actuación','¿Qué dato necesitarías para decidir?'],
            'clínica de preguntas'=>['Resolver preguntas de la práctica profesional sobre '.$theme.'.','Recoger las preguntas del grupo','Trabajar en parejas sobre las preguntas elegidas','¿Qué pregunta te llevas de tu práctica reciente?'],
            'taller de decisiones'=>['Acordar criterios aplicables a una decisión sobre '.$theme.'.','Definir la decisión y sus límites','Contrastar criterios y acordar un plan','¿Qué criterio debería pesar más en esta decisión?'],
        ];
        if (isset($activities[$format])) {
            [$goal,$opening,$discussion,$question]=$activities[$format];
            return ['title'=>ucfirst($format).' · '.$theme,'theme'=>$theme,'objective'=>$goal,'duration_minutes'=>40,
                'agenda'=>[['minutes'=>10,'topic'=>$opening],['minutes'=>20,'topic'=>$discussion],['minutes'=>10,'topic'=>'Acordar un siguiente paso sobre '.$theme]],
                'icebreaker'=>$question,'closing_question'=>'¿Cómo aplicarías lo conversado en tu próxima reunión?','mode'=>$this->mode()];
        }
        return ['title'=>'Círculo ASCLA · '.$theme,'theme'=>$theme,'objective'=>'Intercambiar experiencias y preguntas sobre '.$theme.'.','duration_minutes'=>30,'agenda'=>[['minutes'=>5,'topic'=>'Presentaciones y objetivos'],['minutes'=>10,'topic'=>'Experiencias sobre '.$theme],['minutes'=>10,'topic'=>'Preguntas y aprendizajes compartidos'],['minutes'=>5,'topic'=>'Próximos pasos']],'icebreaker'=>'¿Qué pregunta sobre '.$theme.' te gustaría explorar?','closing_question'=>'¿Qué aprendizaje te gustaría llevar a tu próxima reunión?','mode'=>$this->mode()];
    }

    private function social(array $context): array
    {
        $body=$context['text']??'';
        $relevant=(bool)preg_match('/gobierno|gobernanza|directori|junta|cumplimiento|riesgo/iu',$body);
        $commercial=(bool)preg_match('/compra|descuento|oferta|promoci[oó]n|contr[aá]ta/iu',$body);
        return ['relevant'=>$relevant&&!$commercial,'commercial'=>$commercial,'suggested_reply'=>'Gracias por compartir esta reflexión sobre gobernanza corporativa.','draft'=>$body,'mode'=>$this->mode()];
    }

    private function multimedia(array $context): array
    {
        $text=trim($context['transcript']??'');
        $sentences=\ASCLA\Core\Domain\Grounding::sentences($text);
        $points=array_slice(array_map(static fn($s)=>mb_substr($s,0,400),$sentences),0,5);
        $summary=implode("\n\n",array_map(static fn($part)=>implode(' ',$part),array_chunk(array_slice($sentences,0,8),2)));
        preg_match_all('/gobernanza|inteligencia artificial|supervisión|directorio|riesgos|responsabilidades|secretaría corporativa|cumplimiento|sostenibilidad|transformación digital|acuerdos/iu',$text,$keywords);
        $tags=array_slice(array_values(array_unique(array_map('mb_strtolower',$keywords[0]))),0,12);
        preg_match_all('/\b(?:ISO\s+\d+(?::\d+)?|COSO(?:\s+ERM)?|OCDE)\b/u',$text,$standards);
        preg_match_all('/\b(?:Ley|Decreto)\s+\d+\b/u',$text,$laws);
        $norms=[array_merge($standards[0],$laws[0])];
        $frameworks=array_values(array_filter($norms[0],static fn($term)=>str_contains($term,'COSO')));
        $statistics=array_values(array_filter($sentences,static fn($sentence)=>preg_match('/\d+(?:[.,]\d+)?\s*(?:%|por ciento)/u',$sentence)));
        $timeline=[];foreach($sentences as $sentence){if(preg_match('/\b(?:19|20)\d{2}\b/u',$sentence,$year)){ $timeline[]=['date'=>$year[0],'text'=>$sentence]; }}
        // Extract named frameworks/norms and numerical claims only when mentioned.
        $duration=max(0,(int)($context['duration_seconds']??0));
        $moments=$duration>0?\ASCLA\Core\Domain\Transcript::moments($text,[],$duration):[];
        $topics=[];
        $topicText=mb_strtolower(remove_accents($text));
        if (preg_match('/riesgo/',$topicText)) { $topics[]='Gestión de riesgos'; }
        if (preg_match('/gobierno corporativo|gobernanza corporativa/',$topicText)) { $topics[]='Gobierno corporativo'; }
        if (preg_match('/inteligencia artificial|\bia\b/',$topicText)) { $topics[]='Inteligencia artificial'; }
        if (preg_match('/junta directiva|directorio|consejo de administracion/',$topicText)) { $topics[]='Juntas directivas'; }
        if (preg_match('/sostenibilidad|sustentabilidad|\besg\b|\basg\b/',$topicText)) { $topics[]='Sostenibilidad'; }
        if (preg_match('/transformacion digital|digitalizacion|innovacion digital/',$topicText)) { $topics[]='Transformación digital'; }
        return ['summary'=>$summary,'technical_note'=>"Ideas principales del video:\n\n".implode("\n\n",$points),'frameworks'=>$frameworks,'conclusions'=>$points,'norms'=>array_values(array_unique($norms[0])),'concepts'=>$points,'tags'=>$tags,'topics'=>array_values(array_unique($topics)),'suggested_hub'=>$summary,'infographic'=>['title'=>'Claves de la sesión','sections'=>$points,'statistics'=>$statistics,'timeline'=>$timeline,'key_points'=>$points,'source'=>$context['source_id']??0],'moments'=>$moments,'excerpts'=>$moments,'mode'=>$this->mode()];
    }
}
