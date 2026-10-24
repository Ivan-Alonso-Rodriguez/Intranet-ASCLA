<?php

namespace ASCLA\Core\Services;

final class ContentMeta

{

    public static function sanitize(string $type,array $input,int $id=0,bool $allowIncomplete=false): array
    {
        $data=$id?(array)get_post_meta($id,'_ascla',true):[];
        $previousVideo=$data['video_id']??'';
        $mod=current_user_can('ascla_moderate');
        $editorial=$mod||($type==='resource'&&Access::canPublish());
        self::texts($data,$input,$editorial);
        if ($type==='contact') { SupportCategories::validate($data,$input); }
        self::urls($data,$input);
        if (($data['video_id']??'')!==$previousVideo && ($data['transcript_status']??'')==='supadata') {
            foreach (['transcript','transcript_status','transcript_video_id','transcript_mode','transcript_language','transcript_checked_at','transcript_error'] as $field) { unset($data[$field]); }
        }
        if ($editorial && isset($input['chatham'])) { $data['chatham']=rest_sanitize_boolean($input['chatham']); }
        elseif (!isset($data['chatham'])) { $data['chatham']=Settings::get()['chatham_default']; }
        if ($type==='ally') { Allies::metadata($data,$input); }
        self::media($data,$input,$id);
        if ($type==='event') {
            self::event($data,$input);
            MicroLifecycle::sanitize($data,$input,$id,$allowIncomplete);
            EventReminders::sanitize($data,$input,$id);
            EventParticipation::validateChange($id,$id?(array)get_post_meta($id,'_ascla',true):[],$data,$input);
        }
        self::eventReference($data,$input);
        self::duration($data,$input);
        $data['thumbnail_url']=\ASCLA\Core\Integrations\YouTubeVideoProvider::thumbnail($data['video_id']??'');
        if ($type==='resource') { self::resource($data); }
        return $data;
    }

    private static function texts(array &$data,array $input,bool $editorial): void
    {
        $texts=['source','copyright','description','agenda','location','modality','resource_type','alliance_type','benefits','initiatives','summary'];
        $previousTranscript=(string)($data['transcript']??'');
        if ($editorial) { $texts=array_merge($texts,['transcript','identities']); }
        foreach ($texts as $key) {
            if ($key==='transcript'&&($data['transcript_status']??'')==='supadata'&&($input[$key]??null)===$previousTranscript) { continue; }
            if (isset($input[$key])) { $data[$key]=Access::text($input[$key],$key==='transcript'?VideoTranscripts::MAX_CHARACTERS:10000); }
        }
        if (!$editorial || !array_key_exists('transcript',$input)) { return; }
        if (($data['transcript']??'')===$previousTranscript && ($data['transcript_status']??'')==='supadata') { return; }
        if (trim((string)($data['transcript']??''))!=='') {
            $data['transcript_status']='manual';
            $data['transcript_mode']='Transcripción manual autorizada';
            $data['transcript_checked_at']=current_datetime()->format(DATE_ATOM);
            unset($data['transcript_error']);
            return;
        }
        unset($data['transcript_status'],$data['transcript_mode'],$data['transcript_checked_at'],$data['transcript_error']);
    }

    private static function urls(array &$data,array $input): void
    {
        foreach (['url','youtube_url'] as $key) {
            if (!isset($input[$key])) { continue; }
            $url=trim(Access::text($input[$key],2000));
            Access::require($url===''||(filter_var($url,FILTER_VALIDATE_URL)&&in_array(wp_parse_url($url,PHP_URL_SCHEME),['https','http'],true)),'Enlace no válido.',400);
            $data[$key]=esc_url_raw($url);
        }
        if (!empty($data['youtube_url'])) {
            $data['video_id']=\ASCLA\Core\Integrations\YouTubeVideoProvider::videoId($data['youtube_url']);
            Access::require($data['video_id']!=='','URL de YouTube no válida.',400);
        } elseif (array_key_exists('youtube_url',$input)) {
            unset($data['video_id'],$data['thumbnail_url'],$data['duration_seconds'],$data['video_metadata_mode'],$data['video_source_title']);
        }
    }

    private static function media(array &$data,array $input,int $id): void
    {
        if (!isset($input['media_ids'])) { return; }
        Access::require(is_array($input['media_ids'])&&count($input['media_ids'])<=12,'Máximo 12 archivos.',400);
        $data['media_ids']=FormRules::ids($input['media_ids']);
        foreach ($data['media_ids'] as $media) {
            $file=\ASCLA\Core\Repositories\Store::one('media',$media);
            Access::require($file && ((int)$file['user_id']===get_current_user_id()||($id&&(int)$file['post_id']===$id)),'Archivo no autorizado.');
        }
    }

    private static function event(array &$data,array $input): void
    {
        $eventMedia=array_values(array_filter(array_map('absint',(array)($data['media_ids']??[]))));
        Access::require(count($eventMedia)<=1,'El evento admite una sola imagen de portada.',400);
        foreach ($eventMedia as $mediaId) {
            $file=\ASCLA\Core\Repositories\Store::one('media',$mediaId);
            Access::require($file && in_array((string)$file['mime'],['image/jpeg','image/png','image/webp'],true),'La portada del evento debe ser una imagen JPG, PNG o WebP.',400);
        }
        $data['media_ids']=$eventMedia;
        foreach (['start','end'] as $key) {
            $value=$input[$key]??$data[$key]??'';
            if ($value==='') { unset($data[$key]); }
            else { $data[$key]=self::eventDate($value); }
        }
        if (!empty($data['start']) && !empty($data['end'])) {
            Access::require(strtotime($data['end'])>strtotime($data['start']),'El fin debe ser posterior al inicio.',400);
        }
        $data['offer_hours']=FormRules::integer('offer_hours',$input['offer_hours']??$data['offer_hours']??24);
        $data['capacity']=FormRules::integer('capacity',$input['capacity']??$data['capacity']??0);
        Access::require(in_array($data['modality']??'Virtual',['Virtual','Presencial','Híbrido'],true),'Modalidad no válida.',400);
    }

    public static function eventDate(mixed $value): string
    {
        Access::require(is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/',$value),'Fechas de evento deben incluir zona horaria.',400);
        try { $dt=new \DateTimeImmutable($value); } catch (\Exception) { throw new \ASCLA\Core\Rest\ApiException('Fecha inválida.',400); }
        $errors=\DateTimeImmutable::getLastErrors();
        Access::require($errors===false || ($errors['warning_count']===0 && $errors['error_count']===0),'Fecha inválida.',400);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }

    private static function eventReference(array &$data,array $input): void
    {
        if (!isset($input['event_id'])) { return; }
        $event=absint($input['event_id']);
        if ($event) { Access::require(Content::get($event)->post_type==='ascla_event','Evento no válido.',400); }
        $data['event_id']=$event;
    }

    private static function duration(array &$data,array $input): void
    {
        if (!isset($input['duration_seconds'])) { return; }
        $data['duration_seconds']=FormRules::integer('duration_seconds',$input['duration_seconds']);
        $data['video_metadata_mode']='Datos manuales';
    }

    private static function resource(array &$data): void
    {
        $data['resource_type']=$data['resource_type']??'Artículo';
        Access::require(in_array($data['resource_type'],['Artículo','Video','Podcast','Nota técnica','Infografía','Documento'],true),'Tipo de recurso no válido.',400);
        $data['copyright']=$data['copyright']??'© ASCLA – Asociación de Secretarios Corporativos de América Latina';
    }

}
