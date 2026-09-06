<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Integrations\{AIProviderInterface,MockAIProvider,RealAIProvider,MockVideoProvider,YouTubeVideoProvider};
use ASCLA\Core\Domain\Anonymizer;
final class Knowledge
{
    public static function provider(): AIProviderInterface { return Settings::get()['ai_mode']==='real'?new RealAIProvider():new MockAIProvider(); }
    public static function answer(string $question): array
    {
        $question=Access::text($question,2000);
        $tokens=array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($question))?:[],static fn($w)=>mb_strlen($w)>3&&!in_array($w,['como','cómo','para','sobre','puedo','quiero','tiene','donde','cuáles','ascla'],true))));
        $tokens=array_slice($tokens,0,16);
        $ranked=[];
        $posts=\ASCLA\Core\Repositories\KnowledgeSearch::candidates($tokens);
        foreach ($posts as $post) {
            if (!Content::canRead($post)) { continue; }
            $meta=(array)get_post_meta($post->ID,'_ascla',true);
            if (!empty($meta['generated'])&&empty($meta['reviewed'])) { continue; }
            $body=wp_strip_all_tags($post->post_content);
            if (!empty($meta['chatham'])) {
                $identities=preg_split('/[\n,;]+/u',$meta['identities']??'')?:[];
                $body=Anonymizer::redact($body,$identities);
            }
            $title=!empty($meta['chatham'])?Anonymizer::redact($post->post_title,$identities):$post->post_title;
            $hay=mb_strtolower($title.' '.$body); $score=0;
            foreach ($tokens as $token) { if (str_contains($hay,$token)) { $score++; } }
            if ($score>=max(1,(int)ceil(count($tokens)*0.5))) { $ranked[]=['id'=>$post->ID,'title'=>$title,'body'=>mb_substr($body,0,5000),'score'=>$score,'url'=>Content::serialize($post)['url']]; }
        }
        usort($ranked,static fn($a,$b)=>$b['score']<=>$a['score']); $sources=array_slice($ranked,0,4);
        if (!$sources) { return ['answer'=>'No existe suficiente información en el Centro de Conocimiento para responder esta consulta.','sources'=>[],'mode'=>self::provider()->mode()]; }
        $result=self::provider()->generate('answer',['question'=>$question,'sources'=>$sources]);
        $ids=array_map('intval',(array)($result['source_ids']??[]));
        $used=array_values(array_filter($sources,static fn($s)=>in_array($s['id'],$ids,true)));
        if (!$used) { return ['answer'=>'No existe suficiente información verificable para responder esta consulta.','sources'=>[],'mode'=>self::provider()->mode()]; }
        return ['answer'=>Access::text($result['answer']??'',20000),'sources'=>array_map(static fn($s)=>['id'=>$s['id'],'title'=>$s['title'],'url'=>$s['url']],$used),'mode'=>self::provider()->mode()];
    }
    public static function multimedia(int $id): array
    {
        $post=Content::get($id); Access::require($post->post_type==='ascla_resource','Seleccione un recurso.',400);
        $meta=(array)get_post_meta($id,'_ascla',true); $transcript=$meta['transcript']??''; $videoMode='Transcripción manual';
        if (!$transcript) {
            $provider=Settings::get()['youtube_mode']==='real'?new YouTubeVideoProvider():new MockVideoProvider();
            $video=$provider->transcript($meta['video_id']??''); $transcript=$video['text']; $videoMode=$video['mode'];
        }
        Access::require(mb_strlen($transcript)>=30,'La transcripción es insuficiente.',400);
        $identities=preg_split('/[\n,;]+/u',$meta['identities']??'')?:[];
        if (!empty($meta['chatham'])) {
            foreach (get_users(['capability'=>'ascla_access']) as $user) {
                $p=(array)get_user_meta($user->ID,'_ascla_profile',true);
                $identities[]=$user->display_name; $identities[]=$p['company']??''; $identities[]=$p['position']??'';
            }
            $transcript=Anonymizer::redact($transcript,$identities);
        }
        $result=self::provider()->generate('multimedia',['source_id'=>$id,'transcript'=>$transcript,'chatham'=>!empty($meta['chatham'])]);
        $summary=Access::text($result['summary']??'',15000); $note=Access::text($result['technical_note']??'',30000);
        Access::require($summary!==''&&$note!=='','La IA no devolvió resumen y nota técnica válidos.',502);
        if (!empty($meta['chatham'])) { $summary=Anonymizer::redact($summary,$identities); $note=Anonymizer::redact($note,$identities); }
        $derived=['resource_type'=>'Nota técnica','generated'=>true,'reviewed'=>false,'chatham'=>!empty($meta['chatham']),'source_id'=>$id,'summary'=>$summary,'copyright'=>Settings::get()['copyright'],'ai_mode'=>self::provider()->mode(),'video_mode'=>$videoMode,'infographic'=>$result['infographic']??[],'moments'=>$result['moments']??[],'excerpts'=>$result['excerpts']??[],'frameworks'=>$result['frameworks']??[],'norms'=>$result['norms']??[],'conclusions'=>$result['conclusions']??[],'concepts'=>$result['concepts']??[],'tags'=>$result['tags']??[]];
        // Recursively sanitize every generated field before persistence; metadata is never executable.
        $clean=static function($value) use (&$clean,$identities,$meta) {
            if (is_array($value)) { return array_map($clean,array_slice($value,0,80)); }
            if (is_string($value)) { $text=mb_substr(sanitize_textarea_field($value),0,10000); return !empty($meta['chatham'])?Anonymizer::redact($text,$identities):$text; }
            return is_scalar($value)?$value:null;
        };
        $derived=$clean($derived);
        // Keep only timed excerpts grounded in timestamps actually present in the source.
        $grounded=\ASCLA\Core\Domain\Transcript::moments($transcript);
        $derived['moments']=$grounded; $derived['excerpts']=$grounded;
        $derived['video_id']=$meta['video_id']??'';
        $new=wp_insert_post(wp_slash(['post_type'=>'ascla_resource','post_title'=>'Nota técnica · '.(!empty($meta['chatham'])?'Sesión ASCLA':$post->post_title),'post_content'=>$note,'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
        if (is_wp_error($new)) { throw new \RuntimeException('No fue posible guardar el borrador.'); }
        update_post_meta($new,'_ascla',$derived);
        $hub=wp_insert_post(wp_slash(['post_type'=>'ascla_hub','post_title'=>'Ideas para conversar · Sesión ASCLA','post_content'=>$summary,'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
        if (!is_wp_error($hub)) { update_post_meta($hub,'_ascla',['generated'=>true,'reviewed'=>false,'chatham'=>$derived['chatham'],'source_id'=>$new,'ai_mode'=>self::provider()->mode()]); }
        $capsules=[];
        foreach (array_slice($grounded,0,3) as $index=>$clip) {
            $capsule=wp_insert_post(wp_slash(['post_type'=>'ascla_resource','post_title'=>'Cápsula '.($index+1).' · Sesión ASCLA','post_content'=>$summary,'post_status'=>'draft','post_author'=>get_current_user_id()]),true);
            if (!is_wp_error($capsule)) {
                update_post_meta($capsule,'_ascla',['resource_type'=>'Podcast','generated'=>true,'reviewed'=>false,'chatham'=>$derived['chatham'],'source_id'=>$id,'video_id'=>$meta['video_id']??'','clip'=>$clip,'copyright'=>Settings::get()['copyright'],'ai_mode'=>self::provider()->mode()]);
                $capsules[]=$capsule;
            }
        }
        Audit::record('ai_generated',$new); return ['resource_id'=>$new,'capsule_ids'=>$capsules,'hub_id'=>is_wp_error($hub)?0:$hub,'mode'=>self::provider()->mode(),'video_mode'=>$videoMode,'message'=>'Borradores creados. Revise fuentes, anonimización y derechos antes de publicar.'];
    }
}
