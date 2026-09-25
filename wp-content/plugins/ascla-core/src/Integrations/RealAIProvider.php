<?php
namespace ASCLA\Core\Integrations;
use ASCLA\Core\Services\{Settings,Access};
use ASCLA\Core\Rest\ApiException;

/** Gemini REST transport; the shared encrypted secret store remains unchanged. */
final class RealAIProvider implements AIProviderInterface
{
    public const SYSTEM_PROMPT='Eres el Asistente ASCLA, un chatbot interno de una comunidad profesional. Conversa con naturalidad, claridad y brevedad. Para cualquier dato sobre ASCLA, prioriza siempre el contexto en vivo de la intranet y las fuentes internas entregadas; nunca inventes eventos, personas, publicaciones, fechas, inscripciones, notificaciones ni datos de la comunidad. El historial sirve para mantener el hilo de la conversación, pero puede estar desactualizado: el contexto en vivo y las fuentes actuales tienen prioridad. Puedes saludar, explicar qué puedes hacer y guiar al usuario dentro de la intranet sin exigir una fuente. Si una pregunta factual no puede verificarse con la información interna disponible, dilo claramente y sugiere qué sección de ASCLA revisar. No reveles información que el usuario no tendría permiso de ver.';
    public function mode(): string { return 'Google Gemini · API real'; }
    public static function model(string $value): string
    {
        $value=preg_replace('~^models/~','',trim($value));
        Access::require((bool)preg_match('/^gemini-[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}$/D',$value),'Introduce un ID de modelo Gemini válido, por ejemplo gemini-2.5-flash.',400);
        return $value;
    }
    public function generate(string $task,array $context): array
    {
        $key=Secrets::get('ai_key');
        Access::require($key!=='' && !str_starts_with($key,'sk-'),'Configura una Gemini API Key; las claves de OpenAI no son compatibles.',400);
        $model=self::model(Settings::get()['ai_model']);
        $response=wp_remote_post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent',[
            'timeout'=>$task==='probe'?25:55,'redirection'=>0,'limit_response_size'=>1048576,
            'headers'=>['x-goog-api-key'=>$key,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode(['systemInstruction'=>['parts'=>[['text'=>AIProviderSupport::instructions($task,$context)]]],
                'contents'=>[['role'=>'user','parts'=>[['text'=>wp_json_encode(['task'=>$task,'context'=>$context],JSON_UNESCAPED_UNICODE)]]]],
                'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>$task==='answer'?0.35:0.2,'maxOutputTokens'=>$task==='probe'?1024:8192]])
        ]);
        if(is_wp_error($response)) { throw new ApiException('No se pudo conectar con Google Gemini. Revisa la conexión del servidor.',502); }
        $code=wp_remote_retrieve_response_code($response);
        if($code!==200) {
            $detail=match($code){400=>'Revisa el modelo y la configuración de Gemini.',401,403=>'Revisa la API Key y los permisos de Gemini.',404=>'El modelo no está disponible para esta API Key.',429=>'La cuota o el límite de solicitudes de Gemini se agotó.',default=>'Gemini no pudo completar la solicitud. Inténtalo nuevamente.'};
            throw new ApiException($detail.' (HTTP '.$code.')',502);
        }
        $data=json_decode(wp_remote_retrieve_body($response),true);$candidate=$data['candidates'][0]??[];
        if(($candidate['finishReason']??'STOP')!=='STOP') { throw new ApiException('Gemini no devolvió una respuesta completa. Revisa la consulta o prueba otro modelo.',502); }
        $text='';foreach($candidate['content']['parts']??[] as $piece) { if(empty($piece['thought']) && is_string($piece['text']??null)) { $text.=$piece['text']; } }
        $result=AIProviderSupport::decodeObject($text,'Gemini');
        $result['mode']=$this->mode();return $result;
    }
    public static function test(): array
    {
        Access::require(current_user_can('ascla_manage'));Access::limit('gemini_test',3,60);
        $r=(new self())->generate('probe',[]);
        Access::require(($r['ok']??false)===true,'Gemini respondió, pero no completó la prueba de conexión.',502);
        return ['ok'=>true,'provider'=>'Google Gemini','model'=>Settings::get()['ai_model'],'message'=>'Conexión correcta. Gemini respondió con el modelo guardado.'];
    }
}
