<?php
namespace ASCLA\Core\Integrations;
final class Secrets
{
    private static function key(): string { return hash('sha256',wp_salt('auth').'ascla-secrets-v1',true); }
    public static function set(string $name,string $value): void
    {
        $iv=random_bytes(12); $tag='';
        $cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,$name);
        if ($cipher===false) { throw new \RuntimeException('No fue posible cifrar la credencial.'); }
        update_option('ascla_secret_'.$name,base64_encode($iv.$tag.$cipher),false);
    }
    public static function get(string $name): string
    {
        $constant='ASCLA_'.strtoupper($name);
        if (defined($constant)) { return (string)constant($constant); }
        $raw=base64_decode((string)get_option('ascla_secret_'.$name,''),true);
        if (!$raw||strlen($raw)<29) { return ''; }
        return openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$name)?:'';
    }
    public static function remove(string $name): void { delete_option('ascla_secret_'.$name); }
}
