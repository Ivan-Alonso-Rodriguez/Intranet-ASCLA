<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\MatchScore;
final class Settings
{
    public static function get(): array
    {
        $stored=(array)get_option('ascla_settings',[]);
        $settings=array_merge(['mail_mode'=>'wordpress','smtp_host'=>'','smtp_port'=>587,'smtp_security'=>'tls','smtp_user'=>'','smtp_from'=>'','smtp_name'=>'ASCLA','demo'=>true,'moderation_required'=>true,'moderate_comments'=>false,'chatham_default'=>true,'micro_enabled'=>false,'micro_approval'=>true,'ai_mode'=>'mock','ai_provider'=>'mock','ai_model'=>'','openai_model'=>'gpt-5.6-luna','matching_weights'=>MatchScore::WEIGHTS,'matching_min_affinity'=>30,'google_client_id'=>'','youtube_mode'=>'mock','social_mode'=>'mock','turnstile_enabled'=>false,'turnstile_site_key'=>'','turnstile_login'=>true,'turnstile_recovery'=>true,'turnstile_public'=>true,'copyright'=>'© ASCLA – Asociación de Secretarios Corporativos de América Latina'],$stored);
        // Public self-registration is intentionally unsupported in ASCLA. Remove any legacy 1.9.13 flag.
        unset($settings['turnstile_register']);
        // Backward compatibility with installations that only stored ai_mode before 1.9.11.
        if (!array_key_exists('ai_provider',$stored)) { $settings['ai_provider']=($settings['ai_mode']??'mock')==='real'?'gemini':'mock'; }
        $settings['ai_mode']=$settings['ai_provider']==='mock'?'mock':'real';
        return $settings;
    }
    public static function save(array $input): array
    {
        $data=self::get();
        foreach (['demo','moderation_required','moderate_comments','chatham_default','micro_enabled','micro_approval','turnstile_enabled','turnstile_login','turnstile_recovery','turnstile_public'] as $flag) { if (isset($input[$flag])) { $data[$flag]=rest_sanitize_boolean($input[$flag]); } }
        if (isset($input['ai_provider'])) { Access::require(in_array($input['ai_provider'],['mock','gemini','openai'],true),'Proveedor de IA no válido.',400); $data['ai_provider']=$input['ai_provider']; }
        elseif (isset($input['ai_mode'])) { Access::require(in_array($input['ai_mode'],['mock','real'],true),'Modo no válido.',400); $data['ai_provider']=$input['ai_mode']==='real'?'gemini':'mock'; }
        $data['ai_mode']=$data['ai_provider']==='mock'?'mock':'real';
        if (isset($input['youtube_mode'])) { Access::require(in_array($input['youtube_mode'],['mock','real'],true),'Modo no válido.',400); $data['youtube_mode']=$input['youtube_mode']; }
        foreach (['ai_model','openai_model','google_client_id','turnstile_site_key','copyright'] as $field) { if (isset($input[$field])) { $data[$field]=Access::text($input[$field],300); } }
        if (isset($input['ai_model']) && $data['ai_model']!=='') { $data['ai_model']=\ASCLA\Core\Integrations\RealAIProvider::model($data['ai_model']); }
        if (isset($input['openai_model']) && $data['openai_model']!=='') { $data['openai_model']=\ASCLA\Core\Integrations\OpenAIProvider::model($data['openai_model']); }
        if (isset($input['matching_min_affinity'])) { $data['matching_min_affinity']=max(0,min(100,(int)$input['matching_min_affinity'])); }
        if (isset($input['matching_weights'])) {
            $weights=[];
            foreach (MatchScore::WEIGHTS as $key=>$default) { $weights[$key]=max(0,min(100,(int)($input['matching_weights'][$key]??$default))); }
            Access::require(array_sum($weights)>0,'Los pesos deben sumar más de cero.',400); $data['matching_weights']=$weights;
        }
        $data=\ASCLA\Core\Integrations\Mailer::validate($input,$data);
        foreach (['ai_key','openai_key','google_client_secret','turnstile_secret'] as $secret) {
            if (!empty($input[$secret])) { \ASCLA\Core\Integrations\Secrets::set($secret,Access::text($input[$secret],2000)); }
            if (!empty($input['clear_'.$secret])) { \ASCLA\Core\Integrations\Secrets::remove($secret); }
        }
        if (!empty($input['smtp_password'])) { \ASCLA\Core\Integrations\Secrets::set('smtp_password',$input['smtp_password']); }
        if (!empty($input['clear_smtp_password'])) { \ASCLA\Core\Integrations\Secrets::remove('smtp_password'); }
        if (!empty($data['turnstile_enabled'])) {
            Access::require(trim((string)$data['turnstile_site_key'])!=='','Ingresa la Site Key de Cloudflare Turnstile antes de activarlo.',400);
            Access::require(\ASCLA\Core\Integrations\Secrets::get('turnstile_secret')!=='','Ingresa la Secret Key de Cloudflare Turnstile antes de activarlo.',400);
        }
        update_option('ascla_settings',$data,false); Audit::record('settings_updated'); return self::status();
    }
    public static function status(): array
    {
        $settings=self::get(); $settings['has_ai_key']=\ASCLA\Core\Integrations\Secrets::get('ai_key')!=='';
        $settings['has_openai_key']=\ASCLA\Core\Integrations\Secrets::get('openai_key')!=='';
        $settings['has_google_secret']=\ASCLA\Core\Integrations\Secrets::get('google_client_secret')!=='';
        $settings['has_turnstile_secret']=\ASCLA\Core\Integrations\Secrets::get('turnstile_secret')!=='';
        $settings['google_connected']=\ASCLA\Core\Integrations\Secrets::get('google_calendar_'.get_current_user_id())!=='';
        $settings['google_redirect']=admin_url('admin-post.php?action=ascla_google_callback');
        return $settings+\ASCLA\Core\Integrations\Mailer::status();
    }
}
