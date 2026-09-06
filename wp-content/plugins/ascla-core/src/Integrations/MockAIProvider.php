<?php
namespace ASCLA\Core\Integrations;
final class MockAIProvider implements AIProviderInterface
{
    public function mode(): string { return 'DEMO MODE'; }
    public function generate(string $task,array $context): array
    {
        if ($task==='answer') {
            $sources=$context['sources']??[];
            return ['answer'=>$sources?implode("\n\n",array_map(static fn($s)=>$s['title'].': '.mb_substr($s['body'],0,550).' [ASCLA #'.$s['id'].']',$sources)):'No existe suficiente información en el Centro de Conocimiento.','source_ids'=>array_column($sources,'id'),'mode'=>$this->mode()];
        }
        if ($task==='intro') { return ['text'=>$context['template']??'Hola, me gustaría conectar e intercambiar experiencias en ASCLA.','mode'=>$this->mode()]; }
        if ($task==='social') {
            $body=$context['text']??''; $relevant=(bool)preg_match('/gobierno|gobernanza|directori|junta|cumplimiento|riesgo/iu',$body); $commercial=(bool)preg_match('/compra|descuento|oferta|promoci[oó]n|contr[aá]ta/iu',$body);
            return ['relevant'=>$relevant&&!$commercial,'commercial'=>$commercial,'suggested_reply'=>'Gracias por compartir esta reflexión sobre gobernanza corporativa.','draft'=>$body,'mode'=>$this->mode()];
        }
        $text=trim($context['transcript']??'');
        $sentences=preg_split('/(?<=[.!?])\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $points=array_slice(array_map(static fn($s)=>mb_substr($s,0,400),$sentences),0,5);
        $summary=implode(' ',array_slice($points,0,3));
        preg_match_all('/gobernanza|inteligencia artificial|supervisión|directorio|riesgos|responsabilidades|secretaría corporativa|cumplimiento|sostenibilidad|transformación digital|acuerdos/iu',$text,$keywords);
        $tags=array_slice(array_values(array_unique(array_map('mb_strtolower',$keywords[0]))),0,12);
        // Extract only source material. Empty statistics/norms are intentional.
        return ['summary'=>$summary,'technical_note'=>"Ideas recogidas en la transcripción:\n\n".implode("\n\n",$points),'frameworks'=>[],'conclusions'=>$points,'norms'=>[],'concepts'=>$points,'tags'=>$tags,'suggested_hub'=>$summary,'infographic'=>['title'=>'Claves de la sesión','sections'=>$points,'statistics'=>[],'timeline'=>[],'key_points'=>$points,'source'=>$context['source_id']??0],'moments'=>\ASCLA\Core\Domain\Transcript::moments($text),'excerpts'=>\ASCLA\Core\Domain\Transcript::moments($text),'mode'=>$this->mode()];
    }
}
