<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Frontend\SecurityHeaders;

final class SecurityHeadersTest extends TestCase
{
    public function testHtmlPolicyRejectsInlineHandlersAndBroadSourcesButAllowsRequiredIntegrations(): void
    {
        $policy=SecurityHeaders::policy();
        self::assertStringContainsString("'nonce-".SecurityHeaders::nonce()."'",$policy);
        self::assertSame(24,strlen(base64_decode(SecurityHeaders::nonce(),true)));
        foreach (["default-src 'none'","object-src 'none'","script-src-attr 'none'","style-src-attr 'none'","form-action 'self'","frame-ancestors 'self'"] as $required) { self::assertStringContainsString($required,$policy); }
        foreach (["'unsafe-inline'","'unsafe-eval'",'https:;','http:;',' *'] as $unsafe) { self::assertStringNotContainsString($unsafe,$policy); }
        self::assertStringContainsString('https://challenges.cloudflare.com',$policy);
        self::assertStringContainsString('https://www.youtube-nocookie.com',$policy);
        preg_match('/(?:^|;)\s*script-src\s+([^;]+)/',$policy,$script);
        self::assertSame("'nonce-".SecurityHeaders::nonce()."' 'strict-dynamic'",$script[1]);
    }

    public function testLegacyStyleCompatibilityNeverAuthorizesRawScriptTags(): void
    {
        $html='<style id="legacy">.sample{color:red}</style><script id="untrusted">alert(1)</script>';
        $secured=SecurityHeaders::styleNonces($html);
        $tags=new WP_HTML_Tag_Processor($secured);self::assertTrue($tags->next_tag('STYLE'));
        self::assertSame(SecurityHeaders::nonce(),$tags->get_attribute('nonce'));
        self::assertTrue($tags->next_tag('SCRIPT'));self::assertNull($tags->get_attribute('nonce'));
        self::assertStringContainsString('alert(1)',$secured);
    }

    public function testRestErrorsReceiveRestrictiveHeadersWithoutChangingTheirStatusOrBody(): void
    {
        $body=['code'=>'denied','message'=>'Authentication required'];
        $response=new WP_REST_Response($body,401);
        SecurityHeaders::rest($response,rest_get_server(),new WP_REST_Request('GET','/ascla/v1/bootstrap'));
        self::assertSame(401,$response->get_status());self::assertSame($body,$response->get_data());
        $headers=$response->get_headers();
        self::assertSame('nosniff',$headers['X-Content-Type-Options']);self::assertSame('DENY',$headers['X-Frame-Options']);
        self::assertStringContainsString('no-store',$headers['Cache-Control']);
        self::assertStringContainsString("script-src 'none'",$headers['Content-Security-Policy']);
        self::assertStringNotContainsString('nonce-',$headers['Content-Security-Policy']);
    }

    public function testOtherRestNamespacesAreNotReconfiguredByThePlugin(): void
    {
        $response=new WP_REST_Response(['ok'=>true],200,['Example'=>'unchanged']);
        SecurityHeaders::rest($response,rest_get_server(),new WP_REST_Request('GET','/wp/v2/posts'));
        self::assertSame(['Example'=>'unchanged'],$response->get_headers());
    }
}
