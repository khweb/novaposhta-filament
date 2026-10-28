<?php

namespace NovaPoshta\Filament\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use NovaPoshta\Filament\Support\Tables;

final class RestTest extends TestCase
{
    public function test_before_first_load_returns_explicit_503_and_validates_inputs(): void
    {
        $this->getJson('/api/nova-poshta/v1/areas')->assertStatus(503)->assertJsonPath('error', 'directory_not_ready');
        $this->getJson('/api/nova-poshta/v1/streets?q=ab')->assertUnprocessable();
        $this->getJson('/api/nova-poshta/v1/areas?per_page=101')->assertUnprocessable();
        $this->getJson('/api/nova-poshta/v1/areas?type=parcel_locker')->assertUnprocessable();
        $this->getJson('/api/nova-poshta/v1/settings')->assertNotFound();
        $this->postJson('/api/nova-poshta/v1/warehouses')->assertStatus(405);
    }

    public function test_filters_pagination_and_distinct_external_refs(): void
    {
        $this->sync();
        $this->getJson('/api/nova-poshta/v1/areas')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/nova-poshta/v1/settlements?area_ref=territorial-area&district_ref=district&q='.urlencode('АБАЗ'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ref', 'settlement');
        $this->getJson('/api/nova-poshta/v1/settlements?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2)
            ->assertJsonStructure(['data', 'meta' => ['per_page', 'last_page'], 'links' => ['first', 'last', 'prev', 'next']]);
        $this->getJson('/api/nova-poshta/v1/warehouses?type=parcel_locker&settlement_ref=settlement')
            ->assertOk()->assertJsonPath('data.0.city_ref', 'city')->assertJsonPath('data.0.settlement_ref', 'settlement')
            ->assertJsonPath('data.0.latitude', 50.45)->assertJsonPath('data.0.limits.TotalMaxWeightAllowed', '20');
        $this->getJson('/api/nova-poshta/v1/warehouses?type=branch')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/nova-poshta/v1/districts?area_ref=other-area')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/nova-poshta/v1/warehouse-types')->assertOk()->assertJsonPath('data.0.ref', 'type');
    }

    public function test_street_normalization_cache_expiration_and_unknown_settlement(): void
    {
        $this->sync();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/streets.json'), true);
        $fixture['data'][0]['Addresses'][0]['SettlementRef'] = 'settlement';
        Http::fake(fn () => Http::response($fixture));
        $url = '/api/nova-poshta/v1/streets?settlement_ref=settlement&q='.urlencode('Шев');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.name', 'вул. Шевченка');
        $this->getJson($url)->assertOk();
        Http::assertSentCount(1);
        DB::table(Tables::name('street_cache'))->update(['expires_at' => now()->subSecond()]);
        $this->getJson($url)->assertOk();
        Http::assertSentCount(2);
        $this->getJson('/api/nova-poshta/v1/streets?settlement_ref=unknown&q=abc')->assertUnprocessable();
    }

    public function test_street_failure_returns_safe_503(): void
    {
        $this->sync();
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['success' => false, 'data' => [], 'errors' => ['SECRET']]));
        $response = $this->getJson('/api/nova-poshta/v1/streets?settlement_ref=settlement&q=abc');
        $response->assertStatus(503)->assertJsonPath('error', 'upstream_api_error');
        $this->assertStringNotContainsString('SECRET', $response->getContent());
    }

    public function test_general_rate_limit_is_shared_between_routes(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/nova-poshta/v1/areas')->assertStatus(503);
        }
        $this->getJson('/api/nova-poshta/v1/settlements')->assertStatus(429);
    }

    public function test_street_rate_limit_is_ten_per_minute(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/nova-poshta/v1/streets')->assertUnprocessable();
        }
        $this->getJson('/api/nova-poshta/v1/streets')->assertStatus(429);
    }
}
