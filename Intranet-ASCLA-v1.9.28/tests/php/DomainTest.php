<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Domain\{MatchScore,GroupPlanner,Calendar,Anonymizer,Transcript};
use ASCLA\Core\Integrations\{MockAIProvider,MockVideoProvider,YouTubeVideoProvider,Secrets,LinkedInProvider,XProvider};
final class DomainTest extends TestCase
{
    public function testMatchingIsSymmetricBoundedAndEmptyIsZero(): void
    {
        $a=['interests'=>[1,2],'areas'=>[4],'industries'=>[7],'goals'=>[9],'languages'=>[11]];
        $b=['interests'=>[1,2],'areas'=>[4],'industries'=>[7],'goals'=>[9],'languages'=>[11]];
        self::assertSame(100,MatchScore::calculate($a,$b)['score']);
        self::assertSame(0,MatchScore::calculate([],[])['score']);
        self::assertSame(0,MatchScore::calculate($a,$b,array_fill_keys(array_keys(MatchScore::WEIGHTS),0))['score']);
        $b['interests']=[1,3];
        self::assertSame(80,MatchScore::calculate($a,$b)['score']);
        self::assertSame(MatchScore::calculate($a,$b),MatchScore::calculate($b,$a));
        $a['interests']=[1,1,2]; self::assertSame(80,MatchScore::calculate($a,$b)['score']);
    }
    public function testGroupPlannerNeverDuplicatesOrCreatesInvalidGroups(): void
    {
        for ($count=0;$count<=80;$count++) {
            $profiles=[];for($i=1;$i<=$count;$i++)$profiles[]=['id'=>$i,'interests'=>[$i%4]];
            $plan=GroupPlanner::plan($profiles,[],3);$used=$plan['waiting'];
            foreach($plan['groups'] as $g){self::assertGreaterThanOrEqual(4,count($g));self::assertLessThanOrEqual(6,count($g));$used=array_merge($used,$g);}
            self::assertCount($count,array_unique($used));self::assertLessThan(4,count($plan['waiting']));
            self::assertSame($plan,GroupPlanner::plan($profiles,[],3));
        }
    }
    public function testGroupHistoryPenalizesRepeatedPairs(): void
    {
        $p=array_map(fn($id)=>['id'=>$id,'interests'=>[1]],range(1,12));$first=GroupPlanner::plan($p);$history=[];
        foreach($first['groups'] as $g)foreach($g as $a)foreach($g as $b)if($a<$b)$history[$a.':'.$b]=10;
        self::assertNotSame($first['groups'],GroupPlanner::plan($p,$history)['groups']);
    }
    public function testGoogleCalendarLinkConvertsTimezone(): void
    {
        $meta=['start'=>'2026-09-05T10:00:00-05:00','end'=>'2026-09-05T11:00:00-05:00','location'=>'Lima'];
        self::assertStringContainsString('dates=20260905T150000Z%2F20260905T160000Z',Calendar::google('Consejo',$meta));
    }
    public function testChathamRedactsKnownIdentityAndAffiliation(): void
    {
        $text="María Pérez: En Empresa Azul tomamos una decisión. maria@example.invalid https://example.invalid/perfil";
        $redacted=Anonymizer::redact($text,['María Pérez','Empresa Azul']);
        self::assertStringNotContainsString('María Pérez',$redacted);self::assertStringNotContainsString('Empresa Azul',$redacted);
        self::assertStringNotContainsString('example.invalid',$redacted);self::assertStringNotContainsString('[identidad reservada]',$redacted);self::assertStringContainsString('decisión',$redacted);
        self::assertSame('Participante: idea',Anonymizer::redact('Orador Uno: idea'));
    }
    public function testVideoParsingUsesStrictHostAndId(): void
    {
        foreach(['https://youtu.be/abcdefghijk','https://www.youtube.com/watch?v=abcdefghijk','https://youtube.com/embed/abcdefghijk','https://youtube.com/shorts/abcdefghijk'] as $url)self::assertSame('abcdefghijk',YouTubeVideoProvider::videoId($url));
        foreach(['https://youtube.com.evil.test/watch?v=abcdefghijk','javascript:alert(1)','https://youtube.com/watch?v=short','https://other.test/abcdefghijk'] as $url)self::assertSame('',YouTubeVideoProvider::videoId($url));
    }
    public function testMocksNeverInventStatisticsOrSources(): void
    {
        $ai=new MockAIProvider();$video=(new MockVideoProvider())->transcript('');$result=$ai->generate('multimedia',['transcript'=>$video['text'],'source_id'=>123]);
        self::assertSame('DEMO MODE',$result['mode']);self::assertSame([],$result['infographic']['statistics']);self::assertSame([],$result['norms']);
        self::assertSame([],$result['excerpts'],'Sin duración verificada no deben generarse cápsulas temporales.');
        $timed=$ai->generate('multimedia',['transcript'=>$video['text'],'source_id'=>123,'duration_seconds'=>210]);
        self::assertLessThanOrEqual(1,count($timed['excerpts']));foreach($timed['excerpts'] as $clip){self::assertGreaterThanOrEqual(60,$clip['end']-$clip['start']);self::assertLessThanOrEqual(180,$clip['end']-$clip['start']);self::assertLessThanOrEqual(210,$clip['end']);}
        self::assertSame([],$ai->generate('answer',[])['source_ids']);self::assertSame([7],$ai->generate('answer',['sources'=>[['id'=>7,'title'=>'Fuente','body'=>'Texto']]])['source_ids']);
        self::assertFalse($ai->generate('social',['text'=>'Compra nuestro curso de gobierno corporativo con descuento'])['relevant']);
        self::assertTrue($ai->generate('social',['text'=>'La gobernanza de la junta requiere seguimiento'])['relevant']);
        self::assertArrayHasKey('text',$ai->generate('intro',[]));
    }
    public function testSecretEncryptionDoesNotExposeCleartext(): void
    {
        Secrets::set('test_key','test-secret-not-real');$stored=get_option('ascla_secret_test_key');self::assertStringNotContainsString('test-secret',$stored);self::assertSame('test-secret-not-real',Secrets::get('test_key'));
        update_option('ascla_secret_test_key','invalid');self::assertSame('',Secrets::get('test_key'));Secrets::remove('test_key');self::assertSame('',Secrets::get('test_key'));
    }
    public function testLinkedInIsAnExplicitExternalBlock(): void { $this->expectException(RuntimeException::class);(new LinkedInProvider())->posts(''); }
    public function testXIsAnExplicitExternalBlock(): void { $this->expectException(RuntimeException::class);(new XProvider())->posts(''); }
    public function testCapsulePolicyRespectsRealVideoDuration(): void
    {
        $text='';
        for($i=0;$i<=40;$i++) $text.='['.gmdate('H:i:s',$i*60).'] Responsabilidades del directorio, riesgos y acuerdos de seguimiento.'."\n";
        self::assertSame([],Transcript::moments($text,[],120));
        $short=Transcript::moments($text,[],240);self::assertLessThanOrEqual(1,count($short));foreach($short as $clip)self::assertLessThanOrEqual(240,$clip['end']);
        $medium=Transcript::moments($text,[],900);self::assertLessThanOrEqual(2,count($medium));foreach($medium as $clip)self::assertLessThanOrEqual(900,$clip['end']);
        $long=Transcript::moments($text,[],2000);self::assertLessThanOrEqual(3,count($long));foreach($long as $clip)self::assertLessThanOrEqual(2000,$clip['end']);
    }
    public function testUnknownTimestampsProduceNoClips(): void { self::assertSame([],Transcript::moments('Texto sin tiempos')); }
}
