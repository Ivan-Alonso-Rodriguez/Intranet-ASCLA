<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Integrations\MockSocialProvider;
use ASCLA\Core\Services\Account;

final class AccountCoverageTest extends TestCase
{
    private int $user;
    private string $currentPassword;

    protected function setUp(): void
    {
        $this->currentPassword='Current-E2E-Password-457!';
        $user=wp_insert_user([
            'user_login'=>'account_coverage_'.bin2hex(random_bytes(4)),
            'user_pass'=>$this->currentPassword,
            'user_email'=>'account-'.bin2hex(random_bytes(4)).'@example.invalid',
            'role'=>'ascla_member',
        ]);
        self::assertIsInt($user);
        $this->user=$user;
        wp_set_current_user($this->user);
        add_filter('send_auth_cookies','__return_false');
    }

    protected function tearDown(): void
    {
        remove_filter('send_auth_cookies','__return_false');
        wp_set_current_user(0);
        if ($this->user>0) wp_delete_user($this->user);
    }

    public function testPasswordChangeUpdatesTheStoredCredential(): void
    {
        $new='New-E2E-Password-984!';
        $result=Account::changePassword([
            'current_password'=>$this->currentPassword,
            'new_password'=>$new,
            'confirm_password'=>$new,
        ]);
        self::assertTrue($result['ok']);
        self::assertTrue(wp_check_password($new,get_userdata($this->user)->user_pass,$this->user));
    }

    public function testPasswordResetRequestSucceedsWithoutSendingExternalMail(): void
    {
        $mail=static fn()=>true;
        add_filter('pre_wp_mail',$mail,10,2);
        try {
            $result=Account::sendPasswordReset();
            self::assertTrue($result['ok']);
        } finally {
            remove_filter('pre_wp_mail',$mail,10);
        }
    }

    public function testMockSocialProviderReturnsDeterministicDemoPosts(): void
    {
        $posts=(new MockSocialProvider())->posts('demo');
        self::assertCount(2,$posts);
        self::assertSame('DEMO MODE',$posts[0]['mode']);
        self::assertStringContainsString('inteligencia artificial',$posts[0]['text']);
    }

    public static function invalidPasswords(): array
    {
        return [
            'missing'=>[[],'Complete los tres campos'],
            'too long'=>[['current_password'=>str_repeat('x',4097),'new_password'=>'Valid-next-Password!','confirm_password'=>'Valid-next-Password!'],'Contraseña no válida'],
            'wrong current'=>[['current_password'=>'wrong','new_password'=>'Valid-next-Password!','confirm_password'=>'Valid-next-Password!'],'actual no es correcta'],
            'mismatch'=>[['current_password'=>'Current-E2E-Password-457!','new_password'=>'Valid-next-Password!','confirm_password'=>'other'],'no coinciden'],
            'short'=>[['current_password'=>'Current-E2E-Password-457!','new_password'=>'short','confirm_password'=>'short'],'al menos 12'],
            'reuse'=>[['current_password'=>'Current-E2E-Password-457!','new_password'=>'Current-E2E-Password-457!','confirm_password'=>'Current-E2E-Password-457!'],'diferente de la actual'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPasswords')]
    public function testInvalidPasswordDoesNotChangeTheCredential(array $input,string $message): void
    {
        try {
            Account::changePassword($input);
            self::fail('Expected a validation error');
        } catch (\ASCLA\Core\Rest\ApiException $error) {
            self::assertSame(400,$error->getCode());
            self::assertStringContainsString($message,$error->getMessage());
        }
        self::assertTrue(wp_check_password($this->currentPassword,get_userdata($this->user)->user_pass,$this->user));
    }

    public function testResetDeliveryFailureIsReportedWithoutLeakingTransportDetails(): void
    {
        $mail=static fn()=>false;
        add_filter('pre_wp_mail',$mail,10,2);
        try {
            $this->expectException(\ASCLA\Core\Rest\ApiException::class);
            $this->expectExceptionCode(502);
            $this->expectExceptionMessage('No se pudo enviar el enlace');
            Account::sendPasswordReset();
        } finally {
            remove_filter('pre_wp_mail',$mail,10);
        }
    }
}
