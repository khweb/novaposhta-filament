<?php

namespace NovaPoshta\Filament\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\ApiClient;
use NovaPoshta\Filament\Services\DirectorySearch;
use NovaPoshta\Filament\Services\RecordNormalizer;
use NovaPoshta\Filament\Services\SettingsService;
use NovaPoshta\Filament\Services\SyncCoordinator;

final class FailureSafetyTest extends TestCase
{
    public function test_stale_job_is_detected_even_when_automatic_updates_disabled(): void
    {
        $run = SyncRun::query()->create(['status' => 'running', 'heartbeat_at' => now()->subHour()]);
        Queue::fake();
        app(SyncCoordinator::class)->tick();
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('heartbeat_expired', $run->error);
        Queue::assertNothingPushed();
    }

    public function test_stale_worker_loses_lock_and_cannot_release_new_owner_lock(): void
    {
        $oldLock = Cache::lock(SyncCoordinator::lockName('worker'), 7200);
        $this->assertTrue($oldLock->get());
        SyncRun::query()->create(['status' => 'running', 'heartbeat_at' => now()->subHour()]);
        app(SyncCoordinator::class)->tick();
        $newLock = Cache::lock(SyncCoordinator::lockName('worker'), 7200);
        $this->assertTrue($newLock->get());
        $this->assertFalse($oldLock->release());
        $this->assertFalse(Cache::lock(SyncCoordinator::lockName('worker'), 7200)->get());
        $newLock->release();
    }

    public function test_external_limit_prevents_another_http_request(): void
    {
        config(['nova-poshta.http.per_minute' => 1]);
        RateLimiter::hit('nova-poshta:external', 60);
        Http::fake();
        try {
            app(ApiClient::class)->connection();
            $this->fail('Expected rate limit');
        } catch (DirectoryException $e) {
            $this->assertSame('upstream_rate_limited', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_retry_is_bounded_and_succeeds_after_transient_error(): void
    {
        Http::fakeSequence()->push([], 503)->push(['success' => true, 'data' => [['Ref' => 'area']], 'info' => []]);
        $this->assertTrue(app(ApiClient::class)->connection());
        Http::assertSentCount(2);
    }

    public function test_empty_or_invalid_json_is_rejected(): void
    {
        $this->expectExceptionMessage('upstream_invalid_response');
        app(ApiClient::class)->normalize('<html>error</html>');
    }

    public function test_missing_required_warehouse_reference_is_rejected(): void
    {
        $this->expectExceptionMessage('missing_address_mapping');
        app(RecordNormalizer::class)->row('warehouses', ['Ref' => 'w', 'Description' => 'Warehouse', 'CityRef' => 'c', 'SettlementRef' => 's'], 1);
    }

    public function test_whitespace_street_query_is_rejected_before_external_request(): void
    {
        $this->sync();
        $this->expectException(ValidationException::class);
        app(DirectorySearch::class)->search('streets', ['settlement_ref' => 'settlement', 'q' => '   ']);
    }

    public function test_invalid_schedule_never_changes_key_or_settings(): void
    {
        $settings = Settings::current();
        $settings->update(['api_key' => 'ORIGINAL']);
        try {
            app(SettingsService::class)->save([...$settings->schedule, 'timezone' => 'invalid'], 'REPLACEMENT');
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame('ORIGINAL', Settings::current()->api_key);
            $this->assertSame('Europe/Kyiv', Settings::current()->schedule['timezone']);
        }
    }

    public function test_single_snapshot_query_does_not_mix_generations(): void
    {
        $first = $this->sync();
        $search = app(DirectorySearch::class);
        $builder = $search->query('settlements', [], $search->snapshot());
        $next = $this->sync();
        $this->assertNotSame($first->generation_id, $next->generation_id);
        $this->assertSame([$first->generation_id], $builder->get()->pluck('generation_id')->unique()->all());
    }
}
