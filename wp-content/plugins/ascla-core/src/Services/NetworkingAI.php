<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Integrations\{MockAIProvider,Secrets};

/** Optional prose generation; deterministic scores and explicit sending stay separate. */
final class NetworkingAI
{
    private const DEMO_MODE='DEMO MODE';
    public static function generate(string $task,array $context): array
    {
        $settings=Settings::get();$fallback=$settings['ai_mode']==='real'&&(!Secrets::get('ai_key')||!$settings['ai_model']);
        $provider=$fallback?new MockAIProvider():Knowledge::provider();
        $key='ascla_prose_'.hash('sha256',wp_json_encode([$task,$context,$provider->mode(),$settings['ai_model'],'v1']));
        $cached=get_transient($key);if(is_array($cached)){ return $cached; }
        Access::limit('network-ai',20,300);
        try {$result=$provider->generate($task,$context);}
        catch(\RuntimeException $error){
            Audit::record('network_ai_unavailable',0,$task);$provider=new MockAIProvider();$fallback=true;$result=$provider->generate($task,$context);
        }
        $result['mode']=$provider->mode();$result['fallback']=$fallback;
        set_transient($key,$result,$fallback?MINUTE_IN_SECONDS:HOUR_IN_SECONDS);
        return $result;
    }
    public static function context(int $a,int $b,array $affinity): array
    {
        return ['score'=>$affinity['score'],'shared'=>$affinity['shared'],'left'=>Profiles::networkingContext($a),'right'=>Profiles::networkingContext($b)];
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
