<?php
namespace ASCLA\Core\Frontend;
final class Icons
{
    public const PATHS=[
        'home'=>'M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z',
        'users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'calendar'=>'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
        'hub'=>'M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8Z',
        'gallery'=>'M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1ZM3 16l5-5 4 4 4-5 5 6M8 7h.01',
        'book'=>'M12 7v14M3 3h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5v17h-5a4 4 0 0 0-4 1 4 4 0 0 0-4-1H3Z',
        'spark'=>'m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4ZM20 2v4M18 4h4',
        'ally'=>'m12 3 3 5 6 1-4 5 1 7-6-3-6 3 1-7-4-5 6-1Z',
        'mail'=>'M3 5h18v14H3ZM3 5l9 7 9-7',
        'contact'=>'M22 2 9 15M22 2l-7 20-6-7-7-6Z',
        'search'=>'M21 21l-5-5M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14',
        'bell'=>'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4',
        'arrow'=>'M5 12h14m-6-6 6 6-6 6',
        'chevron'=>'m9 5 7 7-7 7',
        'plus'=>'M12 5v14M5 12h14',
        'close'=>'m6 6 12 12M6 18 18 6',
        'trash'=>'M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5',
        'pin'=>'M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0ZM12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
        'clock'=>'M12 8v4l3 3M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20',
        'heart'=>'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z',
        'logout'=>'M9 21H4V3h5M15 17l5-5-5-5M20 12H9',
        'shield'=>'m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6ZM8 12l3 3 5-6',
        'menu'=>'M3 6h18M3 12h18M3 18h18',
        'download'=>'M12 3v12m-5-5 5 5 5-5M5 17v4h14v-4',
        'play'=>'m8 5 12 7-12 7Z',
        'settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2',
        'sun'=>'M12 4V2M12 22v-2M4.93 4.93 3.51 3.51M20.49 20.49l-1.42-1.42M4 12H2M22 12h-2M4.93 19.07l-1.42 1.42M20.49 3.51l-1.42 1.42M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        'moon'=>'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z',
        'globe'=>'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20ZM2 12h20M12 2a15.3 15.3 0 0 1 0 20M12 2a15.3 15.3 0 0 0 0 20',
        'check'=>'m5 12 4 4L19 6',
        'edit'=>'m15 4 5 5M4 20l4-1L21 6l-4-4L4 15Z',
        'more'=>'M5 12h.01M12 12h.01M19 12h.01',
        'reply'=>'M9 17l-5-5 5-5M4 12h9a7 7 0 0 1 7 7',
    ];
    public static function html(string $name): string
    {
        $path=self::PATHS[$name]??self::PATHS['users'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.esc_attr($path).'"/></svg>';
    }
}
