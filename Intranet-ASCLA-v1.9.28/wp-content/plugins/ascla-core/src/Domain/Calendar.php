<?php
namespace ASCLA\Core\Domain;
final class Calendar
{
    public static function google(string $title,array $meta,string $description=''): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query(['action'=>'TEMPLATE','text'=>$title,'dates'=>gmdate('Ymd\THis\Z',strtotime($meta['start'])).'/'.gmdate('Ymd\THis\Z',strtotime($meta['end'])),'details'=>$description,'location'=>$meta['location']??''], '', '&', PHP_QUERY_RFC3986);
    }
}
