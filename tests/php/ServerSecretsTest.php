<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Integrations\Secrets;
use ASCLA\Core\Services\Settings;

final class ServerSecretsTest extends TestCase
{
    private array $environment=[],$options=[];
    private int $user;
    protected function setUp(): void
    {
        foreach (array_merge(Secrets::SERVICE_KEYS,['external_secrets_only']) as $name) {
            $variable='ASCLA_'.strtoupper($name);$this->environment[$variable]=getenv($variable);putenv($variable);
        }
        putenv('ASCLA_EXTERNAL_SECRETS_ONLY=0');
        foreach (Secrets::SERVICE_KEYS as $name) { $key='ascla_secret_'.$name;$this->options[$key]=get_option($key,null);delete_option($key); }
        $this->options['ascla_settings']=get_option('ascla_settings',null);
        $this->user=wp_insert_user(['user_login'=>'server_secret_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>'administrator']);wp_set_current_user($this->user);
    }
    protected function tearDown(): void
    {
        foreach ($this->options as $key=>$value) { if ($value===null) { delete_option($key); } else { update_option($key,$value,false); } }
        foreach ($this->environment as $name=>$value) { putenv($value===false?$name:$name.'='.$value); }
        ASCLA\Core\Repositories\Store::delete('audit',['actor_id'=>$this->user]);wp_delete_user($this->user);wp_set_current_user(0);
    }
    private function api(array $body): WP_REST_Response
    {
        $request=new WP_REST_Request('POST','/ascla/v1/settings');$request->set_header('Content-Type','application/json');$request->set_body(wp_json_encode($body));return rest_do_request($request);
    }
    public function testEnvironmentOverridesEncryptedLegacyWithoutExposingEitherValue(): void
    {
        $legacy='legacy-'.bin2hex(random_bytes(12));$external='external-'.bin2hex(random_bytes(12));Secrets::set('openai_key',$legacy);
        putenv('ASCLA_OPENAI_KEY='.$external);
        self::assertSame($external,Secrets::get('openai_key'));self::assertSame('environment',Secrets::source('openai_key'));
        $status=Settings::status();self::assertTrue($status['has_openai_key']);self::assertTrue($status['secret_sources']['openai_key']['managed_externally']);self::assertTrue($status['secret_sources']['openai_key']['legacy_copy']);
        self::assertStringNotContainsString($legacy,wp_json_encode($status));self::assertStringNotContainsString($external,wp_json_encode($status));
        self::assertSame(409,$this->api(['openai_key'=>'attempted-replacement'])->get_status());self::assertSame(409,$this->api(['clear_openai_key'=>true])->get_status());self::assertSame($external,Secrets::get('openai_key'));
        putenv('ASCLA_OPENAI_KEY');self::assertSame($legacy,Secrets::get('openai_key'));
    }
    public function testFreshInstallDefaultsToExternalStorageAndExistingCiphertextRemainsCompatible(): void
    {
        putenv('ASCLA_EXTERNAL_SECRETS_ONLY');self::assertTrue(Secrets::externalOnly());
        self::assertSame(409,$this->api(['openai_key'=>'not-allowed-on-fresh-install'])->get_status());
        putenv('ASCLA_EXTERNAL_SECRETS_ONLY=0');Secrets::set('openai_key','migration-fixture');putenv('ASCLA_EXTERNAL_SECRETS_ONLY');
        self::assertFalse(Secrets::externalOnly());self::assertSame('migration-fixture',Secrets::get('openai_key'));
    }
    public function testExplicitEmptyEnvironmentValueDoesNotReuseTheOldKey(): void
    {
        Secrets::set('openai_key','old-fixture');putenv('ASCLA_OPENAI_KEY=');
        self::assertSame('',Secrets::get('openai_key'));self::assertFalse(Settings::status()['has_openai_key']);self::assertTrue(Secrets::hasLegacy('openai_key'));
    }
    public function testStrictModeRejectsNewServiceSecretsAndKeepsLegacyForAnExplicitRollback(): void
    {
        Secrets::set('openai_key','previous-fixture');$stored=get_option('ascla_secret_openai_key');putenv('ASCLA_EXTERNAL_SECRETS_ONLY=1');
        self::assertTrue(Secrets::externalOnly());self::assertSame('',Secrets::get('openai_key'));self::assertSame('external_required',Secrets::source('openai_key'));
        self::assertSame(409,$this->api(['openai_key'=>'new-fixture'])->get_status());self::assertSame($stored,get_option('ascla_secret_openai_key'));
        try { Secrets::set('ai_key','must-not-be-stored');self::fail('Strict mode accepted a database secret'); }
        catch (RuntimeException) { self::assertFalse(get_option('ascla_secret_ai_key')); }
        $name='google_calendar_'.$this->user;Secrets::set($name,wp_json_encode(['access_token'=>'user-token-fixture']));
        self::assertSame('user-token-fixture',json_decode(Secrets::get($name),true)['access_token']);Secrets::remove($name);
        putenv('ASCLA_EXTERNAL_SECRETS_ONLY=0');self::assertSame('previous-fixture',Secrets::get('openai_key'));
    }
    public function testSecretRequestValidationPrecedesAnyWriteAndUnknownServerFlagFailsClosed(): void
    {
        putenv('ASCLA_OPENAI_KEY=server-fixture');
        self::assertSame(409,$this->api(['ai_key'=>'must-not-leak','openai_key'=>'not-allowed'])->get_status());self::assertFalse(get_option('ascla_secret_ai_key'));
        putenv('ASCLA_EXTERNAL_SECRETS_ONLY=invalid-value');self::assertTrue(Secrets::externalOnly());
        self::assertSame(409,$this->api(['smtp_password'=>'not-allowed'])->get_status());self::assertFalse(get_option('ascla_secret_smtp_password'));
    }
    public function testLaterValidationFailureRestoresCredentialsAndConfiguration(): void
    {
        Secrets::set('openai_key','original-fixture');$stored=get_option('ascla_secret_openai_key');$settings=get_option('ascla_settings');
        self::assertSame(400,$this->api(['openai_key'=>'replacement-fixture','turnstile_enabled'=>true,'turnstile_site_key'=>''])->get_status());
        self::assertSame($stored,get_option('ascla_secret_openai_key'));self::assertSame('original-fixture',Secrets::get('openai_key'));self::assertSame($settings,get_option('ascla_settings'));
    }
    public function testRejectedConfigurationWriteRollsBackTheNewCredential(): void
    {
        Secrets::set('openai_key','original-fixture');$stored=get_option('ascla_secret_openai_key');$settings=get_option('ascla_settings');
        $reject=static fn($value,$old)=>$old;add_filter('pre_update_option_ascla_settings',$reject,10,2);
        try { self::assertSame(500,$this->api(['openai_key'=>'replacement-fixture','copyright'=>'Must not persist'])->get_status()); }
        finally { remove_filter('pre_update_option_ascla_settings',$reject,10); }
        self::assertSame($stored,get_option('ascla_secret_openai_key'));self::assertSame($settings,get_option('ascla_settings'));
    }
}
