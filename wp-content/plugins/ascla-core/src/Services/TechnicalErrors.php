<?php
namespace ASCLA\Core\Services;

use ASCLA\Core\Repositories\{RepositoryException,Store};

/** RNF-027: a closed diagnostic vocabulary excludes arbitrary messages and credentials. */
final class TechnicalErrors
{
    private const MODULES=['application','database','content','profiles','messaging','administration','jobs','delivery','smtp','gemini','openai','deepseek','google','youtube','supadata','turnstile'];
    private const DESCRIPTIONS=[
        'request_failed'=>'ASCLA request failed.',
        'job_failed'=>'Background task failed.',
        'delivery_failed'=>'Post-commit delivery failed.',
        'http_failed'=>'External HTTP request failed.',
        'mail_failed'=>'WordPress mail delivery failed.',
        'fatal_error'=>'Fatal PHP error in ASCLA.',
    ];
    private const PROVIDERS=['generativelanguage.googleapis.com'=>'gemini','api.openai.com'=>'openai','api.deepseek.com'=>'deepseek','api.supadata.ai'=>'supadata','www.googleapis.com'=>'google','oauth2.googleapis.com'=>'google','accounts.google.com'=>'google','www.youtube.com'=>'youtube','challenges.cloudflare.com'=>'turnstile'];

    public static function boot(): void
    {
        add_action('wp_mail_failed',[self::class,'mailFailure']);
        add_action('http_api_debug',[self::class,'httpResponse'],10,5);
        register_shutdown_function([self::class,'shutdown']);
    }

    public static function routeModule(string $route): string
    {
        $segment=explode('/',trim($route,'/'))[2]??'';
        return match ($segment) {
            'profiles'=>'profiles',
            'conversations','messages','relations'=>'messaging',
            'content','items','comments','events'=>'content',
            'admin','settings'=>'administration',
            'jobs'=>'jobs',
            default=>'application',
        };
    }

    public static function record(string $module,string $code,?\Throwable $error=null,int $id=0,int $httpStatus=0,string $severity='error'): void
    {
        $detail=[
            'utc'=>gmdate('c'),
            'module'=>in_array($module,self::MODULES,true)?$module:'application',
            'severity'=>in_array($severity,['error','critical'],true)?$severity:'error',
            'code'=>isset(self::DESCRIPTIONS[$code])?$code:'request_failed',
            'description'=>self::DESCRIPTIONS[$code]??self::DESCRIPTIONS['request_failed'],
        ];
        if ($error) {
            $detail['exception']=match(true) {
                $error instanceof RepositoryException=>'repository_error',
                $error instanceof \TypeError=>'type_error',
                $error instanceof \Error=>'php_error',
                $error instanceof \ASCLA\Core\Rest\ApiException=>'api_error',
                default=>'exception',
            };
            $detail+=self::origin($error->getFile(),$error->getLine());
        }
        if ($httpStatus>=100 && $httpStatus<=599) { $detail['http_status']=$httpStatus; }
        self::persist($detail,max(0,$id));
    }

    private static function origin(string $file,int $line): array
    {
        $root=str_replace('\\','/',ASCLA_PATH);$file=str_replace('\\','/',$file);
        if (!str_starts_with($file,$root)) { return []; }
        $relative=substr($file,strlen($root));
        if (!preg_match('/^(?:[A-Za-z]+\/)*[A-Za-z]+\.php$/',$relative)) { return []; }
        return ['component'=>$relative,'line'=>max(0,$line)];
    }

    private static function persist(array $detail,int $id): void
    {
        global $wpdb;
        $json=wp_json_encode($detail,JSON_UNESCAPED_SLASHES);
        // This copy also survives a surrounding rollback or an unavailable audit database.
        error_log('ASCLA technical_error '.$json);
        $previous=$wpdb->suppress_errors(true);$lastError=$wpdb->last_error;
        try {
            // Avoid the throwing repository path: logging must not replace the original failure.
            $wpdb->insert(Store::table('audit'),['actor_id'=>get_current_user_id(),'action'=>'technical_error','object_id'=>$id,'detail'=>$json,'created_at'=>gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable) { /* The safe server log above already contains the diagnostic. */ }
        finally { $wpdb->suppress_errors($previous);$wpdb->last_error=$lastError; }
    }

    public static function httpResponse(mixed $response,string $context,string $transport,array $args,string $url): void
    {
        unset($transport,$args);
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
        if ($context!=='response' || !isset(self::PROVIDERS[$host])) { return; }
        $status=(int)wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $status>=400) { self::record(self::PROVIDERS[$host],'http_failed',httpStatus:$status); }
    }

    public static function mailFailure(): void { self::record('smtp','mail_failed'); }

    public static function shutdown(): void
    {
        $error=error_get_last();
        if (!$error || !in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)) { return; }
        $origin=self::origin($error['file'],(int)$error['line']);
        if (!$origin) { return; }
        self::persist(['utc'=>gmdate('c'),'module'=>'application','severity'=>'critical','code'=>'fatal_error','description'=>self::DESCRIPTIONS['fatal_error']]+$origin,0);
    }
}
