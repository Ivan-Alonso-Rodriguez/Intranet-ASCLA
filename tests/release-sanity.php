<?php
/**
 * Release sanity checks that deliberately avoid WordPress, Docker and PHPUnit.
 * Run with: php tests/release-sanity.php
 */
declare(strict_types=1);

namespace ASCLA\Core\Rest {
    final class ApiException extends \RuntimeException {}
}

namespace ASCLA\Core\Services {
    final class Access
    {
        public static function require(bool $condition,string $message='Error',int $code=400): void
        {
            if (!$condition) throw new \ASCLA\Core\Rest\ApiException($message,$code);
        }
    }
}

namespace {
    const PROJECT_ROOT = __DIR__.'/..';
    if (!defined('ASCLA_URL')) define('ASCLA_URL','https://example.test/wp-content/plugins/ascla-core/');
    function esc_html(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    function esc_url(string $value,array $protocols=[]): string
    {
        $value=trim($value);
        if ($value==='') return '';
        $scheme=(string)parse_url($value,PHP_URL_SCHEME);
        if ($protocols && !in_array(strtolower($scheme),$protocols,true)) return '';
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    function home_url(string $path=''): string { return 'https://example.test'.('/'.ltrim($path,'/')); }

    require PROJECT_ROOT.'/wp-content/plugins/ascla-core/src/Domain/Phone.php';
    require PROJECT_ROOT.'/wp-content/plugins/ascla-core/src/Domain/AttendanceSessions.php';
    require PROJECT_ROOT.'/wp-content/plugins/ascla-core/src/Integrations/EmailTemplate.php';
    require PROJECT_ROOT.'/wp-content/plugins/ascla-core/src/Integrations/AIProviderInterface.php';
    require PROJECT_ROOT.'/wp-content/plugins/ascla-core/src/Services/InterestImports.php';

    $checks=0;
    $assert=function(bool $condition,string $message) use (&$checks): void {
        $checks++;
        if (!$condition) throw new RuntimeException('FAIL: '.$message);
    };
    $throws=function(callable $fn,string $message) use ($assert): void {
        try{$fn();$assert(false,$message);}catch(\ASCLA\Core\Rest\ApiException){$assert(true,$message);}
    };

    // Point 2: canonical E.164-like storage and strict international input.
    $assert(\ASCLA\Core\Domain\Phone::normalize('+51 999 123 456')==='+51999123456','phone normalization');
    $assert(\ASCLA\Core\Domain\Phone::normalize('+1 (212) 555-0123')==='+12125550123','phone separators');
    $assert(\ASCLA\Core\Domain\Phone::normalize('')==='','phone can be cleared');
    foreach(['999123456','0051999123456','+0123456789','+1234567','+1234567890123456'] as $bad){
        $throws(fn()=>\ASCLA\Core\Domain\Phone::normalize($bad),'reject invalid phone '.$bad);
    }

    // Zoom interval union: overlapping reconnects count only once and threshold is applied.
    $start=strtotime('2026-09-01T12:00:00Z');
    $rows=[
        ['line'=>2,'join'=>'2026-09-01T11:50:00Z','leave'=>'2026-09-01T12:30:00Z','seconds'=>2400],
        ['line'=>3,'join'=>'2026-09-01T12:20:00Z','leave'=>'2026-09-01T12:45:00Z','seconds'=>1500],
    ];
    $attendance=\ASCLA\Core\Domain\AttendanceSessions::calculate($rows,$start,$start+3600,70,['timezone'=>'UTC','date_format'=>'ymd']);
    $assert($attendance['seconds']===2700,'overlapping Zoom intervals are merged');
    $assert($attendance['status']==='present','attendance threshold marks present');
    $assert(count($attendance['intervals'])===1,'merged interval count');

    // Point 3: institutional email HTML escapes content and rejects unsafe CTA schemes.
    $html=\ASCLA\Core\Integrations\EmailTemplate::render('Título <script>','Nombre <b>','Privado <img>','Abrir','javascript:alert(1)');
    $assert(str_contains($html,'<!doctype html>'),'email is HTML');
    $assert(str_contains($html,'ascla-logo.png'),'email includes logo');
    $assert(str_contains($html,'Comunidad profesional'),'email includes institutional footer');
    $assert(!str_contains($html,'<script>'),'email escapes title');
    $assert(!str_contains($html,'href="javascript:'),'email rejects javascript CTA');

    // Point 4: release metadata must stay synchronized.
    $read=fn(string $path): string => (string)file_get_contents(PROJECT_ROOT.'/'.$path);
    $plugin=$read('wp-content/plugins/ascla-core/ascla-core.php');
    $wpReadme=$read('wp-content/plugins/ascla-core/readme.txt');
    $installer=$read('wp-content/plugins/ascla-core/src/Database/Installer.php');
    $assert((bool)preg_match('/Version:\s*1\.10\.4/',$plugin),'plugin header version');
    $assert(str_contains($plugin,"define('ASCLA_VERSION', '1.10.4');"),'ASCLA_VERSION constant');
    $assert((bool)preg_match('/Stable tag:\s*1\.10\.4/',$wpReadme),'WordPress stable tag');
    $assert(str_contains($read('sonar-project.properties'),'sonar.projectVersion=1.10.4'),'Sonar version');
    $assert((bool)preg_match('/SCHEMA_VERSION\s*=\s*13/',$installer),'schema version 13');
    $assert(str_contains($read('README.md'),'Versión actual: 1.10.4 · esquema 13'),'README version/schema');
    $assert(str_contains($read('VERSION_HISTORY.md'),'**Versión actual:** `1.10.4`'),'history current version');

    // Point 1: both server and admin UI contain the hard three-interest guard.
    $imports=$read('wp-content/plugins/ascla-core/src/Services/InterestImports.php');
    $importsJs=$read('wp-content/plugins/ascla-core/assets/interest-imports.js');
    $assert(str_contains($imports,'private const MAX_FORM_INTERESTS=3;'),'server max-three constant');
    $assert(str_contains($imports,'count($topics)<=self::MAX_FORM_INTERESTS'),'server max-three review guard');
    $assert(str_contains($importsJs,'if(topics.length>3)'),'client max-three guard');

    // Point 6: Forms AI contract accepts only <=3 existing integer IDs and known confidence labels.
    $catalog=[['id'=>11,'name'=>'Gobierno corporativo'],['id'=>22,'name'=>'Inteligencia artificial'],['id'=>33,'name'=>'Sostenibilidad'],['id'=>44,'name'=>'Ciberseguridad']];
    $valid=\ASCLA\Core\Services\InterestImports::validateAIResult(['intereses'=>[11,22],'confianza'=>'media'],$catalog);
    $assert($valid===['intereses'=>[11,22],'confianza'=>'media'],'Forms AI valid contract');
    $throws(fn()=>\ASCLA\Core\Services\InterestImports::validateAIResult(['intereses'=>[11,22,33,44],'confianza'=>'alta'],$catalog),'Forms AI rejects more than three IDs');
    $throws(fn()=>\ASCLA\Core\Services\InterestImports::validateAIResult(['intereses'=>[999],'confianza'=>'alta'],$catalog),'Forms AI rejects unknown IDs');
    $throws(fn()=>\ASCLA\Core\Services\InterestImports::validateAIResult(['intereses'=>[11],'confianza'=>'inventada'],$catalog),'Forms AI rejects invalid confidence');
    $router=$read('wp-content/plugins/ascla-core/src/Rest/Router.php');
    $assert(str_contains($router,"/admin/interest-imports/ai-test"),'Forms AI diagnostic REST route');
    $deepseek=$read('wp-content/plugins/ascla-core/src/Integrations/DeepSeekProvider.php');
    $settings=$read('wp-content/plugins/ascla-core/src/Services/Settings.php');
    $assert(str_contains($deepseek,'https://api.deepseek.com/chat/completions') && str_contains($deepseek,"'response_format'=>['type'=>'json_object']"),'DeepSeek official API JSON transport');
    $assert(str_contains($settings,"'deepseek_model'=>'deepseek-flash'") && str_contains($settings,"'deepseek_key'"),'DeepSeek independent model and secret');
    $assert(str_contains($router,"'deepseek'=>\\ASCLA\\Core\\Integrations\\DeepSeekProvider::test()"),'DeepSeek connection probe route');
    $assert(str_contains($importsJs,"Probar IA para Forms"),'Forms AI diagnostic admin control');

    // New administrative/profile UI must not fall back to Spanish in English mode.
    $translations=json_decode($read('wp-content/plugins/ascla-core/languages/en.json'),true);
    $assert(is_array($translations) && !empty($translations),'English translation catalog is valid JSON');
    $missing=[];
    foreach(['wp-content/plugins/ascla-core/assets/interest-imports.js','wp-content/plugins/ascla-core/assets/admin-statistics.js','wp-content/plugins/ascla-core/assets/app.js'] as $asset){
        $source=$read($asset);
        if(preg_match_all('/\b(?:T|L|label)\(\s*([\'"])(.*?)\1\s*\)/s',$source,$matches)){
            foreach($matches[2] as $key){if(!array_key_exists($key,$translations))$missing[$key]=true;}
        }
    }
    $assert($missing===[],'new UI direct translation keys are complete');
    foreach(['Analizando','Revisión pendiente','Aplicando','Revisar conflicto','Asistencia parcial','Registrada','Participante','Detalle'] as $key){
        $assert(isset($translations[$key]) && $translations[$key]!==$key,'dynamic translation key '.$key);
    }
    $appJs=$read('wp-content/plugins/ascla-core/assets/app.js');
    $assert(str_contains($appJs,'Guard every same-tab link while Perfil or Configuración contains unsaved changes') && str_contains($appJs,'event.returnValue = "";'),'profile unsaved navigation and unload guard');
    $assert(str_contains($appJs,'Guardar cambios y salir') && str_contains($appJs,'pendingUnsavedSaveForm') && str_contains($appJs,'if (saveAndLeave && S.pendingUnsavedResolver) resolveUnsavedExit(true);'),'profile unsaved modal supports save and leave');
    $assert(str_contains($appJs,"T('Incluye +, código de país y número; por ejemplo +51 987 654 321')"),'phone help title is localized');
    $assert(!str_contains($appJs,'btn("Crear tema", "editor"'),'forums do not expose duplicate Create topic action');

    fwrite(STDOUT,"Release sanity: {$checks} checks passed.\n");
}
