<?php

namespace NovaPoshta\Filament\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use NovaPoshta\Filament\Jobs\SyncDirectories;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Services\ScheduleService;
use NovaPoshta\Filament\Services\SettingsService;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Support\Tables;
use PHPUnit\Framework\Attributes\DataProvider;

final class StorageAndScheduleTest extends TestCase
{
    public static function prefixes(): array
    {
        return [['api_nova_poshta_', ''], ['api_', ''], ['api_', 'host_']];
    }

    #[DataProvider('prefixes')]
    public function test_migrations_search_and_rollback_with_both_prefixes(string $packagePrefix, string $connectionPrefix): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_10_28_000001_create_nova_poshta_tables.php';
        $observability = require __DIR__.'/../database/migrations/2026_10_29_000002_add_sync_observability.php';
        $observability->down();
        $migration->down();
        config(['nova-poshta.table_prefix' => $packagePrefix, 'database.connections.testing.prefix' => $connectionPrefix]);
        DB::purge('testing');
        $migration->up();
        $observability->up();
        $this->assertTrue(Schema::hasTable(Tables::name('warehouses')));
        $this->assertSame('completed', $this->sync()->status);
        $this->getJson('/api/nova-poshta/v1/warehouses')->assertOk()->assertJsonPath('data.0.ref', 'warehouse');
        $rawNames = Schema::getTableListing(schemaQualified: false);
        $this->assertContains($connectionPrefix.$packagePrefix.'warehouses', $rawNames);
        $observability->down();
        $migration->down();
        foreach (array_merge(Tables::directories(), ['settings', 'generations', 'sync_runs', 'sync_events', 'street_cache']) as $suffix) {
            $this->assertFalse(Schema::hasTable(Tables::name($suffix)));
        }
    }

    public function test_two_connection_prefixes_can_coexist_with_independent_indexes(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_10_28_000001_create_nova_poshta_tables.php';
        $observability = require __DIR__.'/../database/migrations/2026_10_29_000002_add_sync_observability.php';
        DB::connection()->setTablePrefix('second_');
        try {
            $migration->up();
            $observability->up();
            $this->assertTrue(Schema::hasTable(Tables::name('warehouses')));
            $observability->down();
            $migration->down();
        } finally {
            DB::connection()->setTablePrefix('');
        }
        $this->assertTrue(Schema::hasTable(Tables::name('warehouses')));
        $this->assertSame(1, Settings::current()->id);
    }

    public function test_key_encrypted_hidden_and_blank_replacement_preserves_it(): void
    {
        $service = app(SettingsService::class);
        $schedule = Settings::current()->schedule;
        $service->save($schedule, 'SECRET');
        $this->assertNotSame('SECRET', DB::table(Tables::name('settings'))->value('api_key'));
        $this->assertSame('SECRET', Settings::current()->api_key);
        $this->assertArrayNotHasKey('api_key', Settings::current()->toArray());
        $service->save($schedule, '');
        $this->assertSame('SECRET', Settings::current()->api_key);
    }

    public function test_monthly_uses_last_day_including_leap_year_and_local_timezone(): void
    {
        $s = Settings::current();
        $s->schedule = [...$s->schedule, 'enabled' => true, 'frequency' => 'monthly', 'day' => 31];
        $service = app(ScheduleService::class);
        $this->assertTrue($service->due($s, CarbonImmutable::parse('2028-02-29 01:00:00', 'UTC')));
        $this->assertTrue($service->due($s, CarbonImmutable::parse('2027-02-28 01:00:00', 'UTC')));
        $this->assertFalse($service->due($s, CarbonImmutable::parse('2027-02-27 01:00:00', 'UTC')));
        $s->last_scheduled_at = CarbonImmutable::parse('2027-02-28 01:00:00', 'UTC');
        $this->assertFalse($service->due($s, CarbonImmutable::parse('2027-02-28 01:00:00', 'UTC')));
    }

    public function test_weekly_hourly_disabled_and_scheduler_deduplication(): void
    {
        $s = Settings::current();
        $service = app(ScheduleService::class);
        $this->assertFalse($service->due($s));
        $s->schedule = [...$s->schedule, 'enabled' => true, 'frequency' => 'weekly', 'weekday' => 1];
        $this->assertTrue($service->due($s, CarbonImmutable::parse('2026-10-26 03:00', 'Europe/Kyiv')));
        $this->assertFalse($service->due($s, CarbonImmutable::parse('2026-10-27 03:00', 'Europe/Kyiv')));
        $s->schedule = [...$s->schedule, 'frequency' => 'hourly', 'hours' => 3];
        $s->last_scheduled_at = CarbonImmutable::parse('2026-10-26 00:00', 'UTC');
        $this->assertTrue($service->due($s, CarbonImmutable::parse('2026-10-26 03:00', 'UTC')));
        $this->assertFalse($service->due($s, CarbonImmutable::parse('2026-10-26 02:59', 'UTC')));
        $s->schedule = [...$s->schedule, 'frequency' => 'daily'];
        $s->last_scheduled_at = null;
        $s->save();
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 03:00', 'Europe/Kyiv'));
        try {
            app(SyncCoordinator::class)->tick();
            app(SyncCoordinator::class)->tick();
            $this->assertNotNull(Settings::current()->scheduler_seen_at);
            $this->assertNotNull(Settings::current()->last_scheduled_at);
            Queue::assertPushed(SyncDirectories::class, 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
