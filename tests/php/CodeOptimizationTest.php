<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Frontend\{Language,Translations};

final class CodeOptimizationTest extends TestCase
{
    public function testEveryRetainedTranslationMatchesThePreviousReleaseInWordPress(): void
    {
        $snapshot='/tmp/code-optimization1110-catalogs.json';
        if (!is_file($snapshot)) { self::markTestSkipped('Local before/after catalog snapshot required.'); }
        $baseline=json_decode(file_get_contents($snapshot),true);
        $removed=json_decode(file_get_contents('/tmp/code-optimization1110-removed.json'),true);
        $original=get_current_user_id();$page=$GLOBALS['pagenow']??null;
        $id=wp_insert_user(['user_login'=>'catalog_'.bin2hex(random_bytes(6)),'user_pass'=>wp_generate_password(30),'role'=>'administrator']);
        try {
            $GLOBALS['pagenow']='intranet.php';wp_set_current_user($id);
            foreach (['es_ES','en_US'] as $locale) {
                update_user_meta($id,'locale',$locale);Translations::load($locale);
                self::assertTrue(is_textdomain_loaded('ascla-core'));
                foreach ($baseline[$locale] as $key=>$expected) {
                    if (!array_key_exists($key,$removed)) { self::assertSame($expected,Translations::text($key),$locale.': '.$key); }
                }
                $labels=Language::labels();
                foreach (['Nuevo evento','Nuevo recurso','Nueva publicación','Guardar tema','Nombres: este campo es obligatorio.'] as $key) {
                    self::assertSame($baseline[$locale][$key],$labels[$key]);
                }
            }
        } finally {
            wp_set_current_user($original);wp_delete_user($id);
            if ($page===null) { unset($GLOBALS['pagenow']); } else { $GLOBALS['pagenow']=$page; }
            Translations::load(Language::current());
        }
    }
}
