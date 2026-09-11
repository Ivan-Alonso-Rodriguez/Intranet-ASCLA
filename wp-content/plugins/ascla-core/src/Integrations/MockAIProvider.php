<?php
namespace ASCLA\Core\Integrations;
final class MockAIProvider implements AIProviderInterface
{
    public function mode(): string { return 'DEMO MODE'; }
    public function generate(string $task,array $context): array
    {
        if ($task==='answer') {
            $sources=$context['sources']??[];
            $quotes=[];foreach($sources as $source){ $quotes=array_merge($quotes,array_slice(\ASCLA\Core\Domain\Grounding::sentences($source['body']),0,3)); }
            return ['answer'=>implode("\n\n",$quotes),'source_ids'=>array_column($sources,'id'),'mode'=>$this->mode()];
        }
        if(in_array($task,['matching','intro'],true)){
            $shared=implode(' y ',array_slice($context['shared']??[],0,3));$topic=$shared?:'sus objetivos de networking';
            $proposal='¿Qué experiencia les gustaría compartir sobre '.$topic.'?';
            return ['explanation'=>$shared?'Comparten interés en '.$shared.'. Estos factores provienen de los campos públicos de sus perfiles.':'Completen sus intereses para descubrir afinidades.','text'=>'Hola '.($context['right']['name']??'asociado').', vi que podemos conversar sobre '.$topic.'. '.$proposal,'conversation_proposal'=>$proposal,'mode'=>$this->mode()];
        }
        if($task==='microagenda'){
            $theme=implode(' y ',array_slice($context['interests']??[],0,2))?:'gobierno corporativo';
            return ['title'=>'Círculo ASCLA · '.$theme,'theme'=>$theme,'objective'=>'Intercambiar experiencias y preguntas sobre '.$theme.'.','duration_minutes'=>30,'agenda'=>[['minutes'=>5,'topic'=>'Presentaciones y objetivos'],['minutes'=>10,'topic'=>'Experiencias sobre '.$theme],['minutes'=>10,'topic'=>'Preguntas y aprendizajes compartidos'],['minutes'=>5,'topic'=>'Próximos pasos']],'icebreaker'=>'¿Qué pregunta sobre '.$theme.' te gustaría explorar?','closing_question'=>'¿Qué aprendizaje te gustaría llevar a tu próxima reunión?','mode'=>$this->mode()];
        }
        if ($task==='social') {
            $body=$context['text']??''; $relevant=(bool)preg_match('/gobierno|gobernanza|directori|junta|cumplimiento|riesgo/iu',$body); $commercial=(bool)preg_match('/compra|descuento|oferta|promoci[oó]n|contr[aá]ta/iu',$body);
            return ['relevant'=>$relevant&&!$commercial,'commercial'=>$commercial,'suggested_reply'=>'Gracias por compartir esta reflexión sobre gobernanza corporativa.','draft'=>$body,'mode'=>$this->mode()];
        }
        return $this->multimedia($context);
    }
    private function multimedia(array $context): array
    {
        $text=trim($context['transcript']??'');
        $sentences=\ASCLA\Core\Domain\Grounding::sentences($text);
        $points=array_slice(array_map(static fn($s)=>mb_substr($s,0,400),$sentences),0,5);
        $summary=implode(' ',array_slice($points,0,3));
        preg_match_all('/gobernanza|inteligencia artificial|supervisión|directorio|riesgos|responsabilidades|secretaría corporativa|cumplimiento|sostenibilidad|transformación digital|acuerdos/iu',$text,$keywords);
        $tags=array_slice(array_values(array_unique(array_map('mb_strtolower',$keywords[0]))),0,12);
        preg_match_all('/\b(?:ISO\s+\d+(?::\d+)?|COSO(?:\s+ERM)?|OCDE)\b/u',$text,$standards);
        preg_match_all('/\b(?:Ley|Decreto)\s+\d+\b/u',$text,$laws);
        $norms=[array_merge($standards[0],$laws[0])];
        $frameworks=array_values(array_filter($norms[0],static fn($term)=>str_contains($term,'COSO')));
        $statistics=array_values(array_filter($sentences,static fn($sentence)=>preg_match('/\d+(?:[.,]\d+)?\s*(?:%|por ciento)/u',$sentence)));
        $timeline=[];foreach($sentences as $sentence){if(preg_match('/\b(?:19|20)\d{2}\b/u',$sentence,$year)){ $timeline[]=['date'=>$year[0],'text'=>$sentence]; }}
        // Extract named frameworks/norms and numerical claims only when mentioned.
        return ['summary'=>$summary,'technical_note'=>"Ideas recogidas en la transcripción:\n\n".implode("\n\n",$points),'frameworks'=>$frameworks,'conclusions'=>$points,'norms'=>array_values(array_unique($norms[0])),'concepts'=>$points,'tags'=>$tags,'suggested_hub'=>$summary,'infographic'=>['title'=>'Claves de la sesión','sections'=>$points,'statistics'=>$statistics,'timeline'=>$timeline,'key_points'=>$points,'source'=>$context['source_id']??0],'moments'=>\ASCLA\Core\Domain\Transcript::moments($text),'excerpts'=>\ASCLA\Core\Domain\Transcript::moments($text),'mode'=>$this->mode()];
    }
}
