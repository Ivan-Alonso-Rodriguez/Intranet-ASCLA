<?php
namespace ASCLA\Core\Jobs;

final class DeliveryMail
{
    public static function snapshot(array $mail): array
    {
        $files=[];$total=0;
        foreach ((array)($mail['attachments']??[]) as $path) {
            $size=is_file($path)?filesize($path):false;
            if ($size===false || ($total+=$size)>10*1024*1024) { throw new \RuntimeException('No se pudo conservar el adjunto del correo.'); }
            $bytes=file_get_contents($path);
            if ($bytes===false) { throw new \RuntimeException('No se pudo leer el adjunto del correo.'); }
            $files[]=['name'=>sanitize_file_name(basename($path)),'bytes'=>base64_encode($bytes)];
        }
        return array_intersect_key($mail,array_flip(['to','subject','message','headers']))+['files'=>$files];
    }
    public static function send(array $mail,string $key): bool
    {
        if (!function_exists('wp_tempnam')) { require_once ABSPATH.'wp-admin/includes/file.php'; }
        $paths=[];
        try {
            foreach ($mail['files']??[] as $file) {
                $path=wp_tempnam($file['name']);$bytes=base64_decode($file['bytes'],true);
                if (!$path) { throw new \RuntimeException('No se pudo restaurar el adjunto.'); }
                $paths[]=$path;
                if ($bytes===false || file_put_contents($path,$bytes)!==strlen($bytes)) { throw new \RuntimeException('No se pudo restaurar el adjunto.'); }
            }
            return self::identified(static fn()=>wp_mail($mail['to'],$mail['subject'],$mail['message'],$mail['headers']??[],$paths),$key);
        } finally { foreach ($paths as $path) { wp_delete_file($path); } }
    }
    public static function identified(callable $send,string $key): bool
    {
        // Stable across retries. SMTP cannot promise exactly-once delivery after an ambiguous disconnect.
        $messageId='<ascla.'.hash('sha256',$key).'@'.(wp_parse_url(home_url(),PHP_URL_HOST)?:'localhost').'>';
        $identify=static function($mailer) use($messageId) { $mailer->MessageID=$messageId; };
        add_action('phpmailer_init',$identify,PHP_INT_MAX);
        try { return (bool)$send(); }
        finally { remove_action('phpmailer_init',$identify,PHP_INT_MAX); }
    }
}
