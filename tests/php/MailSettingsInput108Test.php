<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\Settings;
use ASCLA\Core\Integrations\Secrets;
use ASCLA\Core\Repositories\Store;
use ASCLA\Core\Rest\ApiException;

final class MailSettingsInput108Test extends TestCase
{
    private array $options=[],$environment=[];
    private int $user;
    private string $password;
    protected function setUp(): void
    {
        foreach(Secrets::SERVICE_KEYS as $key){$name='ASCLA_'.strtoupper($key);$this->environment[$name]=getenv($name);putenv($name);$option='ascla_secret_'.$key;$this->options[$option]=get_option($option,null);delete_option($option);}
        $this->options['ascla_settings']=get_option('ascla_settings',null);
        $this->user=wp_insert_user(['user_login'=>'mailinput108_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(30),'role'=>'administrator']);wp_set_current_user($this->user);
        update_option('ascla_settings',array_merge(Settings::get(),['mail_mode'=>'wordpress','turnstile_enabled'=>false]),false);
        $this->password='temporary-smtp-'.bin2hex(random_bytes(10));Secrets::set('smtp_password',$this->password);Secrets::set('openai_key','temporary-service-key');
    }
    protected function tearDown(): void
    {
        foreach($this->options as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
        foreach($this->environment as $name=>$value)putenv($value===false?$name:$name.'='.$value);
        Store::delete('audit',['actor_id'=>$this->user]);wp_delete_user($this->user);wp_set_current_user(0);
    }
    private function api(array $input): WP_REST_Response
    {
        $r=new WP_REST_Request('POST','/ascla/v1/settings');$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($input,JSON_PRESERVE_ZERO_FRACTION));return rest_get_server()->dispatch($r);
    }
    private function snapshot(): string
    {
        $values=[get_option('ascla_settings'),Store::count('audit','actor_id=%d',[$this->user])];foreach(Secrets::SERVICE_KEYS as $key)$values[]=get_option('ascla_secret_'.$key,null);
        return hash('sha256',wp_json_encode($values));
    }
    private function rejected(array $input): void
    {
        $before=$this->snapshot();self::assertSame(400,$this->api($input)->get_status());self::assertSame($before,$this->snapshot());
        try{Settings::save($input);self::fail('Invalid settings must not be persisted.');}catch(ApiException $e){self::assertSame(400,$e->getCode());}
        self::assertSame($before,$this->snapshot());
    }
    public static function invalidText(): array
    {
        $cases=[];foreach(['smtp_host','smtp_user','smtp_from','smtp_name'] as $key){foreach([true,false,42,null,[]] as $value)$cases[]=[$key,$value];}return $cases;
    }
    #[DataProvider('invalidText')]
    public function testSmtpTextTypesAreRejectedBeforeSettingsOrCredentialsChange(string $key,mixed $value): void
    {
        $this->rejected([$key=>$value,'smtp_password'=>'replacement-must-not-be-saved']);
    }
    public static function controls(): array
    {
        return [['smtp_host',"smtp.example.invalid\r\n"],['smtp_host',"smtp.\0example.invalid"],['smtp_user',"\nuser@example.invalid"],['smtp_name',"Sender\0Team"],['smtp_name',"Sender\tTeam"],['smtp_from',"sender@example.invalid\n"]];
    }
    #[DataProvider('controls')]
    public function testControlCharactersCannotDisappearDuringSanitization(string $key,string $value): void
    {
        $this->rejected([$key=>$value]);
    }
    public static function invalidPorts(): array{return [[587.0],[true],[null],[[]],['587x'],['587.0']];}
    #[DataProvider('invalidPorts')]
    public function testPortRequiresAnAllowedInteger(mixed $value): void{$this->rejected(['smtp_port'=>$value]);}
    public static function falseFlags(): array{return [[false],['false'],[0],['0']];}
    #[DataProvider('falseFlags')]
    public function testFalseClearFlagsPreserveStoredCredentialsInRestAndService(mixed $value): void
    {
        $input=['clear_smtp_password'=>$value,'clear_openai_key'=>$value];Settings::save($input);
        self::assertSame($this->password,Secrets::get('smtp_password'));self::assertSame('temporary-service-key',Secrets::get('openai_key'));
        self::assertSame(200,$this->api($input)->get_status());self::assertSame($this->password,Secrets::get('smtp_password'));self::assertSame('temporary-service-key',Secrets::get('openai_key'));
    }
    public static function invalidClearFlags(): array{return [['yes'],[2],[[]],[null]];}
    #[DataProvider('invalidClearFlags')]
    public function testInvalidClearFlagsCannotRemoveACredential(mixed $value): void{$this->rejected(['clear_smtp_password'=>$value]);}
    public function testNullSecretCannotBeSilentlyIgnored(): void{$this->rejected(['smtp_password'=>null]);}
    public function testZeroPasswordIsStoredAndAnEmptyPasswordPreservesIt(): void
    {
        foreach(['0','  password with spaces  '] as $password){
            $status=Settings::save(['smtp_password'=>$password]);self::assertSame($password,Secrets::get('smtp_password'));self::assertTrue($status['has_smtp_password']);
            self::assertSame(200,$this->api(['smtp_password'=>''])->get_status());self::assertSame($password,Secrets::get('smtp_password'));
            self::assertStringNotContainsString($password==='0'?'"smtp_password":"0"':$password,wp_json_encode($status));
        }
    }
    public function testCanonicalSmtpConfigurationRemainsSupportedAndExplicitClearWorks(): void
    {
        foreach([[465,'ssl'],['587','tls'],[2525,'tls']] as [$port,$security]){
            $r=$this->api(['mail_mode'=>'smtp','smtp_host'=>'smtp.example.invalid','smtp_user'=>'sender@example.invalid','smtp_from'=>'sender@example.invalid','smtp_name'=>'ASCLA · Formación','smtp_port'=>$port,'smtp_security'=>$security,'smtp_password'=>'']);
            self::assertSame(200,$r->get_status());self::assertSame((int)$port,Settings::get()['smtp_port']);self::assertSame($this->password,Secrets::get('smtp_password'));
        }
        Settings::save(['mail_mode'=>'wordpress','clear_smtp_password'=>'true']);self::assertSame('',Secrets::get('smtp_password'));
    }
    public function testSingleLineLengthIsCheckedBeforeTrimming(): void{$this->rejected(['smtp_name'=>str_repeat(' ',255)]);}
    public function testValidationErrorUsesTheAdministratorsLanguageWithoutChangingSettings(): void
    {
        update_user_meta($this->user,'locale','en_US');$before=$this->snapshot();
        $r=$this->api(['smtp_host'=>"smtp.example.invalid\r\n"]);
        self::assertSame(400,$r->get_status());self::assertSame('Email settings do not allow line breaks or control characters.',$r->get_data()['message']);self::assertSame($before,$this->snapshot());
    }
}
