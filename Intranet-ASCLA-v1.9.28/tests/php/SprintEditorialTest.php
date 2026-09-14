<?php
use PHPUnit\Framework\TestCase;
use ASCLA\Core\Domain\{EntityRedactor,Grounding};
final class SprintEditorialTest extends TestCase
{
    public function testUnknownPeopleOrganizationsAndAffiliationsAreRedacted(): void {
        $input='El ponente Juan Pérez compartió su experiencia. La directora María explicó el acuerdo. Pedro Gómez de Empresa Boreal presentó el marco. El gerente de Compañía Aurora respondió. Contacto: juan@boreal.example https://example.org/juan';
        $entities=EntityRedactor::detectEntities($input);self::assertNotEmpty($entities);
        $out=EntityRedactor::redact($input);
        foreach(['Juan Pérez','María','Pedro Gómez','Empresa Boreal','Compañía Aurora','juan@','https://'] as $secret)self::assertStringNotContainsString($secret,$out);
        self::assertTrue(EntityRedactor::validateRedaction($out)['valid']);self::assertFalse(EntityRedactor::validateRedaction($input)['valid']);
    }
    public function testFinalOutputIsRedactedEvenIfProviderReintroducesIdentity(): void {
        $tree=EntityRedactor::tree(['summary'=>'La directora María aprobó la propuesta.','infographic'=>['key_points'=>['Pedro Gómez de Empresa Boreal intervino.']]]);
        self::assertStringNotContainsString('María',json_encode($tree));self::assertStringNotContainsString('Boreal',json_encode($tree));
    }
    public function testNaturalAnswerIsPreservedAndOnlyUnknownReferencesAreRemoved(): void {
        $body='El directorio puede revisar los riesgos. La evaluación tiene seguimiento.';
        $natural='La supervisión combina la revisión de riesgos con el seguimiento de la evaluación.';
        $result=Grounding::answer(['answer'=>$natural,'source_ids'=>[1,999,1,'malformed',-2]],[['id'=>1,'body'=>$body],['id'=>2,'body'=>'Otra fuente']]);
        self::assertSame($natural,$result['answer']);self::assertSame([1],array_column($result['sources'],'id'));
        self::assertSame(1,$result['grounding']['ignored_references']);
        $noCitations=Grounding::answer(['answer'=>$natural,'source_ids'=>[999]],[['id'=>1,'body'=>$body]]);
        self::assertSame($natural,$noCitations['answer']);self::assertSame([],$noCitations['sources']);
    }
    public function testMultimediaParaphrasesAreNotDiscardedBySentenceEquality(): void {
        $source='En 2024 el 35 % de los participantes completó la evaluación. Se mencionó la norma ISO 37000.';
        $natural='La evaluación alcanzó al 35 % del grupo en 2024 y se comentó ISO 37000.';
        $r=Grounding::multimedia(['summary'=>$natural,'technical_note'=>$natural,'norms'=>['ISO 37000'],'conclusions'=>['Es útil revisar los resultados de la evaluación.'],'infographic'=>['statistics'=>['35 % del grupo evaluado'],'timeline'=>[['date'=>'2024','text'=>'Se completó la evaluación.']]]],$source);
        self::assertSame($natural,$r['summary']);self::assertSame($natural,$r['technical_note']);self::assertSame(['ISO 37000'],$r['norms']);
        self::assertSame('source-references-v2',$r['grounding']['policy']);self::assertTrue($r['grounding']['review_required']);
    }
}
