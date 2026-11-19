<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\{AssistantContext,Knowledge,Settings};
use ASCLA\Core\Repositories\Store;

final class AssistantOrganizationTest extends TestCase
{
    private int $user;
    private mixed $settings;
    private int $requests=0;
    private $http;

    protected function setUp(): void
    {
        $this->settings=get_option('ascla_settings',[]);
        $this->user=wp_insert_user(['user_login'=>'about_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>'ascla_member']);
        wp_set_current_user($this->user);
        update_option('ascla_settings',array_merge(Settings::get(),['ai_provider'=>'gemini']),false);
        $this->http=function() { $this->requests++;return new WP_Error('about_test_network','Institutional help must not need an external provider.'); };
        add_filter('pre_http_request',$this->http);
    }
    protected function tearDown(): void
    {
        remove_filter('pre_http_request',$this->http);
        foreach (['jobs','notifications','relations'] as $table) { Store::delete($table,['user_id'=>$this->user]); }
        wp_delete_user($this->user);update_option('ascla_settings',$this->settings,false);wp_set_current_user(0);
    }
    public static function descriptions(): array
    {
        return [
            ['es_ES','¿Qué es la intranet de ASCLA?'],
            ['es_ES','¿Qué es esta intranet de ASCLA?'],
            ['es_ES','¿Y qué es la intranet de ASCLA?'],
            ['es_ES','La intranet de ASCLA, ¿qué es?'],
            ['es_ES','Explícame la intranet de ASCLA'],
            ['es_ES','¿Para qué sirve esta plataforma a los asociados?'],
            ['es_ES','¿Qué es la intranet de ASCLA y para qué sirven sus videos y galerías?'],
            ['en_US','What is this ASCLA intranet?'],
            ['en_US',"ASCLA's intranet, what is it?"],
            ['en_US','Explain what ASCLA is'],
            ['en_US','What is the ASCLA intranet for members?'],
        ];
    }
    #[DataProvider('descriptions')]
    public function testInstitutionalHelpIsIndependentOfEarlierMediaAndExternalAi(string $locale,string $question): void
    {
        update_user_meta($this->user,'locale',$locale);
        foreach (['','¿Qué videos hay disponibles?','¿Qué galerías hay disponibles?'] as $previous) {
            $history=$previous===''?[]:[['question'=>$previous,'answer'=>'Contenido consultado anteriormente.','source_ids'=>[]]];
            $result=Knowledge::answer($question,$history);
            self::assertSame('ASCLA',$result['mode'],$question.' after '.$previous);
            self::assertTrue($result['grounding']['live_context_used']);
            self::assertEmpty($result['sources']);
            self::assertStringContainsString('Asociación de Secretarios Corporativos de América Latina',$result['answer']);
            self::assertStringContainsString($locale==='en_US'?'Knowledge Center':'Centro de Conocimiento',$result['answer']);
            self::assertStringContainsString($locale==='en_US'?'Directory':'Directorio',$result['answer']);
            self::assertStringNotContainsString($locale==='en_US'?'Administration':'Administración',$result['answer']);
            self::assertSame($result['answer'],Knowledge::storedAnswer($result)['answer']);
        }
        self::assertSame(0,$this->requests);
    }
    public function testSourceSpecificQuestionsStillRequireAuthorizedEvidence(): void
    {
        update_option('ascla_settings',array_merge(Settings::get(),['ai_provider'=>'mock']),false);
        foreach (['¿Qué explica el video sobre qué es ASCLA?','¿Qué es ASCLA según el video?','What does the video say about what is ASCLA?'] as $question) {
            $result=Knowledge::answer($question);
            self::assertTrue($result['grounding']['no_evidence']??false,$question);
            self::assertStringNotContainsString('Asociación de Secretarios Corporativos de América Latina',$result['answer']);
        }
    }
    public function testUnknownFactsAreNotReplacedByTheInstitutionalDescription(): void
    {
        $result=Knowledge::answer('¿Cuál es el patrimonio contable auditado de ASCLA para 2041?');
        self::assertTrue($result['grounding']['no_evidence']);self::assertEmpty($result['sources']);self::assertSame(0,$this->requests);
    }
    public function testExplicitLiveRequestsKeepTheirDataIntent(): void
    {
        foreach (['¿Qué es ASCLA y cuáles son mis próximos eventos?','¿Qué es ASCLA y qué eventos hay?','¿Qué hay en ASCLA esta semana?','What can I find in ASCLA this week?'] as $question) {
            self::assertFalse(AssistantContext::organizationQuestion($question));
            self::assertTrue(AssistantContext::build($question)['live']['intents']['events']);
        }
    }
    public function testDefinitionExplainsThePrivatePlatformAndAuthorizedAccess(): void
    {
        $answer=Knowledge::answer('¿Qué es la intranet de ASCLA?')['answer'];
        self::assertStringContainsString('plataforma privada',$answer);
        self::assertStringContainsString('cuenta autorizada',$answer);
        self::assertStringContainsString('permisos',$answer);
        self::assertSame(0,$this->requests);
    }
    public function testFollowUpsStayOnTheNewInstitutionalTopic(): void
    {
        foreach ([['es_ES','¿Qué es esta intranet de ASCLA?','¿Y qué contiene?'],['en_US','What is this ASCLA intranet?','And what does it contain?']] as [$locale,$question,$followUp]) {
            update_user_meta($this->user,'locale',$locale);
            $history=[['question'=>'¿Qué videos hay?','answer'=>'Un tema anterior.','source_ids'=>[]],['question'=>$question,'answer'=>'La descripción institucional.','source_ids'=>[]]];
            $result=Knowledge::answer($followUp,$history);
            self::assertSame('ASCLA',$result['mode']);
            self::assertTrue($result['grounding']['live_context_used']);
            self::assertStringContainsString($locale==='en_US'?'Knowledge Center':'Centro de Conocimiento',$result['answer']);
        }
        self::assertSame(0,$this->requests);
    }
}
