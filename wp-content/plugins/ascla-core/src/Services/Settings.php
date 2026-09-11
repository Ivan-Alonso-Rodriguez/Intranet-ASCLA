<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Domain\MatchScore;
final class Settings
{
    public static function get(): array
    {
        return array_merge(['mail_mode'=>'wordpress','smtp_host'=>'','smtp_port'=>587,'smtp_security'=>'tls','smtp_user'=>'','smtp_from'=>'','smtp_name'=>'ASCLA','demo'=>true,'moderation_required'=>true,'moderate_comments'=>false,'chatham_default'=>true,'micro_enabled'=>false,'micro_approval'=>true,'ai_mode'=>'mock','ai_model'=>'','matching_weights'=>MatchScore::WEIGHTS,'google_client_id'=>'','youtube_mode'=>'mock','social_mode'=>'mock','copyright'=>'© ASCLA – Asociación de Secretarios Corporativos de América Latina'],(array)get_option('ascla_settings',[]));
    }
    public static function save(array $input): array
    {
        $data=self::get();
        foreach (['demo','moderation_required','moderate_comments','chatham_default','micro_enabled','micro_approval'] as $flag) { if (isset($input[$flag])) { $data[$flag]=rest_sanitize_boolean($input[$flag]); } }
        foreach (['ai_mode','youtube_mode'] as $field) { if (isset($input[$field])) { Access::require(in_array($input[$field],['mock','real'],true),'Modo no válido.',400); $data[$field]=$input[$field]; } }
        foreach (['ai_model','google_client_id','copyright'] as $field) { if (isset($input[$field])) { $data[$field]=Access::text($input[$field],300); } }
        if (isset($input['ai_model']) && $data['ai_model']!=='') { $data['ai_model']=\ASCLA\Core\Integrations\RealAIProvider::model($data['ai_model']); }
        if (isset($input['matching_weights'])) {
            $weights=[];
            foreach (MatchScore::WEIGHTS as $key=>$default) { $weights[$key]=max(0,min(100,(int)($input['matching_weights'][$key]??$default))); }
            Access::require(array_sum($weights)>0,'Los pesos deben sumar más de cero.',400); $data['matching_weights']=$weights;
        }
        $data=\ASCLA\Core\Integrations\Mailer::validate($input,$data);
        foreach (['ai_key','google_client_secret'] as $secret) {
            if (!empty($input[$secret])) { \ASCLA\Core\Integrations\Secrets::set($secret,Access::text($input[$secret],2000)); }
            if (!empty($input['clear_'.$secret])) { \ASCLA\Core\Integrations\Secrets::remove($secret); }
        }
        if (!empty($input['smtp_password'])) { \ASCLA\Core\Integrations\Secrets::set('smtp_password',$input['smtp_password']); }
        if (!empty($input['clear_smtp_password'])) { \ASCLA\Core\Integrations\Secrets::remove('smtp_password'); }
        update_option('ascla_settings',$data,false); Audit::record('settings_updated'); return self::status();
    }
    public static function status(): array
    {
        $settings=self::get(); $settings['has_ai_key']=\ASCLA\Core\Integrations\Secrets::get('ai_key')!=='';
        $settings['has_google_secret']=\ASCLA\Core\Integrations\Secrets::get('google_client_secret')!=='';
        $settings['google_connected']=\ASCLA\Core\Integrations\Secrets::get('google_calendar_'.get_current_user_id())!=='';
        $settings['google_redirect']=admin_url('admin-post.php?action=ascla_google_callback');
        return $settings+\ASCLA\Core\Integrations\Mailer::status();
    }
}
