<?php
namespace ASCLA\Core\Domain;
final class Calendar
{
    private static function escape(string $text): string { return str_replace(["\\","\r","\n",';',','],['\\\\','','\\n','\\;','\\,'],$text); }
    public static function ics(int $id,string $title,array $meta,string $description='',string $domain='ascla.local'): string
    {
        $lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//ASCLA//Intranet 2.0//ES','CALSCALE:GREGORIAN','METHOD:PUBLISH','BEGIN:VEVENT','UID:ascla-'.$id.'@'.$domain,'DTSTAMP:'.gmdate('Ymd\THis\Z'),'DTSTART:'.gmdate('Ymd\THis\Z',strtotime($meta['start'])),'DTEND:'.gmdate('Ymd\THis\Z',strtotime($meta['end'])),'SUMMARY:'.self::escape($title),'DESCRIPTION:'.self::escape($description),'LOCATION:'.self::escape($meta['location']??''),'END:VEVENT','END:VCALENDAR'];
        $folded=[];
        foreach ($lines as $line) {
            while (strlen($line)>75) { $chunk=mb_strcut($line,0,75,'UTF-8'); $folded[]=$chunk; $line=' '.substr($line,strlen($chunk)); }
            $folded[]=$line;
        }
        return implode("\r\n",$folded)."\r\n";
    }
    public static function google(string $title,array $meta,string $description=''): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query(['action'=>'TEMPLATE','text'=>$title,'dates'=>gmdate('Ymd\THis\Z',strtotime($meta['start'])).'/'.gmdate('Ymd\THis\Z',strtotime($meta['end'])),'details'=>$description,'location'=>$meta['location']??''], '', '&', PHP_QUERY_RFC3986);
    }
}
