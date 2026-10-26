<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Frontend\{Login,LoginHooks};

final class LoginAssetsTest extends TestCase
{
    private mixed $styles,$query;

    protected function setUp(): void
    {
        $this->styles=$GLOBALS['wp_styles']??null;
        $this->query=$GLOBALS['wp_query']??null;
        $GLOBALS['wp_styles']=null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_styles']=$this->styles;
        $GLOBALS['wp_query']=$this->query;
    }

    private function frontend(): void
    {
        $GLOBALS['wp_query']=new WP_Query(['page_id'=>Login::pageId()]);
        self::assertTrue(Login::isFrontendPage());
    }

    public function testCommunityLoginOnlyLoadsItsLayoutAndReusableFormPrimitives(): void
    {
        $this->frontend();Login::frontAssets();$styles=wp_styles();
        self::assertTrue($styles->all_deps($styles->queue));
        foreach (['ascla-login','dashicons','buttons','forms'] as $handle) {
            self::assertContains($handle,$styles->to_do);
        }
        foreach (['login','wp-admin','wp-base-styles','wp-tooltip'] as $handle) {
            self::assertNotContains($handle,$styles->to_do,'The standalone community form must not load native admin presentation: '.$handle);
        }
    }

    public function testNativeLoginRetainsTheOriginalWordPressDependencyGraph(): void
    {
        $styles=wp_styles();$original=$styles->registered['login']->deps;
        LoginHooks::enqueueNativeAssets();
        self::assertSame($original,$styles->registered['login']->deps);
        self::assertTrue($styles->all_deps($styles->queue));
        self::assertContains('login',$styles->to_do);
        foreach ($original as $handle) self::assertContains($handle,$styles->to_do);
    }

    public function testOtherFrontendPagesDoNotEnqueueLoginAssets(): void
    {
        $GLOBALS['wp_query']=new WP_Query(['pagename'=>'ascla-unrelated-page-does-not-exist']);
        $styles=wp_styles();$before=$styles->queue;
        Login::frontAssets();self::assertSame($before,$styles->queue);
    }

    public function testCommunityAssetsPreserveStylesEnqueuedByAnotherIntegration(): void
    {
        $this->frontend();wp_enqueue_style('ascla-test-integration','/integration.css');
        Login::frontAssets();self::assertTrue(wp_style_is('ascla-test-integration','enqueued'));
        self::assertArrayHasKey('login',wp_styles()->registered);
    }
}
