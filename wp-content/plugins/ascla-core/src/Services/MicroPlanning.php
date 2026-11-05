<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\GroupPlanner;

/** A bounded batch, one proposal per interest, with independent consent. */
final class MicroPlanning
{
    public static function interval(): int { return max(7,min(90,(int)(Settings::get()['micro_interval_days']??7))); }
    public static function period(): string
    {
        $days=self::interval();
        if ($days===7) { return wp_date('o-\WW'); }
        return $days.'d-'.intdiv(time(),$days*DAY_IN_SECONDS);
    }
    public static function schedule(): void
    {
        wp_clear_scheduled_hook('ascla_monthly');
        $scheduled=wp_get_scheduled_event('ascla_microevents');
        if ($scheduled && (int)$scheduled->interval!==self::interval()*DAY_IN_SECONDS) { wp_clear_scheduled_hook('ascla_microevents');$scheduled=false; }
        if (!$scheduled) { wp_schedule_event(time()+120,'ascla_micro_period','ascla_microevents'); }
    }
    public static function settings(array &$data,array $input): void
    {
        foreach (['micro_min','micro_capacity','micro_limit','micro_priority_hours','micro_interval_days'] as $key) {
            if (!array_key_exists($key,$input)) { continue; }
            $data[$key]=FormRules::integer($key,$input[$key],'Parámetro de microeventos fuera del rango permitido.');
        }
        Access::require($data['micro_min']<=$data['micro_capacity'],'El mínimo no puede superar los cupos.',400);
        $data['micro_approval']=true;
    }
    public static function plan(array $profiles,array $history,array $blocked): array
    {
        $settings=Settings::get();$topics=[];$used=[];$groups=[];
        foreach ($profiles as $p) { foreach ((array)($p['interests']??[]) as $topic) { $topics[(int)$topic]=($topics[(int)$topic]??0)+1; } }
        arsort($topics);
        foreach (array_keys($topics) as $topic) {
            $candidates=array_values(array_filter($profiles,static fn($p)=>!isset($used[$p['id']]) && in_array($topic,array_map('intval',(array)($p['interests']??[])),true)));
            $plan=GroupPlanner::plan($candidates,$history,(int)wp_date('W'),$blocked,$settings['micro_min'],$settings['micro_capacity'],1);
            if (!$plan['groups']) { continue; }
            $group=$plan['groups'][0];$groups[]=['members'=>$group,'topic'=>$topic];
            foreach ($group as $uid) { $used[$uid]=true; }
            if (count($groups)>=$settings['micro_limit']) { break; }
        }
        return ['groups'=>$groups,'waiting'=>array_values(array_diff(array_column($profiles,'id'),array_keys($used)))];
    }

    public static function diagnostics(array $profiles,array $blocked,array $plan): array
    {
        $minimum=(int)Settings::get()['micro_min'];$topics=[];
        foreach ($profiles as $profile) {
            foreach (array_unique(array_map('intval',(array)($profile['interests']??[]))) as $topic) { $topics[$topic]=($topics[$topic]??0)+1; }
        }
        $shared=$topics?max($topics):0;
        $reason=match(true) {
            !empty($plan['groups'])=>'ready',
            count($profiles)<$minimum=>'insufficient_candidates',
            $shared<$minimum=>'insufficient_shared_interest',
            !empty($blocked)=>'incompatible_group',
            default=>'no_group',
        };
        return ['eligible'=>count($profiles),'minimum'=>$minimum,'capacity'=>(int)Settings::get()['micro_capacity'],
            'max_shared_interest'=>$shared,'blocked_pairs'=>count($blocked),'reason'=>$reason];
    }
}
