<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{Demo,Settings,Matching,Media};
use ASCLA\Core\Frontend\App;
use ASCLA\Core\Admin\Panel;
use ASCLA\Core\Plugin;
use ASCLA\Core\Repositories\Store;
final class LifecycleTest extends TestCase
{
    public function testBootRegistersPluginScopedAssetsAndRoutes(): void
    {
        Plugin::boot();self::assertNotFalse(has_action('rest_api_init',[ASCLA\Core\Rest\Router::class,'routes']));self::assertNotFalse(has_action('ascla_jobs',[ASCLA\Core\Jobs\Queue::class,'run']));
        $admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);App::assets('intranet');self::assertTrue(wp_script_is('ascla-app','enqueued'));$data=wp_scripts()->get_data('ascla-app','data');self::assertStringContainsString('ascla',$data);self::assertStringContainsString('nonce',$data);self::assertStringNotContainsString('client_secret',$data);
        ob_start();Panel::render();$html=ob_get_clean();self::assertStringContainsString('ascla-root',$html);wp_set_current_user(0);
    }
    public function testSeedIsIdempotentAndDoesNotResetPasswords(): void
    {
        $admin=get_users(['role'=>'administrator','number'=>1])[0];wp_set_current_user($admin->ID);$user=get_user_by('login','demo.asociado');$before=$user->user_pass;$count=count(get_users(['meta_key'=>'_ascla_demo','meta_value'=>1]));$r=Demo::seed(wp_generate_password(30));self::assertSame(21,$r['users']);self::assertSame($before,get_user_by('login','demo.asociado')->user_pass);self::assertSame($count,count(get_users(['meta_key'=>'_ascla_demo','meta_value'=>1])));wp_set_current_user(0);
    }
    public function testRecommendationsAndIntroAreDeterministicAndNeverSend(): void
    {
        $user=get_user_by('login','demo.asociado');wp_set_current_user($user->ID);$r=Matching::recommendations();self::assertNotEmpty($r);$before=Store::count('messages','sender_id=%d',[$user->ID]);$intro=Matching::intro($r[0]['id']);self::assertFalse($intro['sent']);self::assertStringContainsString('Hola',$intro['text']);self::assertSame($before,Store::count('messages','sender_id=%d',[$user->ID]));self::assertSame($r,Matching::recommendations());wp_set_current_user(0);
    }
    public function testPrivateMediaCannotBeAttachedByAnotherUser(): void
    {
        $owner=get_user_by('login','demo.asociado');$other=get_user_by('login','demo.miembro.2');wp_set_current_user($owner->ID);$id=Store::insert('media',['user_id'=>$owner->ID,'post_id'=>0,'name'=>'test.png','mime'=>'image/png','bytes'=>'fixture','created_at'=>gmdate('Y-m-d H:i:s')]);
        try {
            Media::requireOwned($id,$owner->ID,true);self::assertStringContainsString('ascla_media',Media::url($id));wp_set_current_user($other->ID);
            try {Media::attach($id,999);self::fail('Another user attached private media');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(403,$e->getCode());}
            try {Media::upload([]);self::fail('Invalid upload accepted');}catch(ASCLA\Core\Rest\ApiException $e){self::assertSame(400,$e->getCode());}
        } finally {Store::delete('media',['id'=>$id]);wp_set_current_user(0);}
    }
}
