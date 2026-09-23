<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Services\{DemoUser,Profiles};
use ASCLA\Core\Frontend\{App,Icons};
final class NavigationDemoTest extends TestCase
{
    private array $users=[];
    protected function setUp():void {wp_set_current_user(get_users(['role'=>'administrator','number'=>1])[0]->ID);}
    protected function tearDown():void {foreach($this->users as $id)wp_delete_user($id);wp_set_current_user(0);}
    public function testDemoIdentityIsIdempotentAndPreservesManualProfileAndPassword():void
    {
        $first=DemoUser::sample(wp_generate_password(24));$id=$first['id'];$before=Profiles::raw($id);$hash=get_userdata($id)->user_pass;
        $count=count(get_users(['meta_key'=>'_ascla_demo','meta_value'=>1]));
        $again=DemoUser::sample(wp_generate_password(24));self::assertSame($id,$again['id']);self::assertSame($before,Profiles::raw($id));self::assertSame($hash,get_userdata($id)->user_pass);self::assertSame($count,count(get_users(['meta_key'=>'_ascla_demo','meta_value'=>1])));
        self::assertSame(DemoUser::EMAIL,get_userdata($id)->user_email);self::assertContains('ascla_member',get_userdata($id)->roles);self::assertCount(2,$first['peer_ids']);
        try{Profiles::save(['company'=>'Cambio manual de prueba','networking'=>false],$id);DemoUser::sample(wp_generate_password(24));$saved=Profiles::raw($id);self::assertSame('Cambio manual de prueba',$saved['company']);self::assertFalse($saved['networking']);}
        finally{Profiles::save(['company'=>$before['company'],'networking'=>$before['networking']],$id);}
    }
    public function testDemoUpsertDoesNotDuplicateEmailOrTakeOverOtherAccounts():void
    {
        $method=new ReflectionMethod(DemoUser::class,'upsert');$suffix=bin2hex(random_bytes(5));$login='navdemo_'.$suffix;$email=$login.'@example.invalid';$password=wp_generate_password(24);
        $r=$method->invoke(null,$login,$email,'Prueba','Demo',['company'=>'Demo'],$password);$this->users[]=$r['id'];self::assertTrue($r['created']);
        $same=$method->invoke(null,$login.'other',$email,'Prueba','Demo',['company'=>'Demo'],$password);self::assertSame($r['id'],$same['id']);self::assertFalse($same['created']);
        try{$method->invoke(null,$login,'different@example.invalid','Prueba','Demo',[],$password);self::fail('Should reject a conflicting identity');}catch(ASCLA\Core\Rest\ApiException $error){self::assertSame(409,$error->getCode());}
        $user=get_userdata($r['id']);$user->set_role('administrator');
        try{$method->invoke(null,$login,$email,'Prueba','Demo',[],$password);self::fail('Should preserve the existing role');}catch(ASCLA\Core\Rest\ApiException $error){self::assertSame(409,$error->getCode());}
        self::assertContains('administrator',get_userdata($r['id'])->roles);
    }
    public function testServerShellContainsNativeLinksBeforeJavascriptAndEscapesTheName():void
    {
        $id=wp_insert_user(['user_login'=>'navshell_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(24),'role'=>'ascla_member','display_name'=>'<script>invalid</script>']);$this->users[]=$id;wp_set_current_user($id);
        ob_start();App::shell();$html=ob_get_clean();self::assertStringContainsString('ascla-sidebar',$html);self::assertStringContainsString('ascla-header',$html);self::assertStringContainsString('ascla-logo.png',$html);self::assertStringContainsString('/directorio/',$html);self::assertStringNotContainsString('<script>invalid</script>',$html);self::assertSame(12,substr_count($html,'class="nav-link '));self::assertStringContainsString('<svg',Icons::html('missing'));
    }
}
