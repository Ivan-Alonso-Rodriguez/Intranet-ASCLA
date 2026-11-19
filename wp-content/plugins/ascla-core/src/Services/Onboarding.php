<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Domain\OnboardingTours;
use ASCLA\Core\Repositories\{Store,WordPressWrites,RepositoryException};

/** Per-account walkthrough claims and progress, shared across devices. */
final class Onboarding
{
    private const ROLLOUT='ascla_onboarding_rollout';
    private const ELIGIBLE='_ascla_onboarding_cohort';
    private const PREFIX='_ascla_onboarding_';

    public static function boot(): void
    {
        add_action('init',[self::class,'initialize'],22);
        add_action('user_register',[self::class,'registered']);
    }

    public static function initialize(): array
    {
        $rollout=get_option(self::ROLLOUT,[]);
        if (is_array($rollout) && !empty($rollout['cohort'])) { return $rollout; }
        global $wpdb;
        $last=$wpdb->get_var("SELECT COALESCE(MAX(ID),0) FROM {$wpdb->users}");
        if ($last===null || $wpdb->last_error!=='') { throw new RepositoryException('No se pudo preparar la guía de ASCLA.'); }
        add_option(self::ROLLOUT,['cohort'=>wp_generate_uuid4(),'last_user_id'=>(int)$last],'',false);
        return (array)get_option(self::ROLLOUT,[]);
    }

    public static function registered(int $user): void
    {
        $rollout=self::initialize();
        if ($user>(int)$rollout['last_user_id']) {
            WordPressWrites::meta('user',$user,self::ELIGIBLE,$rollout['cohort']);
        }
    }

    private static function eligible(int $user): bool
    {
        $rollout=self::initialize();
        return $user>(int)$rollout['last_user_id']
            && get_user_meta($user,self::ELIGIBLE,true)===$rollout['cohort'];
    }

    private static function available(): array
    {
        $all=OnboardingTours::all();
        return array_filter($all,static function(array $tour): bool {
            if (!current_user_can($tour['cap']??'ascla_access')) { return false; }
            if ($tour['page']==='admin' && !current_user_can('ascla_admin_area')) { return false; }
            return empty($tour['community_only']) || !Access::isAdministrator();
        });
    }

    private static function tour(string $module,?array $tours=null): array
    {
        $tour=($tours??self::available())[$module]??null;
        Access::require((bool)$tour,'Guía no disponible para esta cuenta.',404);
        $tour['steps']=array_values(array_filter($tour['steps'],static fn(array $step): bool=>current_user_can($step['cap']??'ascla_access')));
        Access::require((bool)$tour['steps'],'Guía no disponible para esta cuenta.',404);
        return $tour;
    }

    private static function progress(string $module,int $user): array
    {
        $state=get_user_meta($user,self::PREFIX.$module,true);
        return is_array($state)?$state:[];
    }

    public static function get(): array
    {
        Access::require(Access::member());
        $user=get_current_user_id();$tours=[];$progress=[];
        $available=self::available();
        foreach ($available as $module=>$definition) {
            $tour=self::tour($module,$available);
            unset($tour['cap'],$tour['community_only']);
            $tours[$module]=$tour;
            $progress[$module]=self::progress($module,$user);
        }
        return ['eligible'=>self::eligible($user),'tours'=>$tours,'progress'=>$progress];
    }

    public static function update(string $module,array $input): array
    {
        Access::require(Access::member());
        $tour=self::tour($module);$user=get_current_user_id();
        Access::require(in_array($input['action']??null,['start','move','complete','skip'],true),'Acción de guía no válida.',400);
        return Store::atomic('onboarding:'.$user.':'.$module,static function () use($module,$input,$tour,$user) {
            // Another browser may have claimed the guide while this request waited.
            wp_cache_delete($user,'user_meta');
            $state=self::progress($module,$user);
            if ($input['action']==='start') {
                $next=self::startState($module,$input,$tour,$user,$state);
                if ($next===null) { return ['started'=>false,'progress'=>$state]; }
                $state=$next;
            } else {
                $state=self::advanceState($input,$tour,$state);
            }
            $state['updated_at']=time();
            WordPressWrites::meta('user',$user,self::PREFIX.$module,$state);
            return ['started'=>true,'progress'=>$state];
        });
    }
    private static function startState(string $module,array $input,array $tour,int $user,array $state): ?array
    {
        $automatic=FormRules::boolean($input['automatic']??false);
        $restart=FormRules::boolean($input['restart']??false);
        if ($automatic && (!self::eligible($user) || metadata_exists('user',$user,self::PREFIX.$module))) { return null; }
        if ($restart || !$state || ($state['status']??'')!=='started') {
            $state=['step'=>$tour['steps'][0]['id'],'status'=>'started'];
        }
        $state['run']=wp_generate_uuid4();
        $state['revision']=0;
        return $state;
    }
    private static function advanceState(array $input,array $tour,array $state): array
    {
        Access::require(($state['status']??'')==='started' && is_string($input['run']??null)
            && hash_equals($state['run']??'',$input['run'])
            && ($input['revision']??null)===($state['revision']??0),'El recorrido cambió en otra sesión. Ábrelo desde Ayuda para continuar.',409);
        if ($input['action']==='move') {
            Access::require(in_array($input['step']??null,array_column($tour['steps'],'id'),true),'Paso de guía no válido.',400);
            $state['step']=$input['step'];
        } else {
            $state['status']=$input['action']==='complete'?'completed':'skipped';
        }
        $state['revision']++;
        return $state;
    }
}
