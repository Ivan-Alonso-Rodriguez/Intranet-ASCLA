<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\MatchScore;
use ASCLA\Core\Repositories\Store;

final class Matching
{
    private const BATCH_META='_ascla_recommendation_batch';
    private const DISMISS_KIND='recommendation_ignore';
    private const BATCH_SECONDS=7*DAY_IN_SECONDS;

    public static function between(int $a,int $b,bool $explain=true,bool $includeParticipation=true): array
    {
        $left=Profiles::raw($a);$right=Profiles::raw($b);
        Access::require($a!==$b && !empty($left['networking']) && !empty($right['networking']) && !empty($right['directory']) && !Messaging::blocked($a,$b),'Active networking; ambos perfiles deben aceptar participar.',400);
        foreach ((array)$left['hidden'] as $field) { unset($left[$field]); }
        foreach ((array)$right['hidden'] as $field) { unset($right[$field]); }
        $leftParticipation=$includeParticipation?MatchingParticipation::topics($a):[];
        $rightParticipation=$includeParticipation?MatchingParticipation::topics($b):[];
        $left['participation']=$leftParticipation;$right['participation']=$rightParticipation;
        $weights=Settings::get()['matching_weights'];
        $key='ascla_match_'.hash('sha256',wp_json_encode([$a,$b,$left['revision']??0,$right['revision']??0,$weights,$leftParticipation,$rightParticipation,$includeParticipation]));
        $result=get_transient($key);
        if (!$result) { $result=MatchScore::calculate($left,$right,$weights);set_transient($key,$result,HOUR_IN_SECONDS); }
        $names=MatchingExplanation::sharedNames($result);
        $signals=MatchingExplanation::signals($result);
        $affinity=['score'=>$result['score'],'shared'=>$names,'signals'=>$signals,'explanation'=>MatchingExplanation::explanation($names,$signals)];
        if ($explain) { $affinity=MatchingExplanation::withAiExplanation($affinity,$a,$b); }
        return $affinity;
    }

    public static function recommendations(): array
    {
        $me=get_current_user_id();
        if (!self::eligibility($me,true)['eligible']) { return []; }
        $batch=self::batch($me);$items=[];
        foreach ((array)($batch['ids']??[]) as $id) {
            $id=(int)$id;
            if (!self::eligibility($id,false)['eligible'] || self::isDismissed($me,$id)) { continue; }
            try { $items[]=array_merge(Profiles::visible($id),['affinity'=>self::between($me,$id,true,true)]); }
            catch (\ASCLA\Core\Rest\ApiException) { continue; }
        }
        usort($items,static fn($a,$b)=>($b['affinity']['score']<=>$a['affinity']['score'])?:($a['id']<=>$b['id']));
        return Connections::attach(array_slice($items,0,self::maximum()));
    }

    public static function overview(): array
    {
        $me=get_current_user_id();$eligibility=self::eligibility($me,true);
        if (!$eligibility['eligible']) {
            return ['eligible'=>false,'reason'=>$eligibility['reason'],'missing'=>$eligibility['missing'],'completion'=>$eligibility['completion'],'people'=>[],'limit'=>self::maximum(),'low_match'=>false,'generated_at'=>null,'next_update'=>null];
        }
        $batch=self::batch($me);$people=self::recommendations();$limit=self::maximum();
        return ['eligible'=>true,'reason'=>'','missing'=>[],'completion'=>$eligibility['completion'],'people'=>$people,'limit'=>$limit,'low_match'=>count($people)<$limit,'generated_at'=>$batch['generated_at']??null,'next_update'=>$batch['next_update']??null];
    }

    public static function eligibility(int $id,bool $recipient=false): array
    {
        try { $profile=Profiles::raw($id); }
        catch (\ASCLA\Core\Rest\ApiException) { return ['eligible'=>false,'reason'=>'membership','missing'=>[],'completion'=>0]; }
        $completion=Profiles::completion($id);
        $missing=ProfileRequirements::missing($profile);
        $reason='';
        if (!Access::member($id)) { $reason='membership'; }
        elseif (empty($profile['networking'])) { $reason='disabled'; }
        elseif (!$recipient && empty($profile['directory'])) { $reason='privacy'; }
        elseif ($missing || (int)$completion['percent']<70) { $reason='profile'; }
        return ['eligible'=>$reason==='','reason'=>$reason,'missing'=>$missing,'completion'=>(int)$completion['percent']];
    }

    private static function maximum(): int
    {
        return max(1,min(5,(int)(Settings::get()['matching_max_suggestions']??5)));
    }

    private static function batchSignature(int $userId): string
    {
        $settings=Settings::get();$profile=Profiles::raw($userId);
        return hash('sha256',wp_json_encode([$profile['revision']??0,(int)get_option('ascla_profile_revision',0),$settings['matching_min_affinity']??30,$settings['matching_max_suggestions']??5,$settings['matching_weights']??[]]));
    }

    private static function batch(int $userId): array
    {
        $batch=get_user_meta($userId,self::BATCH_META,true);
        $valid=is_array($batch)
            && ($batch['signature']??'')===self::batchSignature($userId)
            && (int)($batch['generated_timestamp']??0)>time()-self::BATCH_SECONDS;
        return $valid?$batch:self::refreshBatch($userId);
    }

    public static function refreshBatch(int $userId): array
    {
        return Store::lock('recommendation-batch:'.$userId,static function()use($userId){
            $previous=get_current_user_id();$ids=[];
            try {
                wp_set_current_user($userId);
                if (self::eligibility($userId,true)['eligible']) { $ids=self::rankedCandidateIds($userId); }
                $generated=time();$batch=['ids'=>$ids,'generated_at'=>gmdate('c',$generated),'generated_timestamp'=>$generated,'next_update'=>gmdate('c',$generated+self::BATCH_SECONDS),'signature'=>self::batchSignature($userId)];
                update_user_meta($userId,self::BATCH_META,$batch);
                return $batch;
            } finally { wp_set_current_user($previous); }
        });
    }

    public static function refreshAll(): array
    {
        $refreshed=0;$previous=get_current_user_id();
        try {
            foreach (get_users(['capability'=>'ascla_access','fields'=>'ID']) as $id) {
                if (!Access::member((int)$id)) { continue; }
                wp_set_current_user((int)$id);
                try {
                    self::refreshBatch((int)$id);
                    // Precompute and cache the privacy-safe explanation with the configured
                    // AI provider; deterministic scoring remains available as its fallback.
                    self::recommendations();
                    $refreshed++;
                }
                catch (\Throwable $error) { Audit::record('recommendation_refresh_failed',(int)$id,get_class($error)); }
            }
        } finally { wp_set_current_user($previous); }
        return ['refreshed'=>$refreshed];
    }

    private static function rankedCandidateIds(int $me): array
    {
        $minimum=max(0,min(100,(int)(Settings::get()['matching_min_affinity']??30)));
        $shortlist=self::recommendationShortlist($me,max(0,$minimum-5));$ranked=[];
        foreach (array_slice($shortlist,0,40) as $candidate) {
            $id=(int)$candidate['id'];
            if (self::isDismissed($me,$id) || !self::eligibility($id,false)['eligible']) { continue; }
            try {
                $affinity=self::between($me,$id,false,true);
                if ((int)$affinity['score']>=$minimum) { $ranked[]=['id'=>$id,'score'=>(int)$affinity['score']]; }
            } catch (\ASCLA\Core\Rest\ApiException) { continue; }
        }
        usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));
        return array_column(array_slice($ranked,0,self::maximum()),'id');
    }

    private static function recommendationShortlist(int $me,int $preMinimum): array
    {
        $shortlist=[];
        foreach (get_users(['capability'=>'ascla_access']) as $candidate) {
            $id=(int)$candidate->ID;
            if ($id===$me || self::isDismissed($me,$id) || !self::eligibility($id,false)['eligible'] || !self::hasRequiredCommonFactor($me,$id)) { continue; }
            try {
                $affinity=self::between($me,$id,false,false);
                if ((int)$affinity['score']>=$preMinimum) { $shortlist[]=['id'=>$id,'score'=>(int)$affinity['score']]; }
            } catch (\ASCLA\Core\Rest\ApiException) { continue; }
        }
        usort($shortlist,static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));
        return $shortlist;
    }

    private static function hasRequiredCommonFactor(int $leftId,int $rightId): bool
    {
        $left=Profiles::raw($leftId);$right=Profiles::raw($rightId);
        foreach (['interests','industries','goals'] as $field) {
            if (in_array($field,(array)($left['hidden']??[]),true) || in_array($field,(array)($right['hidden']??[]),true)) { continue; }
            if (array_intersect(array_map('intval',(array)($left[$field]??[])),array_map('intval',(array)($right[$field]??[])))) { return true; }
        }
        return false;
    }

    private static function isDismissed(int $viewer,int $target): bool
    {
        return Store::count('relations','user_id=%d AND target_id=%d AND kind=%s',[$viewer,$target,self::DISMISS_KIND])>0;
    }

    public static function dismiss(int $target): array
    {
        $me=get_current_user_id();Access::require($target>0 && $target!==$me,'Perfil recomendado no válido.',400);
        $batch=self::batch($me);Access::require(in_array($target,array_map('intval',(array)($batch['ids']??[])),true),'La recomendación ya no está vigente.',409);
        Store::lock('recommendation-dismiss:'.$me,static function()use($me,$target,$batch){
            if (!self::isDismissed($me,$target)) { Store::insert('relations',['user_id'=>$me,'target_id'=>$target,'kind'=>self::DISMISS_KIND,'created_at'=>current_time('mysql',true)]); }
            $batch['ids']=array_values(array_filter(array_map('intval',(array)$batch['ids']),static fn($id)=>$id!==$target));
            update_user_meta($me,self::BATCH_META,$batch);Audit::record('recommendation_dismissed',$target);
        });
        return ['dismissed'=>true,'target'=>$target];
    }

    public static function restore(int $target): array
    {
        $me=get_current_user_id();Access::require($target>0 && $target!==$me,'Perfil descartado no válido.',400);
        Store::delete('relations',['user_id'=>$me,'target_id'=>$target,'kind'=>self::DISMISS_KIND]);Audit::record('recommendation_restored',$target);
        return ['restored'=>true,'target'=>$target];
    }

    public static function select(int $target): array
    {
        $me=get_current_user_id();$batch=self::batch($me);
        Access::require(in_array($target,array_map('intval',(array)($batch['ids']??[])),true),'La recomendación ya no está vigente.',409);
        Audit::record('recommendation_viewed',$target);
        return ['recorded'=>true,'target'=>$target];
    }

    public static function dismissed(): array
    {
        $items=[];
        foreach (Store::rows('relations','user_id=%d AND kind=%s',[get_current_user_id(),self::DISMISS_KIND],'ORDER BY id DESC LIMIT 100') as $row) {
            $card=Profiles::card((int)$row['target_id']);$card['dismissed_at']=$row['created_at'];$items[]=$card;
        }
        return ['items'=>$items,'total'=>count($items)];
    }

    public static function intro(int $id): array
    {
        $me=get_current_user_id();$affinity=self::between($me,$id,false);
        $context=NetworkingAI::context($me,$id,$affinity);
        $result=NetworkingAI::generate('intro',$context,[$me,$id]);
        $text=trim(Access::excerpt($result['text']??'',5000));
        // A suggested private message must sound like the member, never like the AI service itself.
        $claimsIdentity=(bool)preg_match('/\b(?:soy|somos)\s+(?:(?:el|la|un|una)\s+)?(?:asistente|chatbot|ASCLA)\b/iu',$text);
        $claimsAssistantRole=(bool)preg_match('/\b(?:como|en calidad de)\s+(?:asistente|chatbot)\b/iu',$text);
        if($text==='' || $claimsIdentity || $claimsAssistantRole){
            $safe=(new \ASCLA\Core\Integrations\MockAIProvider())->generate('intro',$context);
            $text=trim(Access::excerpt($safe['text']??'',5000));
            $result['conversation_proposal']=$safe['conversation_proposal']??'';
            $result['fallback']=true;
        }
        return ['text'=>$text,'conversation_proposal'=>Access::excerpt($result['conversation_proposal']??'',2000),'mode'=>$result['mode']??'IA','fallback'=>!empty($result['fallback']),'sent'=>false];
    }


}
