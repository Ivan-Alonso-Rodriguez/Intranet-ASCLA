<?php
namespace ASCLA\Core\Integrations;
use ASCLA\Core\Services\{Settings,Access};
use ASCLA\Core\Rest\ApiException;

/** OpenAI Responses API transport. ASCLA builds and authorizes the context before it reaches this provider. */
final class OpenAIProvider implements AIProviderInterface
{
    public function mode(): string { return 'OpenAI · API real'; }

    public static function model(string $value): string
    {
        return AIProviderSupport::validatedModel($value,'/^[a-zA-Z0-9][a-zA-Z0-9._-]{1,119}$/D','Introduce un ID de modelo OpenAI válido, por ejemplo gpt-5.6-luna.');
    }

    private static function responseText(array $data): string
    {
        $text=is_string($data['output_text']??null)?$data['output_text']:'';
        if($text===''){
            foreach((array)($data['output']??[]) as $output){
                if(($output['type']??'')!=='message') { continue; }
                foreach((array)($output['content']??[]) as $piece){
                    if(($piece['type']??'')==='output_text' && is_string($piece['text']??null)) { $text.=$piece['text']; }
                }
            }
        }
        return trim($text);
    }

    public function generate(string $task,array $context): array
    {
        $key=Secrets::get('openai_key');
        Access::require($key!=='','Configura una OpenAI API Key.',400);
        $model=self::model(Settings::get()['openai_model']??'gpt-5.6-luna');
        $body=[
            'model'=>$model,
            'instructions'=>AIProviderSupport::instructions($task,$context),
            'input'=>wp_json_encode(['task'=>$task,'context'=>$context],JSON_UNESCAPED_UNICODE),
            'max_output_tokens'=>$task==='probe'?512:8192,
        ];
        $response=wp_remote_post('https://api.openai.com/v1/responses',AIProviderSupport::bearerPostArgs($key,$task==='probe'?25:55,$body));
        $data=AIProviderSupport::responseData($response,'OpenAI');
        if(($data['status']??'completed')==='failed') { throw new ApiException('OpenAI no pudo completar la respuesta.',502); }
        $result=AIProviderSupport::decodeObject(self::responseText($data),'OpenAI');
        $result['mode']=$this->mode();
        return $result;
    }

    public static function test(): array
    {
        return AIProviderSupport::testConnection(new self(),'openai_test','OpenAI',(string)Settings::get()['openai_model'],'Conexión correcta. OpenAI respondió con el modelo guardado.');
    }
}
