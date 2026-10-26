<?php
namespace ASCLA\Core\Frontend;

/** Enforced policy for ASCLA-owned HTML and REST; native admin/plugins keep their own policy. */
final class SecurityHeaders
{
    private static ?string $nonce=null;
    private static bool $active=false;
    private static array $styleBuffers=[];

    public static function boot(): void
    {
        add_action('template_redirect',[self::class,'frontend'],0);
        add_action('login_init',[self::class,'nativeLogin']);
        add_filter('wp_script_attributes',[self::class,'attributes']);
        add_filter('wp_inline_script_attributes',[self::class,'attributes']);
        add_filter('wp_inline_style_attributes',[self::class,'attributes']);
        add_filter('script_loader_tag',[self::class,'scriptIntegrity'],20,3);
        add_filter('style_loader_tag',[self::class,'styleIntegrity'],20,3);
        // WordPress 6.6-6.8 prints some inline styles without the newer attribute filter.
        foreach (['wp_head','wp_footer','login_head','login_footer'] as $hook) {
            add_action($hook,[self::class,'captureStyles'],-9999);
            add_action($hook,[self::class,'finishStyles'],9999);
        }
        add_filter('rest_post_dispatch',[self::class,'rest'],20,3);
        add_filter('rest_pre_serve_request',[self::class,'cors'],20,4);
    }

    public static function nonce(): string
    {
        return self::$nonce??=base64_encode(random_bytes(24));
    }

    public static function policy(bool $html=true): string
    {
        if (!$html) { return "default-src 'none'; script-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'"; }
        return "default-src 'none'; script-src 'nonce-".self::nonce()."' 'strict-dynamic'; script-src-attr 'none'; "
            ."style-src 'self' 'nonce-".self::nonce()."'; style-src-attr 'none'; "
            ."img-src 'self' data: blob: https://i.ytimg.com https://img.youtube.com https://secure.gravatar.com; font-src 'self' data:; "
            ."connect-src 'self' https://challenges.cloudflare.com; media-src 'self' blob:; worker-src 'self' blob:; "
            ."frame-src https://www.youtube-nocookie.com https://www.youtube.com https://player.vimeo.com https://challenges.cloudflare.com; "
            ."object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; manifest-src 'self'";
    }

    public static function frontend(): void
    {
        if (App::page() || Login::isFrontendPage()) { self::send(); }
    }

    public static function nativeLogin(): void
    {
        // WordPress concatenation prints raw tags outside its nonce attribute filters.
        // Individual registered scripts retain the dependency order and receive our nonce/SRI.
        $GLOBALS['concatenate_scripts']=false;
        self::send();
    }

    public static function send(): void
    {
        if (headers_sent()) { return; }
        self::$active=true;
        header_remove('X-Powered-By');
        foreach (self::common() as $name=>$value) { header($name.': '.$value); }
        header('Content-Security-Policy: '.self::policy());
        if (is_ssl()) { header('Strict-Transport-Security: max-age=31536000'); }
        header_register_callback([self::class,'cookies']);
    }

    private static function common(): array
    {
        return ['X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'SAMEORIGIN',
            'Referrer-Policy'=>'same-origin','Permissions-Policy'=>'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Resource-Policy'=>'same-origin','Cross-Origin-Opener-Policy'=>'same-origin',
            'Cross-Origin-Embedder-Policy'=>'credentialless',
            'Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache'];
    }

    public static function attributes(array $attributes): array
    {
        if (self::$active) { $attributes['nonce']=self::nonce(); }
        return $attributes;
    }

    public static function captureStyles(): void
    {
        if (!self::$active) { return; }
        ob_start([self::class,'styleNonces']);
        self::$styleBuffers[]=ob_get_level();
    }

    public static function finishStyles(): void
    {
        if (!self::$styleBuffers) { return; }
        if (ob_get_level()===end(self::$styleBuffers)) { array_pop(self::$styleBuffers);ob_end_flush(); }
    }

    /** Only trusted head/footer style tags receive a nonce; raw script tags never do. */
    public static function styleNonces(string $html): string
    {
        $tags=new \WP_HTML_Tag_Processor($html);
        while ($tags->next_tag('STYLE')) { $tags->set_attribute('nonce',self::nonce()); }
        return $tags->get_updated_html();
    }

    public static function scriptIntegrity(string $tag,string $handle,string $src): string
    {
        unset($handle);
        return self::integrity($tag,$src,'SCRIPT');
    }

    public static function styleIntegrity(string $tag,string $handle,string $href): string
    {
        unset($handle);
        return self::integrity($tag,$href,'LINK');
    }

    private static function integrity(string $tag,string $url,string $element): string
    {
        if (!self::$active) { return $tag; }
        $file=self::localAssetFile($url);
        if ($file===null) { return $tag; }
        static $hashes=[];
        $hash=$hashes[$file]??=base64_encode(hash_file('sha384',$file,true));
        $html=new \WP_HTML_Tag_Processor($tag);
        if ($html->next_tag($element)) { $html->set_attribute('integrity','sha384-'.$hash);$html->set_attribute('crossorigin','anonymous'); }
        return $html->get_updated_html();
    }

    private static function localAssetFile(string $url): ?string
    {
        $site=wp_parse_url(site_url('/'));$asset=wp_parse_url($url);
        if (($asset['host']??'')!==($site['host']??'') || ($asset['port']??null)!==($site['port']??null)) { return null; }
        $base=(string)($site['path']??'/');$path=rawurldecode((string)($asset['path']??''));
        if (!str_starts_with($path,$base) || str_contains($path,"\0") || preg_match('~(?:^|/)\.\.(?:/|$)~',$path)) { return null; }
        $file=realpath(ABSPATH.substr($path,strlen($base)));
        $allowed=$file && str_starts_with(wp_normalize_path($file),wp_normalize_path(ABSPATH)) && in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['js','css'],true);
        return $allowed?$file:null;
    }

    /** Preserve WordPress cookie names, values, paths and expirations; add explicit browser restrictions. */
    public static function cookies(): void
    {
        $headers=headers_list();$cookies=[];
        foreach ($headers as $line) {
            if (!str_starts_with(strtolower($line),'set-cookie:')) { continue; }
            $cookies[]=self::restrictCookie($line);
        }
        if (!$cookies) { return; }
        header_remove('Set-Cookie');
        foreach ($cookies as $line) { header($line,false); }
    }

    private static function restrictCookie(string $line): string
    {
        if (!preg_match('/^Set-Cookie:\s*(?:wordpress_[^=]*|ascla_[^=]*|wp_lang)=/i',$line)) { return $line; }
        if (!preg_match('/;\s*SameSite=/i',$line)) { $line.='; SameSite=Lax'; }
        if (!preg_match('/;\s*HttpOnly(?:;|$)/i',$line)) { $line.='; HttpOnly'; }
        if (is_ssl() && !preg_match('/;\s*Secure(?:;|$)/i',$line)) { $line.='; Secure'; }
        return $line;
    }

    public static function rest($response,$server,\WP_REST_Request $request)
    {
        unset($server);
        if ($request->get_route()!=='/ascla/v1' && !str_starts_with($request->get_route(),'/ascla/v1/')) { return $response; }
        foreach (self::common() as $name=>$value) { $response->header($name,$value); }
        $response->header('Content-Security-Policy',self::policy(false));
        $response->header('X-Frame-Options','DENY');
        if (is_ssl()) { $response->header('Strict-Transport-Security','max-age=31536000'); }
        if (!headers_sent()) { header_remove('X-Powered-By');header_register_callback([self::class,'cookies']); }
        return $response;
    }

    public static function cors(bool $served,$response,\WP_REST_Request $request,$server): bool
    {
        unset($response,$server);
        if ($request->get_route()==='/ascla/v1' || str_starts_with($request->get_route(),'/ascla/v1/')) {
            // The ASCLA UI and API share an origin. Do not reflect arbitrary origins with credentials.
            header_remove('Access-Control-Allow-Origin');
            header_remove('Access-Control-Allow-Credentials');
        }
        return $served;
    }
}
