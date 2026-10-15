<?php
namespace ASCLA\Core\Integrations;
final class Secrets
{
    public const SERVICE_KEYS=['ai_key','openai_key','deepseek_key','google_client_secret','turnstile_secret','smtp_password'];
    public static function externalOnly(): bool
    {
        $value=defined('ASCLA_EXTERNAL_SECRETS_ONLY')?constant('ASCLA_EXTERNAL_SECRETS_ONLY'):getenv('ASCLA_EXTERNAL_SECRETS_ONLY');
        if ($value===false || $value==='') {
            foreach (self::SERVICE_KEYS as $name) { if (self::hasLegacy($name)) { return false; } }
            return true;
        }
        // An invalid server flag must not silently enable writes to the legacy store.
        return filter_var($value,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE)??true;
    }
    public static function source(string $name): string
    {
        $constant='ASCLA_'.strtoupper($name);
        if (defined($constant)) { return 'constant'; }
        if (getenv($constant)!==false) { return 'environment'; }
        $fallback=self::hasLegacy($name)?'encrypted_option':'absent';
        return self::externalOnly() && in_array($name,self::SERVICE_KEYS,true)?'external_required':$fallback;
    }
    public static function hasLegacy(string $name): bool { return get_option('ascla_secret_'.$name,'')!==''; }
    public static function managedExternally(string $name): bool { return in_array(self::source($name),['constant','environment','external_required'],true); }
    private static function key(): string { return hash('sha256',wp_salt('auth').'ascla-secrets-v1',true); }
    public static function set(string $name,string $value): void
    {
        if (self::managedExternally($name)) { throw new IntegrationException('Esta credencial se administra en el servidor.'); }
        $iv=random_bytes(12); $tag='';
        $cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,$name);
        if ($cipher===false) { throw new IntegrationException('No fue posible cifrar la credencial.'); }
        \ASCLA\Core\Repositories\WordPressWrites::option('ascla_secret_'.$name,base64_encode($iv.$tag.$cipher));
    }
    public static function get(string $name): string
    {
        $constant='ASCLA_'.strtoupper($name);
        if (defined($constant)) { return (string)constant($constant); }
        $environment=getenv($constant);
        if ($environment!==false) { return $environment; }
        return self::externalOnly() && in_array($name,self::SERVICE_KEYS,true)?'':self::legacy($name);
    }
    private static function legacy(string $name): string
    {
        $raw=base64_decode((string)get_option('ascla_secret_'.$name,''),true);
        if (!$raw||strlen($raw)<29) { return ''; }
        $value=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$name);
        return $value===false?'':$value;
    }
    public static function remove(string $name): void { delete_option('ascla_secret_'.$name); }
}
