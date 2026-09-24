<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ASCLA\Core\Services\Locations;
use ASCLA\Core\Rest\ApiException;

final class LocationsCoverageTest extends TestCase
{
    private $transport;
    private mixed $cached;

    protected function setUp(): void
    {
        $this->cached=get_transient('ascla_geo_cities_pe');
        delete_transient('ascla_geo_cities_pe');
    }

    protected function tearDown(): void
    {
        if ($this->transport) { remove_filter('pre_http_request',$this->transport,10); }
        delete_transient('ascla_geo_cities_pe');
        if ($this->cached!==false) { set_transient('ascla_geo_cities_pe',$this->cached,DAY_IN_SECONDS); }
    }

    private function respond(mixed $payload,int $status=200): void
    {
        $this->transport=static function($pre,$args,$url) use($payload,$status) {
            self::assertSame('https://countriesnow.space/api/v0.1/countries/cities/q?country=Peru',$url);
            self::assertSame(8,$args['timeout']);
            return $payload instanceof WP_Error ? $payload : ['response'=>['code'=>$status],'headers'=>[],'body'=>wp_json_encode($payload)];
        };
        add_filter('pre_http_request',$this->transport,10,3);
    }

    public function testCitiesAreSanitizedDeduplicatedSortedAndCached(): void
    {
        $this->respond(['data'=>[' Lima ','<b>Arequipa</b>','Lima','','Cusco',str_repeat('x',121)]]);
        self::assertSame(['Arequipa','Lima'],Locations::cities('Perú','a')['items']);
        remove_filter('pre_http_request',$this->transport,10);
        $this->transport=static function() { self::fail('Cached cities must not make another HTTP request'); };
        add_filter('pre_http_request',$this->transport,10,3);
        self::assertSame(['Lima'],Locations::cities('PE','LÍMA',true)['items']);
        self::assertTrue(Locations::cities('Peru','lima',true)['exact']);
        self::assertFalse(Locations::cities('PE','lim',true)['exact']);
        self::assertSame([],Locations::cities('PE','')['items']);
        self::assertTrue(Locations::cities('PE','')['available']);
    }

    public function testSuggestionsAreLimitedButExactMatchingChecksTheEntireCatalog(): void
    {
        set_transient('ascla_geo_cities_pe',array_map(static fn($i)=>'City '.$i,range(1,60)),60);
        self::assertCount(40,Locations::cities('PE','City')['items']);
        self::assertSame(['City 59'],Locations::cities('PE','City 59',true)['items']);
    }

    public static function failures(): array
    {
        return [
            'transport'=>[new WP_Error('offline','No network'),200],
            'HTTP'=>[['data'=>['Lima']],503],
            'API error'=>[['error'=>true,'data'=>['Lima']],200],
            'missing data'=>[['ok'=>true],200],
            'malformed data'=>[['data'=>'Lima'],200],
            'scalar'=>[null,200],
        ];
    }

    #[DataProvider('failures')]
    public function testUnavailableProviderDoesNotInventLocations(mixed $body,int $status): void
    {
        $this->respond($body,$status);
        $result=Locations::cities('PE','Lima');
        self::assertFalse($result['available']);
        self::assertSame([],$result['items']);
        self::assertFalse(get_transient('ascla_geo_cities_pe'));
    }

    public function testUnknownCountryIsRejectedBeforeAnyTransport(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        Locations::cities('not-a-country','x');
    }
}
