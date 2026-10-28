<?php

namespace NovaPoshta\Filament\Tests;

use Illuminate\Support\Facades\Http;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Services\ApiClient;
use NovaPoshta\Filament\Services\DirectoryDownloader;

final class ApiClientTest extends TestCase
{
    public function test_real_public_fixtures_and_historical_envelopes(): void
    {
        $api = app(ApiClient::class);
        foreach (['getAreas', 'getCities', 'getSettlements', 'getWarehouses', 'getWarehouseTypes'] as $method) {
            $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/'.$method.'.json'), true);
            $this->assertSame($fixture['data'], $api->normalize($fixture)['data']);
            $this->assertSame($fixture['data'], $api->normalize([$fixture])['data']);
        }
    }

    public function test_http_errors_and_json_errors_are_distinct_and_safe(): void
    {
        Http::fakeSequence()->push(['secret' => 'hidden'], 500)->push(['secret' => 'hidden'], 500)->push(['secret' => 'hidden'], 500)->push(['success' => false, 'data' => [], 'errors' => ['SECRET-KEY']]);
        try {
            app(ApiClient::class)->validateKey('SECRET-KEY');
            $this->fail('Expected safe error');
        } catch (DirectoryException $e) {
            $this->assertSame('upstream_http_error', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('SECRET', (string) $e);
        }

        $this->expectExceptionMessage('upstream_api_error');
        app(ApiClient::class)->validateKey('SECRET-KEY');
    }

    public function test_key_validation_is_read_only_and_has_minimal_limit(): void
    {
        Http::fake(fn () => Http::response(['success' => true, 'data' => [['Private' => 'must-not-persist']], 'info' => []]));
        $this->assertTrue(app(ApiClient::class)->validateKey('SECRET'));
        Http::assertSent(fn ($r) => $r['modelName'] === 'Counterparty' && $r['calledMethod'] === 'getCounterparties'
            && $r['methodProperties']['Limit'] === '1' && $r['apiKey'] === 'SECRET');
    }

    public function test_short_pages_and_underreported_total_do_not_truncate_download(): void
    {
        Http::fakeSequence()
            ->push(['success' => true, 'data' => [['Ref' => 'one']], 'info' => ['totalCount' => 1]])
            ->push(['success' => true, 'data' => [['Ref' => 'two']], 'info' => ['totalCount' => 'invalid']])
            ->push(['success' => true, 'data' => [], 'info' => []]);
        $pages = iterator_to_array(app(DirectoryDownloader::class)->pages('getSettlements'));
        $this->assertCount(2, $pages);
        Http::assertSent(fn ($r) => $r['methodProperties']['Limit'] === '150');
    }

    public function test_repeated_pages_fail(): void
    {
        Http::fake(fn () => Http::response(['success' => true, 'data' => [['Ref' => 'same']], 'info' => []]));
        $this->expectExceptionMessage('invalid_or_repeated_page');
        iterator_to_array(app(DirectoryDownloader::class)->pages('getSettlements'));
    }

    public function test_incomplete_advertised_total_fails(): void
    {
        Http::fakeSequence()->push(['success' => true, 'data' => [['Ref' => 'one']], 'info' => ['totalCount' => 50]])
            ->push(['success' => true, 'data' => [], 'info' => []]);
        $this->expectExceptionMessage('incomplete_directory');
        iterator_to_array(app(DirectoryDownloader::class)->pages('getCities'));
    }
}
