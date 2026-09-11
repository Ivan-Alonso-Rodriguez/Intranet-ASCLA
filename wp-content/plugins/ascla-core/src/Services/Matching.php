<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\MatchScore;
final class Matching
{
    public static function between(int $a,int $b,bool $explain=true): array
    {
        $left=Profiles::raw($a); $right=Profiles::raw($b);
        Access::require($a!==$b && !empty($left['networking']) && !empty($right['networking']) && !empty($right['directory']) && !Messaging::blocked($a,$b),'Active networking; ambos perfiles deben aceptar participar.',400);
        $weights=Settings::get()['matching_weights'];
        $key='ascla_match_'.hash('sha256',wp_json_encode([$a,$b,$left['revision'],$right['revision'],$weights]));
        $result=get_transient($key);
        if (!$result) {
            foreach ((array)$left['hidden'] as $field) { unset($left[$field]); }
            foreach ((array)$right['hidden'] as $field) { unset($right[$field]); }
            $result=MatchScore::calculate($left,$right,$weights); set_transient($key,$result,DAY_IN_SECONDS);
        }
        $names=[];
        foreach (['interests'=>'interest','areas'=>'area','industries'=>'industry'] as $field=>$tax) {
            foreach ($result['factors'][$field]['common'] as $tid) { $term=get_term($tid,'ascla_'.$tax); if ($term && !is_wp_error($term)) { $names[]=$term->name; } }
        }
        $affinity=['score'=>$result['score'],'shared'=>$names,'explanation'=>$names?'Comparten '.implode(', ',array_slice($names,0,4)).'.':'Completen sus intereses para descubrir más afinidades.'];
        if($explain){
            $prose=NetworkingAI::generate('matching',NetworkingAI::context($a,$b,$affinity));
            $affinity['explanation']=Access::excerpt($prose['explanation']??$affinity['explanation'],2000);
            $affinity['conversation_proposal']=Access::excerpt($prose['conversation_proposal']??'',2000);
            $affinity['mode']=$prose['mode'];$affinity['fallback']=$prose['fallback'];
        }
        return $affinity;
    }
    public static function recommendations(): array
    {
        $me=get_current_user_id(); $profile=Profiles::raw($me);
        if (empty($profile['networking'])) { return []; }
        $items=[];
        foreach (get_users(['capability'=>'ascla_access']) as $candidate) {
            $id=$candidate->ID;
            if ((int)$id===$me) { continue; }
            try { $affinity=self::between($me,(int)$id,false); $items[]=array_merge(Profiles::visible((int)$id),['affinity'=>$affinity]); } catch (\ASCLA\Core\Rest\ApiException $e) { continue; }
        }
        usort($items,static fn($a,$b)=>($b['affinity']['score']<=>$a['affinity']['score'])?:($a['id']<=>$b['id']));
        return array_slice($items,0,6);
    }
    public static function intro(int $id): array
    {
        $me=get_current_user_id();$affinity=self::between($me,$id,false);
        $result=NetworkingAI::generate('intro',NetworkingAI::context($me,$id,$affinity));
        return ['text'=>Access::excerpt($result['text']??'',5000),'conversation_proposal'=>Access::excerpt($result['conversation_proposal']??'',2000),'mode'=>$result['mode'],'fallback'=>$result['fallback'],'sent'=>false];
    }
}
