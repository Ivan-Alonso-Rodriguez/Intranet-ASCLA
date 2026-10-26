<?php
namespace ASCLA\Core\Services;

/** Keep challenge state opaque; cookie purpose is authenticated with AES-GCM. */
final class TurnstileCookie
{
    private static function key(): string
    {
        return hash('sha256',wp_salt('secure_auth').'ascla-turnstile-cookie-v1',true);
    }

    public static function seal(string $purpose,string $payload): string
    {
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($payload,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,$purpose);
        if ($cipher===false) { throw new ChallengeStateException('Unable to protect challenge state.'); }
        return 'v1.'.rtrim(strtr(base64_encode($iv.$tag.$cipher),'+/','-_'),'=');
    }

    public static function open(string $purpose,string $value): string
    {
        if (strlen($value)>2048) { return ''; }
        // Existing signed cookies remain subject to the caller's signature, IP,
        // user-agent and expiry checks until their original lifetime ends.
        if (!str_starts_with($value,'v1.')) { return $value; }
        $raw=base64_decode(strtr(substr($value,3),'-_','+/'),true);
        return self::decrypt($purpose,$raw);
    }

    private static function decrypt(string $purpose,string|false $raw): string
    {
        if ($raw===false || strlen($raw)<29) { return ''; }
        $payload=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$purpose);
        return $payload===false?'':$payload;
    }
}
