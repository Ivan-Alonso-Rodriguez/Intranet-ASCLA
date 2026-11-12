<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Access,AccountRecovery,Bootstrap,Profiles};
use ASCLA\Core\Frontend\Language;
use ASCLA\Core\Repositories\Store;

final class RecoveryRolePdfTest extends TestCase
{
    private array $users=[],$query=[],$post=[];
    private mixed $page;
    protected function setUp(): void
    {
        $this->query=$_GET;$this->post=$_POST;$this->page=$GLOBALS['pagenow']??null;
        $_GET=$_POST=[];$GLOBALS['pagenow']='wp-login.php';
        foreach (['administrator','ascla_executive','ascla_moderator','ascla_member'] as $role) {
            $this->users[$role]=wp_insert_user(['user_login'=>'identity_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>$role]);
        }
    }
    protected function tearDown(): void
    {
        foreach ($this->users as $id) { Store::delete('audit',['actor_id'=>$id]);wp_delete_user($id); }
        $_GET=$this->query;$_POST=$this->post;$GLOBALS['pagenow']=$this->page;wp_set_current_user(0);
    }
    public function testRecoveryConfirmationStaysOnRecoveryAndUsesGenericLocalizedText(): void
    {
        $url=AccountRecovery::confirmationUrl();parse_str(wp_parse_url($url,PHP_URL_QUERY),$query);
        self::assertSame('lostpassword',$query['action']);self::assertSame('sent',$query['ascla_recovery']);
        self::assertArrayNotHasKey('checkemail',$query,'WordPress overrides the action when checkemail is present.');
        self::assertSame(wp_parse_url(network_site_url(),PHP_URL_HOST),wp_parse_url($url,PHP_URL_HOST));
        self::assertSame($url,apply_filters('lostpassword_redirect','https://example.invalid/'));
        wp_set_current_user($this->users['administrator']);
        foreach (['es_ES'=>'Si existe una cuenta asociada','en_US'=>'If an account matches'] as $locale=>$copy) {
            update_user_meta(get_current_user_id(),'locale',$locale);
            // Separate browser requests reload the text domain; emulate that boundary in this shared PHPUnit process.
            unload_textdomain('ascla-core',true);\ASCLA\Core\Frontend\Translations::load($locale);
            $_GET['wp_lang']=$locale;self::assertStringStartsWith($copy,AccountRecovery::confirmationMessage());
            self::assertStringNotContainsString('example.invalid',AccountRecovery::confirmationMessage());
        }
    }
    public function testExecutiveAndAdministratorHaveDifferentLabelsWithTheSameManagementPermissions(): void
    {
        foreach (['administrator'=>'Administrador','ascla_executive'=>'Ejecutivo ASCLA','ascla_moderator'=>'Moderador ASCLA','ascla_member'=>'Asociado ASCLA'] as $role=>$label) {
            wp_set_current_user($this->users[$role]);
            self::assertSame($label,Access::roleLabel());self::assertSame($label,Profiles::visible(get_current_user_id())['role_label']);
        }
        wp_set_current_user($this->users['ascla_executive']);$boot=Bootstrap::get();
        self::assertSame('Ejecutivo ASCLA',$boot['me']['role_label']);self::assertTrue($boot['admin']);
        self::assertTrue(current_user_can('ascla_manage'));self::assertFalse(current_user_can('ascla_configure'));
        self::assertFalse(current_user_can('manage_options'));
        wp_set_current_user($this->users['administrator']);self::assertSame('Administrador',Bootstrap::get()['me']['role_label']);
        $user=get_userdata(get_current_user_id());$user->add_role('ascla_executive');
        self::assertSame('Administrador',Access::roleLabel());
    }
    public function testOwnRoleLabelDoesNotBecomeAFieldOfAnotherMembersPublicProfile(): void
    {
        wp_set_current_user($this->users['ascla_member']);
        self::assertArrayNotHasKey('role_label',Profiles::visible($this->users['ascla_moderator']));
    }
}
