<?php
namespace ASCLA\Core\Integrations;
final class MockVideoProvider implements VideoProviderInterface
{
    public function metadata(string $videoId): array
    {
        return ['mode'=>'DEMO MODE','duration_seconds'=>210,'thumbnail_url'=>YouTubeVideoProvider::thumbnail($videoId),'source_title'=>'Conferencia ficticia de demostración'];
    }
    public function transcript(string $videoId): array
    {
        return ['mode'=>'DEMO MODE','text'=>"[00:00] Material ficticio de demostración: la gobernanza de inteligencia artificial requiere supervisión del directorio.\n[01:00] La organización debe definir responsabilidades y documentar los riesgos antes de adoptar nuevos sistemas.\n[02:15] La secretaría corporativa facilita el seguimiento de acuerdos y la evaluación periódica.\n[03:30] El aprendizaje entre pares ayuda a compartir criterios y preguntas para la junta."];
    }
}
