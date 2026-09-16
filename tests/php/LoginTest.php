<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Frontend\Login;
use ASCLA\Core\Frontend\App;
use ASCLA\Core\Domain\Catalog;

final class LoginTest extends TestCase
{

    public function testDedicatedLoginPageIsSeparateFromIntranet(): void
    {
        self::assertStringContainsString('/login', Login::url());
        self::assertNotSame(Catalog::url('intranet'), Login::url());
        self::assertStringContainsString('/administracion', App::adminUrl());
    }

    public function testMemberReturnsOnlyToAnAsclaPage(): void
    {
        $member = get_user_by('login', 'demo.asociado');
        $home = Catalog::url('intranet');
        $target = Catalog::url('aliados', ['view' => 'all']);
        self::assertSame($target, Login::redirect(admin_url(), $target, $member));
        foreach (['', admin_url(), home_url(), 'https://example.invalid/aliados/', '//example.invalid/aliados/'] as $requested) {
            self::assertSame($home, Login::redirect(admin_url(), $requested, $member));
        }
        $pages = get_option('ascla_pages');
        $queryUrl = home_url('/?page_id=' . $pages['aliados']);
        self::assertSame($queryUrl, Login::redirect(admin_url(), $queryUrl, $member));
    }

    public function testAdministratorAndAuthenticationErrorsKeepNativeRedirect(): void
    {
        $admin = get_users(['role' => 'administrator', 'number' => 1])[0];
        self::assertSame(admin_url(), Login::redirect(admin_url(), '', $admin));
        self::assertSame(admin_url(), Login::redirect(admin_url(), '', new WP_Error('incorrect_password')));
    }
}
