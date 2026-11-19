<?php
namespace ASCLA\Core\Jobs;

/** Private mail bodies and addresses never appear in delivery status or plaintext rows. */
final class DeliveryPayload
{
    private static function key(): string { return hash('sha256',wp_salt('auth').'ascla-delivery-v1',true); }
    public static function seal(string $kind,array $payload): string
    {
        $json=wp_json_encode($payload);$iv=random_bytes(12);$tag='';
        if ($json===false) { throw new DeliveryException('No se pudo preparar la entrega.'); }
        $cipher=openssl_encrypt($json,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,$kind);
        if ($cipher===false) { throw new DeliveryException('No se pudo proteger la entrega.'); }
        return base64_encode($iv.$tag.$cipher);
    }
    public static function open(string $kind,string $payload): array
    {
        $raw=base64_decode($payload,true);
        if ($raw===false || strlen($raw)<29) { throw new DeliveryException('Entrega no válida.'); }
        $json=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$kind);
        $data=$json===false?null:json_decode($json,true);
        if (!is_array($data)) { throw new DeliveryException('No se pudo leer la entrega protegida.'); }
        return $data;
    }
}
