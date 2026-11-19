<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Frontend\{Language,Translations};
use ASCLA\Core\Services\{Profiles,Settings};

final class TranslationsReviewTest extends TestCase
{
    private int $user;
    protected function setUp(): void
    {
        $this->user=wp_insert_user(['user_login'=>'labels_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(32),'role'=>'ascla_member','display_name'=>'Nombre que se conserva']);
        wp_set_current_user($this->user);
    }
    protected function tearDown(): void { wp_delete_user($this->user);wp_set_current_user(0); }
    public function testEnglishControlsAndErrorsAreTranslatedWithoutChangingTheSiteLocale(): void
    {
        $site=get_option('WPLANG');update_user_meta($this->user,'locale','en_US');
        self::assertSame('Account menu',Language::label('Menú de cuenta'));
        self::assertSame('My account',Language::label('Mi cuenta'));
        self::assertSame('Administrator',Language::label('Administrador'));
        self::assertSame('ASCLA Executive',Language::label('Ejecutivo ASCLA'));
        self::assertSame('ASCLA Moderator',Language::label('Moderador ASCLA'));
        self::assertSame('The current password is incorrect.',Translations::error('La contraseña actual no es correcta.'));
        self::assertSame('CSV row 23 does not match the header columns.',Translations::error('La fila 23 no coincide con las columnas de la cabecera.'));
        self::assertSame('Could not connect to OpenAI. Check the server connection.',Translations::error('No se pudo conectar con OpenAI. Revisa la conexión del servidor.'));
        self::assertSame($site,get_option('WPLANG'));
        self::assertSame('Nombre que se conserva',get_userdata($this->user)->display_name);
        self::assertSame('Texto escrito por el usuario: Hola, María.',Translations::error('Texto escrito por el usuario: Hola, María.'));
    }
    public function testSpanishStillUsesTheOriginalLabelsAndErrors(): void
    {
        update_user_meta($this->user,'locale','es_ES');
        self::assertSame('Menú de cuenta',Language::label('Menú de cuenta'));
        self::assertSame('La contraseña actual no es correcta.',Translations::error('La contraseña actual no es correcta.'));
        self::assertSame('La fila 23 no coincide con las columnas de la cabecera.',Translations::error('La fila 23 no coincide con las columnas de la cabecera.'));
    }
    public function testServerRenderedAccountMenuUsesAuthorizedWordPressLinksAndEscaping(): void
    {
        update_user_meta($this->user,'locale','en_US');
        $args=['page'=>'intranet','profile'=>Profiles::visible($this->user),'settings'=>Settings::get()];
        ob_start();include ASCLA_PATH.'templates/shell.php';$markup=ob_get_clean();
        self::assertStringContainsString('class="overflow-menu account-menu"',$markup);
        self::assertStringContainsString('aria-label="Account menu: ',$markup);
        self::assertStringContainsString('class="account-profile-link"',$markup);
        self::assertStringContainsString('My profile',$markup);
        self::assertStringContainsString('action=logout',$markup);
        self::assertStringContainsString('_wpnonce=',$markup);
    }
}
