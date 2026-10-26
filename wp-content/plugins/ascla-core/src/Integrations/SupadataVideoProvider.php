<?php
namespace ASCLA\Core\Integrations;

use ASCLA\Core\Jobs\DeferredJob;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Services\{Settings,VideoTranscripts};

/** Server-only transcript transport. Never start a second billable request while a job is pending. */
final class SupadataVideoProvider implements VideoProviderInterface
{
    private const BASE='https://api.supadata.ai/v1/transcript';
    private const MAX_BYTES=16777216;

    /** Only an explicit retry clears a terminal failure; an active job retains its remote ID. */
    public static function retry(string $videoId): void
    {
        $key=trim(Secrets::get('supadata_key'));$account=hash_hmac('sha256',$key,wp_salt('auth'));
        $cache='ascla_sd_'.hash('sha256',$account.'|'.$videoId.'|'.Settings::get()['supadata_language']);
        $pending='ascla_sd_pending_'.hash('sha256',$account.'|'.$videoId);
        Store::lock($pending,static function()use($cache,$pending){
            $reference=get_transient($pending);
            if (is_array($reference)&&is_string($reference['cache']??null)) { $cache=$reference['cache']; }
            $state=get_transient($cache);
            if (!is_array($state)||empty($state['terminal'])) { return; }
            if (empty($state['job'])||!empty($state['finished'])) { delete_transient($cache);delete_transient($pending);return; }
            $state['until']=0;$state['terminal']=false;$state['failures']=0;set_transient($cache,$state,2*DAY_IN_SECONDS);
        });
    }

    public function metadata(string $videoId): array { return YouTubeVideoProvider::publicMetadata($videoId); }

    public function transcript(string $videoId): array
    {
        if (!YouTubeVideoProvider::thumbnail($videoId)) { throw new IntegrationException('ID de video no válido.'); }
        $key=trim(Secrets::get('supadata_key'));
        if ($key==='') { throw new IntegrationException('Configura la API Key de Supadata en Ajustes para consultar subtítulos públicos.'); }
        $settings=Settings::get();$lang=(string)$settings['supadata_language'];
        $mode=empty($settings['supadata_generate'])?'native':'auto';
        $account=hash_hmac('sha256',$key,wp_salt('auth'));
        $cache='ascla_sd_'.hash('sha256',$account.'|'.$videoId.'|'.$lang);
        $pending='ascla_sd_pending_'.hash('sha256',$account.'|'.$videoId);
        $request=compact('cache','pending','key','videoId','lang','mode','account');
        return Store::lock($pending,function()use($request){
            $request=$this->pendingRequest($request);
            $state=get_transient($request['cache']);$state=is_array($state)?$state:[];
            if (isset($state['text'])) { return $state; }
            if (($state['mode']??$request['mode'])!==$request['mode'] && empty($state['job'])) { $state=[]; }
            $this->guardState($request,$state);
            return $this->receive($request,$state,$this->request($request,$state));
        });
    }

    private function pendingRequest(array $request): array
    {
        // A language change must not create another paid request for an active video.
        $reference=get_transient($request['pending']);
        if (is_array($reference)&&is_string($reference['cache']??null)) {
            $original=get_transient($reference['cache']);
            if (is_array($original)&&!empty($original['job'])&&empty($original['finished'])) {
                $request['cache']=$reference['cache'];$request['lang']=$reference['language'];$request['mode']=$original['mode'];
            } else { delete_transient($request['pending']); }
        }
        return $request;
    }
    private function guardState(array $request,array $state): void
    {
        if (($state['until']??0)>time()) {
            if (!empty($state['terminal'])) { throw new IntegrationException($state['message']); }
            throw new DeferredJob($state['message'],max(5,$state['until']-time()));
        }
        if (!empty($state['job']) && time()-(int)$state['started']>DAY_IN_SECONDS) {
            $state['finished']=true;
            $this->problem($request['cache'],$state,$request['mode'],'Supadata sigue sin completar el trabajo después de 24 horas. Revisa el servicio antes de iniciar otra solicitud.',true,600);
        }
    }
    private function request(array $request,array $state): array|\WP_Error
    {
        $poll=!empty($state['job']);
        $url=$poll?self::BASE.'/'.rawurlencode($state['job']):self::BASE.'?'.http_build_query(['url'=>'https://www.youtube.com/watch?v='.$request['videoId'],'lang'=>$request['lang'],'text'=>'false','mode'=>$request['mode']]);
        $guard='ascla_sd_rate_'.$request['account'];$blocked=(int)get_transient($guard);
        if ($blocked>time()) { throw new DeferredJob('Supadata está limitando las solicitudes. ASCLA volverá a consultar automáticamente.',max(5,$blocked-time())); }
        Store::lock($guard,static function()use($guard){
            if ((int)get_transient($guard)>time()) { throw new DeferredJob('Esperando el siguiente turno de Supadata.',5); }
            set_transient($guard,time()+2,2);
        });
        return wp_remote_get($url,['headers'=>['x-api-key'=>$request['key'],'Accept'=>'application/json'],'timeout'=>$poll?30:115,'redirection'=>0,'limit_response_size'=>self::MAX_BYTES]);
    }
    private function receive(array $request,array $state,array|\WP_Error $response): array
    {
        $poll=!empty($state['job']);
        if (is_wp_error($response)) {
            // The initial timeout may have consumed credits; never resubmit it automatically.
            $this->problem($request['cache'],$state,$request['mode'],$poll?'No se pudo consultar el trabajo de Supadata. Se volverá a intentar.':'No se confirmó la respuesta de Supadata. Revisa el consumo antes de volver a solicitar la transcripción.',!$poll,$poll?30:600);
        }
        $code=(int)wp_remote_retrieve_response_code($response);$body=wp_remote_retrieve_body($response);$data=json_decode($body,true);
        $this->checkStatus($request,$state,$response,$code,$data);
        if (strlen($body)>=self::MAX_BYTES || !is_array($data)) {
            $this->problem($request['cache'],$state,$request['mode'],'Supadata devolvió una respuesta incompleta o no válida. No se guardó contenido parcial.',true,600);
        }
        if ($code===202) { return $this->beginJob($request,$state,$data); }
        if ($poll) { $this->checkJob($request,$state,$data); }
        return $this->saveTranscript($request,$state,$data);
    }
    private function checkStatus(array $request,array $state,array $response,int $code,mixed $data): void
    {
        if ($code===429) { $this->rateLimited($request,$state,$response,$data); }
        if ($code>=500) { $this->problem($request['cache'],$state,$request['mode'],'Supadata no está disponible temporalmente. Se volverá a consultar.',false,60); }
        if (!in_array($code,[200,202],true)) {
            $poll=!empty($state['job']);
            $message=self::failureMessage($code,$poll);
            if ($code===404&&$poll) { $state['finished']=true; }
            $this->problem($request['cache'],$state,$request['mode'],$message,true,600);
        }
    }
    private function rateLimited(array $request,array $state,array $response,mixed $data): void
    {
        $quota=is_array($data)&&str_contains(strtolower((string)($data['details']??'')),'usage limit');
        $delay=$quota?900:self::retryAfter(wp_remote_retrieve_header($response,'retry-after'));
        set_transient('ascla_sd_rate_'.$request['account'],time()+$delay,$delay);
        $this->problem($request['cache'],$state,$request['mode'],$quota?'La cuota de Supadata se agotó. Revisa el plan o los créditos en Supadata.':'Supadata alcanzó su límite de solicitudes. ASCLA volverá a consultar automáticamente.',$quota,$delay);
    }
    private static function failureMessage(int $code,bool $poll): string
    {
        return match($code){
            206=>'El video no tiene subtítulos disponibles. Puedes autorizar la generación en Ajustes o añadir una transcripción manual.',
            401=>'La API Key de Supadata no es válida. Revísala en Ajustes.',
            402=>'El plan de Supadata no permite esta solicitud. Revisa el plan y los créditos.',
            403=>'El video es privado o está restringido; Supadata no puede acceder a él.',
            404=>$poll?'El trabajo de Supadata ya no está disponible. No se iniciará otro automáticamente.':'El video no está disponible para Supadata.',
            default=>'Supadata rechazó la solicitud. Revisa que el video sea público, haya terminado y cumpla los límites del servicio.',
        };
    }
    private function beginJob(array $request,array $state,array $data): array
    {
        $job=$data['jobId']??'';
        if (!is_string($job)||!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$job)) { $this->problem($request['cache'],$state,$request['mode'],'Supadata no devolvió un identificador de trabajo válido.',true,600); }
        $state=['job'=>$job,'started'=>time(),'mode'=>$request['mode'],'failures'=>0];
        set_transient($request['pending'],['cache'=>$request['cache'],'language'=>$request['lang']],2*DAY_IN_SECONDS);
        return $this->waiting($request['cache'],$state);
    }
    private function checkJob(array $request,array $state,array $data): void
    {
        if (in_array($data['status']??'',['queued','active'],true)) { $this->waiting($request['cache'],$state); }
        if (($data['status']??'')==='failed') {
            $state['finished']=true;
            $this->problem($request['cache'],$state,$request['mode'],'Supadata no pudo completar la transcripción. Revisa el video y los límites del servicio antes de reintentar; una nueva solicitud puede consumir créditos.',true,600);
        }
        if (($data['status']??'')!=='completed') { $this->problem($request['cache'],$state,$request['mode'],'Supadata devolvió un estado de trabajo no válido.',true,600); }
    }
    private function saveTranscript(array $request,array $state,array $data): array
    {
        try { $text=self::fullText($data['content']??null); }
        catch (IntegrationException $e) { $this->problem($request['cache'],$state,$request['mode'],$e->getMessage(),true,600); }
        $result=['text'=>$text,'mode'=>'Supadata · '.($state['mode']??$request['mode']),'language'=>sanitize_text_field((string)($data['lang']??'')),'characters'=>mb_strlen($text)];
        set_transient($request['cache'],$result,7*DAY_IN_SECONDS);delete_transient($request['pending']);
        return $result;
    }

    private function waiting(string $cache,array $state): array
    {
        $state['failures']=0;$state['until']=time()+30;$state['message']='Supadata está preparando la transcripción completa. El trabajo continuará en segundo plano.';
        set_transient($cache,$state,2*DAY_IN_SECONDS);
        throw new DeferredJob($state['message'],30);
    }
    private function problem(string $cache,array $state,string $mode,string $message,bool $terminal,int $delay): array
    {
        $failures=(int)($state['failures']??0)+1;$terminal=$terminal||$failures>=8;
        $state=array_merge($state,['mode'=>$mode,'message'=>$message,'until'=>time()+$delay,'terminal'=>$terminal,'failures'=>$failures]);
        set_transient($cache,$state,2*DAY_IN_SECONDS);
        if ($terminal) { throw new IntegrationException($message); }
        throw new DeferredJob($message,$delay);
    }
    private static function retryAfter(mixed $value): int
    {
        if (is_numeric($value)) { $seconds=(int)$value; }
        else { $seconds=(strtotime((string)$value)?:time()+60)-time(); }
        return max(5,min(900,$seconds));
    }
    private static function stamp(float $milliseconds): string
    {
        $ms=(int)round($milliseconds);$seconds=intdiv($ms,1000);
        return sprintf('%02d:%02d:%02d,%03d',intdiv($seconds,3600),intdiv($seconds,60)%60,$seconds%60,$ms%1000);
    }
    private static function fullText(mixed $content): string
    {
        if (is_string($content)) { $text=trim($content); }
        elseif (is_array($content)&&array_is_list($content)) {
            $text=self::segmentText($content);
        } else { throw new IntegrationException('Supadata no devolvió una transcripción válida.'); }
        if ($text==='' || mb_strlen($text)>VideoTranscripts::MAX_CHARACTERS) { throw new IntegrationException('La transcripción está vacía o supera el límite de almacenamiento. No se guardó contenido parcial.'); }
        return $text;
    }
    private static function segmentText(array $content): string
    {
        $lines=[];$size=0;$speech=false;
        foreach ($content as $segment) {
            self::validateSegment($segment);
            $speech=$speech||trim($segment['text'])!=='';
            $line=self::stamp((float)$segment['offset']).' --> '.self::stamp((float)$segment['offset']+(float)$segment['duration'])."\n".trim($segment['text']);
            $size+=mb_strlen($line)+2;
            if ($size>VideoTranscripts::MAX_CHARACTERS) { throw new IntegrationException('La transcripción supera el límite de almacenamiento de ASCLA. No se guardó contenido parcial.'); }
            $lines[]=$line;
        }
        return $speech?implode("\n\n",$lines):'';
    }
    private static function validateSegment(mixed $segment): void
    {
        if (!is_array($segment)||!is_string($segment['text']??null)||!is_numeric($segment['offset']??null)||!is_numeric($segment['duration']??null)||$segment['offset']<0||$segment['duration']<0||$segment['offset']>604800000||$segment['duration']>604800000) { throw new IntegrationException('Supadata devolvió segmentos no válidos. No se guardó una transcripción parcial.'); }
    }
}
