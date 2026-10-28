<?php

namespace NovaPoshta\Filament\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use NovaPoshta\Filament\Jobs\SyncDirectories;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\DirectorySearch;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Services\Synchronizer;
use NovaPoshta\Filament\Support\Tables;

final class SyncTest extends TestCase
{
    public function test_publishes_complete_generation_and_keeps_previous_on_error(): void
    {
        $this->freezeTime();
        $first = $this->sync();
        $this->assertSame('completed', $first->status, json_encode($first->toArray()));
        $id = Settings::current()->active_generation_id;
        $result = app(DirectorySearch::class)->search('warehouses', ['settlement_ref' => 'settlement', 'type' => 'parcel_locker']);
        $this->assertSame('warehouse', $result->items()[0]['ref']);
        $this->assertSame('territorial-area', $result->items()[0]['area_ref']);
        $this->assertSame('city', $result->items()[0]['city_ref']);
        $this->assertSame('failed', $this->sync('getWarehouses')->status);
        $this->assertEquals($id, Settings::current()->active_generation_id);
        $this->assertSame('completed', $this->sync()->status);
        $this->assertNotEquals($id, Settings::current()->active_generation_id);
        $this->assertSame(1, app(DirectorySearch::class)->query('warehouses', [], (int) $id)->count());
    }

    public function test_repeated_start_returns_same_job_and_detects_stale_job(): void
    {
        Queue::fake();
        $coordinator = app(SyncCoordinator::class);
        $first = $coordinator->request();
        $this->assertSame($first->id, $coordinator->request()->id);
        Queue::assertPushed(SyncDirectories::class, 1);
        $first->update(['heartbeat_at' => now()->subHour()]);
        $next = $coordinator->request();
        $this->assertNotSame($first->id, $next->id);
        $this->assertSame('heartbeat_expired', $first->refresh()->error);
        $this->assertSame('failed', $first->status);
    }

    public function test_worker_ignores_cancelled_or_duplicate_job(): void
    {
        $this->fakeDirectories();
        $run = SyncRun::query()->create(['status' => 'queued', 'heartbeat_at' => now()]);
        $job = new SyncDirectories($run->id);
        $job->handle(app(Synchronizer::class));
        $this->assertSame('completed', $run->refresh()->status);
        $job->handle(app(Synchronizer::class));
        $this->assertSame(1, DB::table(Tables::name('generations'))->count());
    }

    public function test_obsolete_job_cannot_publish(): void
    {
        $this->sync();
        $old = Settings::current()->active_generation_id;
        $run = SyncRun::query()->create(['status' => 'failed', 'heartbeat_at' => now()]);
        app(Synchronizer::class)->run($run->id);
        $this->assertEquals($old, Settings::current()->active_generation_id);
    }

    public function test_invalid_relationship_prevents_publication(): void
    {
        $run = $this->sync();
        DB::table(Tables::name('warehouses'))->where('generation_id', $run->generation_id)->update(['settlement_ref' => 'missing']);
        $this->expectExceptionMessage('broken_directory_relationship');
        app(Synchronizer::class)->validate($run->generation_id);
    }

    public function test_worker_lock_is_shared_by_all_jobs(): void
    {
        $lock = Cache::lock(SyncCoordinator::lockName('worker'), 60);
        $this->assertTrue($lock->get());
        $second = Cache::lock(SyncCoordinator::lockName('worker'), 60);
        $this->assertFalse($second->get());
        $lock->release();
        $this->assertTrue($second->get());
        $second->release();
    }
}
