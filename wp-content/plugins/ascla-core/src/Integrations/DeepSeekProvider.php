<?php
namespace ASCLA\Core\Integrations;
use ASCLA\Core\Services\{Settings,Access};
use ASCLA\Core\Rest\ApiException;

/** DeepSeek REST transport using the OpenAI-compatible Chat Completions API. */
final class DeepSeekProvider implements AIProviderInterface
{
    public function mode(): string { return 'DeepSeek · API real'; }

    public static function model(string $value): string
    {
        return AIProviderSupport::validatedModel($value,'/^deepseek-[a-zA-Z0-9][a-zA-Z0-9._-]{0,119}$/D','Introduce un ID de modelo DeepSeek válido, por ejemplo deepseek-flash.');
    }

    public function generate(string $task,array $context): array
    {
        $key=Secrets::get('deepseek_key');
        Access::require($key!=='','Configura una DeepSeek API Key.',400);
        $model=self::model(Settings::get()['deepseek_model']??'deepseek-flash');
        $body=[
            'model'=>$model,
            'messages'=>[
                ['role'=>'system','content'=>AIProviderSupport::instructions($task,$context)],
                ['role'=>'user','content'=>wp_json_encode(['task'=>$task,'context'=>$context],JSON_UNESCAPED_UNICODE)],
            ],
            'response_format'=>['type'=>'json_object'],
            'thinking'=>['type'=>'disabled'],
            'stream'=>false,
            'max_tokens'=>$task==='probe'?512:8192,
        ];
        $response=wp_remote_post('https://api.deepseek.com/chat/completions',AIProviderSupport::bearerPostArgs($key,$task==='probe'?25:55,$body));
        $data=AIProviderSupport::responseData($response,'DeepSeek');
        $choice=$data['choices'][0]??[];
        $finish=(string)($choice['finish_reason']??'stop');
        if($finish!=='stop') { throw new ApiException('DeepSeek no devolvió una respuesta completa. Revisa la consulta o prueba otro modelo.',502); }
        $text=is_string($choice['message']['content']??null)?$choice['message']['content']:'';
        $result=AIProviderSupport::decodeObject($text,'DeepSeek');
        $result['mode']=$this->mode();
        return $result;
    }

    public static function test(): array
    {
        return AIProviderSupport::testConnection(new self(),'deepseek_test','DeepSeek',(string)Settings::get()['deepseek_model'],'Conexión correcta. DeepSeek respondió con el modelo guardado.');
    }
}
