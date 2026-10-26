<?php
namespace ASCLA\Core\Services;

/** Presentation-safe explanations for deterministic matching results. */
final class MatchingExplanation
{
    public static function sharedNames(array $result): array
    {
        $names=[];
        foreach (['interests'=>'interest','areas'=>'area','industries'=>'industry','goals'=>'goal','languages'=>'language'] as $field=>$tax) {
            foreach ((array)($result['factors'][$field]['common']??[]) as $tid) {
                $term=get_term((int)$tid,'ascla_'.$tax);
                if ($term && !is_wp_error($term) && !in_array($term->name,$names,true)) { $names[]=$term->name; }
            }
        }
        return $names;
    }

    public static function signals(array $result): array
    {
        $signals=[];
        if ((float)($result['factors']['experience']['ratio']??0)>=0.12) { $signals[]='experience'; }
        if ((float)($result['factors']['participation']['ratio']??0)>0) { $signals[]='participation'; }
        return $signals;
    }

    public static function explanation(array $names,array $signals): string
    {
        $parts=[];
        if ($names) { $parts[]='Comparten '.implode(', ',array_slice($names,0,4)).'.'; }
        if (in_array('experience',$signals,true)) { $parts[]='Tienen experiencia profesional relacionada.'; }
        if (in_array('participation',$signals,true)) { $parts[]='Participan en temas similares dentro de la comunidad.'; }
        if (!$parts) { $parts[]='Completen sus intereses y experiencia para descubrir más afinidades.'; }
        return implode(' ',$parts);
    }

    public static function withAiExplanation(array $affinity,int $a,int $b): array
    {
        $prose=NetworkingAI::generate('matching',NetworkingAI::context($a,$b,$affinity),[$a,$b]);
        $affinity['explanation']=Access::excerpt($prose['explanation']??$affinity['explanation'],2000);
        $affinity['conversation_proposal']=Access::excerpt($prose['conversation_proposal']??'',2000);
        $affinity['mode']=$prose['mode'];$affinity['fallback']=$prose['fallback'];
        return $affinity;
    }
}
