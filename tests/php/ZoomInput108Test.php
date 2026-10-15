<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Domain\{ZoomCsv,AttendanceSessions};
use ASCLA\Core\Rest\ApiException;

final class ZoomInput108Test extends TestCase
{
    public static function malformedColumns(): array
    {
        return [
            ["Name,Email,Duration\nPerson,one@example.invalid\n"],
            ["Name,Email,Duration\nPerson,one@example.invalid,20,extra\n"],
            ["Name,Email,Duration\nPerson,one@example.invalid,20\nOther,two@example.invalid\n"],
            ["Email,User Email,Duration\none@example.invalid,two@example.invalid,20\n"],
            ["Email,Duration,Duration (Minutes)\none@example.invalid,20,30\n"],
            ["Email,Join Time,Hora de ingreso\none@example.invalid,a,b\n"],
            ["Email,Leave Time,Hora de salida\none@example.invalid,a,b\n"],
            ["Email,Name,User Name\none@example.invalid,A,B\n"],
            ["Email,".implode(',',array_fill(0,100,'Extra'))."\none@example.invalid,".implode(',',array_fill(0,100,'x'))."\n"],
        ];
    }
    #[DataProvider('malformedColumns')]
    public function testMalformedOrAmbiguousColumnsAreRejectedBeforeAttribution(string $csv): void
    {
        try{ZoomCsv::parse($csv);self::fail('An ambiguous CSV must not be attributed to a member.');}
        catch(ApiException $e){self::assertSame(400,$e->getCode());}
    }
    public static function invalidDurations(): array
    {
        return [[str_repeat('9',400)],['99999999999999999999'],['10080.001'],['-1'],['=10+20'],['1e2'],['NaN']];
    }
    #[DataProvider('invalidDurations')]
    public function testInvalidDurationCannotBecomeZeroOrValidAttendance(string $value): void
    {
        $row=ZoomCsv::parse("Email,Duration\none@example.invalid,$value\n")[0];
        self::assertSame('Duración no válida',$row['error']);
    }
    public function testRealExportFormatsRemainSupported(): void
    {
        $csv="Meeting;Summary\n\n\xEF\xBB\xBFNOMBRE;CORREO ELECTRÓNICO;DURACIÓN (MINUTOS)\n\"Persona; Uno\";ONE@example.invalid;12,5\n";
        $row=ZoomCsv::parse($csv)[0];self::assertSame('one@example.invalid',$row['email']);self::assertSame('Persona; Uno',$row['name']);self::assertSame(13,$row['minutes']);self::assertSame(750,$row['seconds']);self::assertSame('',$row['error']);
        $row=ZoomCsv::parse("Name\tEmail\tDuration\tCustom\r\n\"Persona, \"\"Uno\"\"\"\tone@example.invalid\t10080\tExtra\r\n")[0];
        self::assertSame('Persona, "Uno"',$row['name']);self::assertSame(604800,$row['seconds']);self::assertSame('',$row['error']);
        $row=ZoomCsv::parse("Email,Duration\none@example.invalid,\n")[0];self::assertNull($row['seconds']);self::assertNull($row['minutes']);
    }
    public static function impossibleTimes(): array
    {
        return [
            ['2026-02-30T12:00:00Z','2026-02-30T13:00:00Z'],
            ['2026-02-29T12:00Z','2026-02-29T13:00Z'],
            ['2026-04-31T12:00:00Z','2026-04-31T13:00:00Z'],
            ['2026-03-01T24:00:00Z','2026-03-02T01:00:00Z'],
            ['2026-03-02T12:60:00Z','2026-03-02T14:00:00Z'],
            ['2026-03-02T12:00:60Z','2026-03-02T13:00:00Z'],
            ['2026-03-02T12:00+00:99','2026-03-02T13:00+00:99'],
            ['2026-03-02T12:00+24:00','2026-03-02T13:00+24:00'],
        ];
    }
    #[DataProvider('impossibleTimes')]
    public function testImpossibleIsoTimesRequireReviewInsteadOfInventingAttendance(string $join,string $leave): void
    {
        $start=strtotime('2026-03-02T12:00:00Z');
        $result=AttendanceSessions::calculate([['line'=>2,'join'=>$join,'leave'=>$leave,'seconds'=>3600]],$start,$start+3600,70,['timezone'=>'UTC','date_format'=>'ymd']);
        self::assertSame('review',$result['status']);self::assertNull($result['seconds']);self::assertNull($result['percent']);self::assertNotEmpty($result['review_reason']);
    }
    public static function validTimes(): array
    {
        return [
            ['2024-02-29T12:00:00Z','2024-02-29T13:00:00Z','2024-02-29T12:00:00Z'],
            ['2026-03-02T07:00-05:00','2026-03-02T08:00-05:00','2026-03-02T12:00:00Z'],
            ['2026-03-02T13:00:00+0100','2026-03-02T14:00:00+0100','2026-03-02T12:00:00Z'],
            ['2026-03-02T12:00Z','2026-03-02T13:00Z','2026-03-02T12:00:00Z'],
        ];
    }
    #[DataProvider('validTimes')]
    public function testLeapDatesAndExplicitOffsetsKeepTheCorrectDuration(string $join,string $leave,string $eventStart): void
    {
        $start=strtotime($eventStart);$result=AttendanceSessions::calculate([['line'=>2,'join'=>$join,'leave'=>$leave,'seconds'=>3600]],$start,$start+3600,70,['timezone'=>'America/Lima','date_format'=>'dmy']);
        self::assertSame('present',$result['status']);self::assertSame(3600,$result['seconds']);self::assertSame('',$result['review_reason']);
    }
}
