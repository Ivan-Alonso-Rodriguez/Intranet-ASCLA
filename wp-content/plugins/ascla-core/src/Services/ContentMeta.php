<?php

namespace ASCLA\Core\Services;

final class ContentMeta

{

    public static function sanitize(string $type,array $input,int $id=0): array

    {

        $old=$id?(array)get_post_meta($id,'_ascla',true):[];

        $data=$old; $mod=current_user_can('ascla_moderate');

        $texts=['source','copyright','description','agenda','location','modality','resource_type','alliance_type','benefits','initiatives','summary'];

        if ($mod) { $texts=array_merge($texts,['transcript','identities']); }

        foreach ($texts as $key) { if (isset($input[$key])) { $data[$key]=Access::text($input[$key],$key==='transcript'?100000:10000); } }

        foreach (['url','youtube_url'] as $key) {

            if (isset($input[$key])) {

                $url=trim(Access::text($input[$key],2000));

                Access::require($url===''||(filter_var($url,FILTER_VALIDATE_URL)&&in_array(wp_parse_url($url,PHP_URL_SCHEME),['https','http'],true)),'Enlace no válido.',400);

                $data[$key]=esc_url_raw($url);

            }

        }

        if (!empty($data['youtube_url'])) {

            $data['video_id']=\ASCLA\Core\Integrations\YouTubeVideoProvider::videoId($data['youtube_url']);

            Access::require($data['video_id']!=='','URL de YouTube no válida.',400);

        }

        if ($mod && isset($input['chatham'])) { $data['chatham']=rest_sanitize_boolean($input['chatham']); }

        elseif (!isset($data['chatham'])) { $data['chatham']=Settings::get()['chatham_default']; }

        if (isset($input['media_ids'])) {

            Access::require(is_array($input['media_ids'])&&count($input['media_ids'])<=12,'Máximo 12 archivos.',400);

            $data['media_ids']=array_values(array_unique(array_map('absint',$input['media_ids'])));

            foreach ($data['media_ids'] as $media) {

                $file=\ASCLA\Core\Repositories\Store::one('media',$media);

                Access::require($file && ((int)$file['user_id']===get_current_user_id()||($id&&(int)$file['post_id']===$id)),'Archivo no autorizado.');

            }

        }

        if ($type==='event') {

            foreach (['start','end'] as $key) {

                $value=$input[$key]??$data[$key]??'';

                Access::require(is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/',$value),'Fechas de evento deben incluir zona horaria.',400);

                try { $dt=new \DateTimeImmutable($value); } catch (\Exception $e) { throw new \ASCLA\Core\Rest\ApiException('Fecha inválida.',400); }

                $errors=\DateTimeImmutable::getLastErrors();

                Access::require($errors===false || ($errors['warning_count']===0 && $errors['error_count']===0),'Fecha inválida.',400);

                $data[$key]=$dt->setTimezone(new \DateTimeZone('UTC'))->format('c');

            }

            Access::require(strtotime($data['end'])>strtotime($data['start']),'El fin debe ser posterior al inicio.',400);

            $data['capacity']=max(0,min(100000,absint($input['capacity']??$data['capacity']??0)));

        }

        if (isset($input['event_id'])) { $event=absint($input['event_id']); if ($event) { Access::require(Content::get($event)->post_type==='ascla_event','Evento no válido.',400); } $data['event_id']=$event; }

        if (isset($input['duration_seconds'])) {
            Access::require(is_numeric($input['duration_seconds']) && (int)$input['duration_seconds']>=0 && (int)$input['duration_seconds']<=604800,'Duración no válida.',400);
            $data['duration_seconds']=(int)$input['duration_seconds'];
            $data['video_metadata_mode']='Datos manuales';
        }
        $data['thumbnail_url']=\ASCLA\Core\Integrations\YouTubeVideoProvider::thumbnail($data['video_id']??'');
        if ($type==='resource') {

            $data['resource_type']=$data['resource_type']??'Artículo';

            Access::require(in_array($data['resource_type'],['Artículo','Video','Podcast','Nota técnica','Infografía','Documento'],true),'Tipo de recurso no válido.',400);

            $data['copyright']=$data['copyright']??'© ASCLA – Asociación de Secretarios Corporativos de América Latina';

        }

        return $data;

    }

}
