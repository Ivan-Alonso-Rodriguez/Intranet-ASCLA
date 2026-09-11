<?php
namespace ASCLA\Core\Services;
use ASCLA\Core\Integrations\MockAIProvider;

/** A synthetic editorial example, explicitly separate from the illustrative video. */
final class DemoShowcase
{
    public static function transcript(): string
    {
        return "[00:00] Transcripción ficticia de demostración; no corresponde al audio del video ilustrativo. El ponente Juan Pérez, gerente de Compañía Boreal, presentó el caso sintético de gobernanza.\n[01:00] En el caso ficticio de 2024, el 35 % de los participantes completó la evaluación de riesgos. Se mencionó la norma ISO 37000 y el marco COSO ERM como referencias de la conversación.\n[02:00] En el caso ficticio de 2025 se revisaron los acuerdos del directorio. La conclusión fue documentar responsables y mantener una revisión periódica de los riesgos.\n[03:00] La secretaría corporativa facilita el seguimiento de las decisiones. El aprendizaje entre pares ayuda a formular preguntas para la junta.\n[04:00] Fin de la transcripción sintética.";
    }
    public static function seed(callable $make,array $users): void
    {
        $previous=get_current_user_id();wp_set_current_user($users[0]);
        try {
            $id=$make('sprint-multimedia','resource','Laboratorio multimedia · Demo ficticia','Ejemplo para revisar transcripción, anonimización, fuentes, nota técnica, infografía y cápsulas. El video externo es únicamente ilustrativo y no contiene esta transcripción.',[
                'resource_type'=>'Video','video_id'=>'M7lc1UVf-VE','youtube_url'=>'https://www.youtube.com/watch?v=M7lc1UVf-VE','thumbnail_url'=>'https://i.ytimg.com/vi/M7lc1UVf-VE/hqdefault.jpg','duration_seconds'=>240,'source'=>'Transcripción sintética ASCLA para pruebas','transcript'=>self::transcript(),'chatham'=>true,'demo_source_note'=>'Video ilustrativo de la documentación oficial de YouTube. La transcripción, estadísticas y cápsulas son datos ficticios de prueba; no corresponden a su audio.'
            ]);
            $saved=get_option('ascla_demo_showcase');
            if(!$saved||!get_post((int)($saved['resource_id']??0))){
                $result=Knowledge::multimedia($id,new MockAIProvider());
                foreach(array_merge([$result['resource_id'],$result['hub_id']],$result['capsule_ids']) as $postId){update_post_meta($postId,'_ascla_demo_key','showcase-derived-'.$postId);}
                update_option('ascla_demo_showcase',$result,false);
            }
            MicroEvents::create(array_map('intval',$users));
        } finally {wp_set_current_user($previous);}
    }
}
