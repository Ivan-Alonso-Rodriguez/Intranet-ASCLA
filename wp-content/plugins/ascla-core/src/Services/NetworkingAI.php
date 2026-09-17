<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Integrations\{MockAIProvider,Secrets};
use ASCLA\Core\Repositories\{NetworkingCache,Store};

/** Optional prose generation; deterministic scores and explicit sending stay separate. */
final class NetworkingAI
{
    private const DEMO_MODE='DEMO MODE';
    /** Pair IDs are local cache metadata and are never sent to the provider. */
    public static function generate(string $task,array $context,array $pair=[]): array
    {
        $settings=Settings::get();$selected=$settings['ai_provider']??'mock';
        $configured=match($selected){'gemini'=>Secrets::get('ai_key')!==''&&!empty($settings['ai_model']),'openai'=>Secrets::get('openai_key')!==''&&!empty($settings['openai_model']),default=>true};
        $fallback=$selected!=='mock'&&!$configured;
        $provider=$fallback?new MockAIProvider():Knowledge::provider();
        $model=$selected==='openai'?($settings['openai_model']??''):($settings['ai_model']??'');
        $persistent=count($pair)===2 && in_array($task,['matching','intro'],true);
        $fingerprints=$persistent?array_map([self::class,'profileFingerprint'],$pair):[];
        $key='ascla_prose_'.hash('sha256',wp_json_encode([$task,$context,$selected,$configured,$provider->mode(),$model,$pair,$fingerprints,'v4']));
        $read=static function(bool $fresh=false)use($persistent,$pair,$task,$key){
            if($persistent){
                $saved=NetworkingCache::get($pair[0],$pair[1],$task,$key,$fresh);
                if($saved!==null){return $saved;}
            }
            $cached=get_transient($key);return is_array($cached)?$cached:null;
        };
        $cached=$read();if($cached!==null){return $cached;}
        try {
            // A concurrent visitor receives the deterministic fallback without another API call.
            return Store::lock($key,static function()use($read,$provider,$fallback,$task,$context,$persistent,$pair,$key){
                $cached=$read(true);if($cached!==null){return $cached;}
                try {
                    Access::limit('network-ai',20,300);
                    $result=self::validateProse($task,$provider->generate($task,$context));
                } catch(\RuntimeException $error){
                    Audit::record('network_ai_unavailable',0,$task);$provider=new MockAIProvider();$fallback=true;
                    $result=self::validateProse($task,$provider->generate($task,$context));
                }
                $result['mode']=$provider->mode();$result['fallback']=$fallback;
                if($persistent&&!$fallback){NetworkingCache::put($pair[0],$pair[1],$task,$key,$result);}
                else{set_transient($key,$result,$fallback?MINUTE_IN_SECONDS:HOUR_IN_SECONDS);}
                return $result;
            },0);
        } catch(\RuntimeException $error){
            $result=(new MockAIProvider())->generate($task,$context);
            $result['mode']=self::DEMO_MODE;$result['fallback']=true;
            return $result; // Never overwrite an in-flight successful result with a busy fallback.
        }
    }
    private static function profileFingerprint(int $id): string
    {
        // Invalidate relevant edits and privacy changes, not photos, birthdays or email preferences.
        // Only this hash is cached; raw/private values do not enter the AI context.
        $fields=['name','first_name','last_name','position','company','country','city','bio','experience','interests','areas','industries','goals','languages','learn','help','connect_topics','hidden','directory','networking'];
        return hash('sha256',wp_json_encode(array_intersect_key(Profiles::raw($id),array_flip($fields))));
    }
    private static function validateProse(string $task,array $result): array
    {
        if($task==='microagenda'){return self::validateAgenda($result);}
        $fields=match($task){'matching'=>['explanation'=>2000,'conversation_proposal'=>2000],'intro'=>['text'=>5000,'conversation_proposal'=>2000],default=>[]};
        $clean=[];
        foreach($fields as $field=>$limit){
            Access::require(is_string($result[$field]??null),'Respuesta de IA incompleta.',502);
            $text=trim(Access::excerpt($result[$field],$limit));
            Access::require($text!=='','Respuesta de IA vacía.',502);$clean[$field]=$text;
        }
        if($task==='intro'){
            Access::require(!preg_match('/\b(?:soy|somos)\s+(?:(?:el|la|un|una)\s+)?(?:asistente|chatbot|ASCLA)\b|\b(?:como|en calidad de)\s+(?:asistente|chatbot)\b/iu',$clean['text']),'Mensaje de IA no válido.',502);
        }
        return $fields?$clean:$result;
    }
    public static function context(int $a,int $b,array $affinity): array
    {
        return ['score'=>$affinity['score'],'shared'=>$affinity['shared'],'signals'=>$affinity['signals']??[],'left'=>Profiles::networkingContext($a),'right'=>Profiles::networkingContext($b)];
    }
    public static function agenda(array $group,bool $demo=false): array
    {
        $context=['group_size'=>count($group),'interests'=>[],'positions'=>[],'industries'=>[],'goals'=>[],'areas'=>[]];
        foreach($group as $uid){
            $p=Profiles::networkingContext($uid);
            foreach(['interests','industries','goals','areas'] as $field){ $context[$field]=array_merge($context[$field],$p[$field]??[]); }
            if($p['position']!==''){ $context['positions'][]=$p['position']; }
        }
        $counts=array_count_values($context['interests']);
        $context['common_interests']=array_keys(array_filter($counts,static fn($count)=>$count>=2));
        foreach(['interests','positions','industries','goals','areas'] as $field){ $context[$field]=array_values(array_unique($context[$field])); }
        $result=$demo?(new MockAIProvider())->generate('microagenda',$context):self::generate('microagenda',$context);
        $mode=$demo?self::DEMO_MODE:($result['mode']??self::DEMO_MODE);
        $result=\ASCLA\Core\Domain\EntityRedactor::tree($result);
        $result['mode']=$mode;
        try{return self::validateAgenda($result);}
        catch(\ASCLA\Core\Rest\ApiException $error){$result=(new MockAIProvider())->generate('microagenda',$context);$result['fallback']=true;return self::validateAgenda($result);}
    }
    public static function validateAgenda(array $result): array
    {
        $duration=filter_var($result['duration_minutes']??null,FILTER_VALIDATE_INT);
        Access::require($duration!==false&&$duration>=30&&$duration<=60,'Duración de agenda no válida.',502);
        $items=$result['agenda']??[];Access::require(is_array($items)&&count($items)>=2&&count($items)<=8,'Agenda no válida.',502);
        $agenda=[];$total=0;
        foreach($items as $item){
            Access::require(is_array($item),'Bloque de agenda no válido.',502);$minutes=filter_var($item['minutes']??null,FILTER_VALIDATE_INT);
            Access::require($minutes!==false&&$minutes>0&&$minutes<=60,'Minutos no válidos.',502);$total+=$minutes;
            $topic=trim(Access::text($item['topic']??'',600));Access::require($topic!=='','Tema vacío.',502);$agenda[]=['minutes'=>$minutes,'topic'=>$topic];
        }
        Access::require($total===$duration,'Los bloques no suman la duración.',502);
        $clean=['duration_minutes'=>$duration,'agenda'=>$agenda,'mode'=>$result['mode']??self::DEMO_MODE,'fallback'=>!empty($result['fallback'])];
        foreach(['title','theme','objective','icebreaker','closing_question'] as $key){$text=trim(Access::text($result[$key]??'',1000));Access::require($text!=='','Agenda incompleta.',502);$clean[$key]=$text;}
        return $clean;
    }
}
