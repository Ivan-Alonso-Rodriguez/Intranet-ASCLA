<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\MatchScore;
use ASCLA\Core\Repositories\Store;

final class Matching
{
    /** @var array<string,array<int,string>> */
    private static array $participationCache=[];

    public static function between(int $a,int $b,bool $explain=true,bool $includeParticipation=true): array
    {
        $left=Profiles::raw($a); $right=Profiles::raw($b);
        Access::require($a!==$b && !empty($left['networking']) && !empty($right['networking']) && !empty($right['directory']) && !Messaging::blocked($a,$b),'Active networking; ambos perfiles deben aceptar participar.',400);

        // Respect profile privacy before deriving any matching signal.
        foreach ((array)$left['hidden'] as $field) { unset($left[$field]); }
        foreach ((array)$right['hidden'] as $field) { unset($right[$field]); }

        // RF-040: public community participation is a small secondary signal.
        // It is represented only as topic signatures; no private message,
        // attendance record or hidden profile field is exposed to the other user.
        $leftParticipation=$includeParticipation?self::participationTopics($a):[];
        $rightParticipation=$includeParticipation?self::participationTopics($b):[];
        $left['participation']=$leftParticipation;
        $right['participation']=$rightParticipation;

        $weights=Settings::get()['matching_weights'];
        $key='ascla_match_'.hash('sha256',wp_json_encode([
            $a,$b,$left['revision']??0,$right['revision']??0,$weights,
            $leftParticipation,$rightParticipation,$includeParticipation,
        ]));
        $result=get_transient($key);
        if (!$result) {
            $result=MatchScore::calculate($left,$right,$weights); set_transient($key,$result,HOUR_IN_SECONDS);
        }

        $names=[];
        foreach (['interests'=>'interest','areas'=>'area','industries'=>'industry','goals'=>'goal','languages'=>'language'] as $field=>$tax) {
            foreach ((array)($result['factors'][$field]['common']??[]) as $tid) {
                $term=get_term((int)$tid,'ascla_'.$tax);
                if ($term && !is_wp_error($term) && !in_array($term->name,$names,true)) { $names[]=$term->name; }
            }
        }
        $signals=[];
        if ((float)($result['factors']['experience']['ratio']??0)>=0.12) { $signals[]='experience'; }
        if ((float)($result['factors']['participation']['ratio']??0)>0) { $signals[]='participation'; }

        $parts=[];
        if ($names) { $parts[]='Comparten '.implode(', ',array_slice($names,0,4)).'.'; }
        if (in_array('experience',$signals,true)) { $parts[]='Tienen experiencia profesional relacionada.'; }
        if (in_array('participation',$signals,true)) { $parts[]='Participan en temas similares dentro de la comunidad.'; }
        if (!$parts) { $parts[]='Completen sus intereses y experiencia para descubrir más afinidades.'; }

        $affinity=[
            'score'=>$result['score'],
            'shared'=>$names,
            'signals'=>$signals,
            'explanation'=>implode(' ',$parts),
        ];
        if($explain){
            $prose=NetworkingAI::generate('matching',NetworkingAI::context($a,$b,$affinity),[$a,$b]);
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
        $minimum=max(0,min(100,(int)(Settings::get()['matching_min_affinity']??30)));
        // First pass uses profile + professional experience only. Public
        // activity can add at most five points, so we only calculate the more
        // expensive participation signal for a small shortlist near the
        // configured threshold. This keeps RF-040 useful at community scale.
        $shortlist=[]; $preMinimum=max(0,$minimum-5);
        foreach (get_users(['capability'=>'ascla_access']) as $candidate) {
            $id=(int)$candidate->ID;
            if ($id===$me) { continue; }
            try {
                $affinity=self::between($me,$id,false,false);
                if ((int)$affinity['score'] < $preMinimum) { continue; }
                $shortlist[]=['id'=>$id,'score'=>(int)$affinity['score']];
            } catch (\ASCLA\Core\Rest\ApiException $e) { continue; }
        }
        usort($shortlist,static fn($a,$b)=>($b['score']<=>$a['score'])?:($a['id']<=>$b['id']));
        $items=[];
        foreach (array_slice($shortlist,0,24) as $candidate) {
            $id=(int)$candidate['id'];
            try {
                $affinity=self::between($me,$id,false,true);
                if ((int)$affinity['score'] < $minimum) { continue; }
                $items[]=array_merge(Profiles::visible($id),['affinity'=>$affinity]);
            } catch (\ASCLA\Core\Rest\ApiException $e) { continue; }
        }
        usort($items,static fn($a,$b)=>($b['affinity']['score']<=>$a['affinity']['score'])?:($a['id']<=>$b['id']));
        return Connections::attach(array_slice($items,0,6));
    }

    public static function intro(int $id): array
    {
        $me=get_current_user_id();$affinity=self::between($me,$id,false);
        $context=NetworkingAI::context($me,$id,$affinity);
        $result=NetworkingAI::generate('intro',$context,[$me,$id]);
        $text=trim(Access::excerpt($result['text']??'',5000));
        // A suggested private message must sound like the member, never like the AI service itself.
        if($text==='' || preg_match('/\b(?:soy|somos)\s+(?:(?:el|la|un|una)\s+)?(?:asistente|chatbot|ASCLA)\b|\b(?:como|en calidad de)\s+(?:asistente|chatbot)\b/iu',$text)){
            $safe=(new \ASCLA\Core\Integrations\MockAIProvider())->generate('intro',$context);
            $text=trim(Access::excerpt($safe['text']??'',5000));
            $result['conversation_proposal']=$safe['conversation_proposal']??'';
            $result['fallback']=true;
        }
        return ['text'=>$text,'conversation_proposal'=>Access::excerpt($result['conversation_proposal']??'',2000),'mode'=>$result['mode']??'IA','fallback'=>!empty($result['fallback']),'sent'=>false];
    }

    /**
     * Build privacy-safe topic signatures from public community activity.
     * Only published content that the current viewer can read participates.
     * Private chats, support requests and event attendance are deliberately
     * excluded so the score cannot reveal those relationships indirectly.
     */
    private static function participationTopics(int $userId): array
    {
        $cacheKey=get_current_user_id().':'.$userId;
        if (isset(self::$participationCache[$cacheKey])) { return self::$participationCache[$cacheKey]; }
        $ids=[];
        foreach (Store::rows('relations','user_id=%d AND kind IN (%s,%s)',[$userId,'like','follow'],'ORDER BY id DESC LIMIT 60') as $row) {
            $ids[]=(int)$row['target_id'];
        }
        foreach (get_comments(['user_id'=>$userId,'status'=>'approve','number'=>50,'fields'=>'ids']) as $commentId) {
            $comment=get_comment((int)$commentId); if ($comment) { $ids[]=(int)$comment->comment_post_ID; }
        }
        $authored=get_posts([
            'author'=>$userId,
            'post_type'=>['ascla_hub','ascla_topic','ascla_resource','ascla_gallery'],
            'post_status'=>'publish','posts_per_page'=>30,'fields'=>'ids','orderby'=>'date','order'=>'DESC',
        ]);
        $ids=array_values(array_unique(array_merge($ids,array_map('intval',$authored))));
        if (!$ids) { return []; }

        $topics=[];
        foreach (array_slice($ids,0,80) as $postId) {
            $post=get_post($postId);
            if (!$post || $post->post_status!=='publish' || !str_starts_with($post->post_type,'ascla_') || !Content::canRead($post)) { continue; }
            foreach (['ascla_interest','ascla_category','ascla_tag'] as $taxonomy) {
                $terms=wp_get_object_terms($postId,$taxonomy,['fields'=>'ids']);
                if (is_wp_error($terms)) { continue; }
                foreach ($terms as $termId) { $topics[]=$taxonomy.':'.(int)$termId; }
            }
        }
        $topics=array_values(array_unique($topics)); sort($topics,SORT_STRING);
        return self::$participationCache[$cacheKey]=array_slice($topics,0,60);
    }
}
