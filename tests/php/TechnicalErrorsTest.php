<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\TechnicalErrors;
use ASCLA\Core\Repositories\{Store,Transaction,RepositoryException};

final class TechnicalErrorsTest extends TestCase
{
    private int $user;
    protected function setUp(): void
    {
        $this->user=wp_insert_user(['user_login'=>'errors_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>'administrator']);
        wp_set_current_user($this->user);
    }
    protected function tearDown(): void
    {
        Store::delete('audit',['actor_id'=>$this->user]);wp_delete_user($this->user);wp_set_current_user(0);
    }
    private function details(): array
    {
        return array_map(static fn($row)=>json_decode($row['detail'],true),Store::rows('audit','actor_id=%d AND action=%s',[$this->user,'technical_error'],'ORDER BY id ASC'));
    }
    public function testExternalFailuresAreStructuredWithoutRecordingSecretsOrProviderBodies(): void
    {
        $secret='PRIVATE-'.bin2hex(random_bytes(8));
        do_action('http_api_debug',new WP_Error($secret,$secret,['password'=>$secret]),'response','transport',['headers'=>['Authorization'=>'Bearer '.$secret],'body'=>$secret],'https://api.openai.com/v1/responses?key='.$secret);
        do_action('http_api_debug',['response'=>['code'=>503],'body'=>$secret,'headers'=>['set-cookie'=>$secret]],'response','transport',[],'https://generativelanguage.googleapis.com/v1/models?key='.$secret);
        do_action('wp_mail_failed',new WP_Error('smtp',$secret,['to'=>$secret,'message'=>$secret]));
        $rows=$this->details();self::assertCount(3,$rows);
        self::assertSame(['openai','gemini','smtp'],array_column($rows,'module'));
        self::assertSame(['error','error','error'],array_column($rows,'severity'));
        self::assertSame(503,$rows[1]['http_status']);
        foreach ($rows as $row) { self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T.*\+00:00$/',$row['utc']);self::assertNotEmpty($row['description']); }
        self::assertStringNotContainsString($secret,wp_json_encode($rows));
        do_action('http_api_debug',['response'=>['code'=>200]],'response','transport',[],'https://api.openai.com/v1/responses');
        do_action('http_api_debug',new WP_Error('timeout',$secret),'response','transport',[],'https://unknown.example.invalid/?token='.$secret);
        self::assertCount(3,$this->details());
    }
    public function testUnexpectedRestAndDatabaseFailuresAreLoggedWhileValidationErrorsAreNot(): void
    {
        $dispatch=new ReflectionMethod(ASCLA\Core\Rest\Router::class,'dispatch');
        foreach ([new TypeError('API_KEY SECRET PERSONAL DATA'),new RepositoryException('No se pudo guardar.'),new ASCLA\Core\Rest\ApiException('Proveedor no disponible.',502),new ASCLA\Core\Rest\ApiException('Entrada inválida.',400)] as $index=>$error) {
            $response=$dispatch->invoke(null,new WP_REST_Request('GET','/ascla/v1/profiles/me'),static fn()=>throw $error);
            self::assertInstanceOf(WP_Error::class,$response);
            self::assertSame([500,500,502,400][$index],$response->get_error_data()['status']);
        }
        $rows=$this->details();self::assertCount(3,$rows);
        self::assertSame('type_error',$rows[0]['exception']);self::assertSame('database',$rows[1]['module']);self::assertSame('critical',$rows[1]['severity']);
        self::assertStringNotContainsString('API_KEY',wp_json_encode($rows));
        self::assertSame('profiles',TechnicalErrors::routeModule('/ascla/v1/profiles/me'));
        self::assertSame('messaging',TechnicalErrors::routeModule('/ascla/v1/conversations/42'));
    }
    public function testDeliveryFailureIsPersistedAfterCommitWithoutUndoingTheSavedData(): void
    {
        $id=Store::atomic('technical-test:'.$this->user,static function() {
            $id=Store::insert('audit',['actor_id'=>get_current_user_id(),'action'=>'technical_test_commit','object_id'=>0,'detail'=>'saved','created_at'=>gmdate('Y-m-d H:i:s')]);
            Transaction::afterCommit(static fn()=>throw new RuntimeException('password=SECRET token=SECRET'));
            return $id;
        });
        self::assertNotNull(Store::one('audit',$id));
        $rows=$this->details();self::assertCount(1,$rows);self::assertSame('delivery',$rows[0]['module']);
        self::assertSame('delivery_failed',$rows[0]['code']);self::assertStringNotContainsString('SECRET',wp_json_encode($rows));
    }
    public function testDatabaseFailureFallsBackToSafeServerLogAndNeverThrowsASecondException(): void
    {
        global $wpdb;$oldLog=ini_get('error_log');$log=tempnam(sys_get_temp_dir(),'ascla-safe-error-');ini_set('error_log',$log);
        $fail=static function($sql) { return str_starts_with($sql,'INSERT INTO `'.Store::table('audit').'`') ? str_replace(Store::table('audit'),Store::table('audit').'_missing',$sql) : $sql; };
        add_filter('query',$fail);$previous='previous-safe-marker';$wpdb->last_error=$previous;
        try {
            TechnicalErrors::record('SMTP_PASSWORD SECRET','token=SECRET',new RuntimeException('token=SECRET'));
            self::assertSame($previous,$wpdb->last_error);
            $output=file_get_contents($log);self::assertStringContainsString('ASCLA technical_error',$output);
            self::assertStringContainsString('"module":"application"',$output);self::assertStringNotContainsString('SECRET',$output);
        } finally { remove_filter('query',$fail);$wpdb->last_error='';ini_set('error_log',$oldLog);unlink($log); }
    }
    public function testErrorRemainsInTheServerLogEvenWhenTheSurroundingTransactionRollsBack(): void
    {
        $oldLog=ini_get('error_log');$log=tempnam(sys_get_temp_dir(),'ascla-safe-rollback-');ini_set('error_log',$log);
        try {
            try {
                Store::atomic('technical-rollback:'.$this->user,static function() {
                    TechnicalErrors::record('google','http_failed',httpStatus:503);
                    throw new RuntimeException('SECRET in rolled back operation');
                });
                self::fail('Expected rollback');
            } catch (RuntimeException) { self::assertSame([],$this->details()); }
            $output=file_get_contents($log);self::assertStringContainsString('"module":"google"',$output);
            self::assertStringContainsString('"http_status":503',$output);self::assertStringNotContainsString('SECRET',$output);
        } finally { ini_set('error_log',$oldLog);unlink($log); }
    }
}
