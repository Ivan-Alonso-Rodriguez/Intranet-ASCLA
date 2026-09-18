<?php
namespace ASCLA\Core\Integrations;

/** Escaped, table-based HTML. Independent of SMTP and safe with or without a CTA. */
final class EmailTemplate
{
    public static function render(string $title,string $name,string $message,string $button='',string $url='',string $extra=''): string
    {
        $safe=esc_url($url,['https','http']);$logo=esc_url(ASCLA_URL.'assets/ascla-logo.png');$home=esc_url(home_url('/'));
        $body=nl2br(esc_html($message));
        $cta=$safe!=='' && $button!==''?'<table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0"><tr><td bgcolor="#147bb5" style="border-radius:6px"><a href="'.$safe.'" style="display:inline-block;padding:14px 24px;color:#ffffff;text-decoration:none;font-weight:bold">'.esc_html($button).'</a></td></tr></table>':'';
        return '<!doctype html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).'</title></head><body style="margin:0;padding:0;background:#f2f6fa;color:#18334d;font-family:Arial,Helvetica,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" bgcolor="#f2f6fa"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dfe8ef"><tr><td style="padding:28px;border-bottom:3px solid #147bb5"><a href="'.$home.'"><img src="'.$logo.'" width="190" alt="ASCLA" style="display:block;border:0;width:190px;max-width:100%;height:auto"></a></td></tr><tr><td style="padding:28px;font-size:16px;line-height:1.6"><h1 style="margin:0 0 22px;font-size:24px;color:#102f50">'.esc_html($title).'</h1><p>Hola, '.esc_html($name).':</p><p>'.$body.'</p>'.$cta.($extra!==''?'<p style="font-size:13px;color:#52677b">'.nl2br(esc_html($extra)).'</p>':'').'</td></tr><tr><td style="padding:22px 28px;background:#102f50;color:#ffffff;font-size:12px;line-height:1.6">ASCLA · Comunidad profesional<br>Asociación de Secretarios Corporativos de América Latina<br><a href="'.$home.'" style="color:#ffffff">Visitar ASCLA</a></td></tr></table></td></tr></table></body></html>';
    }
}
