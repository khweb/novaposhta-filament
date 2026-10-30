<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NovaPoshta\Filament\Jobs\SyncDirectories;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Support\Tables;

final class SyncCoordinator
{
    public static function lockName(string $operation): string
    {
        return 'nova-poshta:'.sha1(config('database.default').':'.config('database.connections.'.config('database.default').'.database').':'.Tables::name('settings')).':'.$operation;
    }

    public function request(bool $scheduled = false): ?SyncRun
    {
        $created = false;
        $run = Cache::lock(self::lockName('dispatch'), 15)->block(5, function () use ($scheduled, &$created): ?SyncRun {
            return DB::transaction(function () use ($scheduled, &$created): ?SyncRun {
                $settings = Settings::query()->lockForUpdate()->findOrFail(1);
                if ($scheduled && ! app(ScheduleService::class)->due($settings)) {
                    return null;
                }
                $active = SyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
                if ($active !== null) {
                    $cutoff = now()->subSeconds((int) config('nova-poshta.sync.stale_seconds', 900));
                    if ($active->heartbeat_at->greaterThan($cutoff)
                        && ($active->status !== 'queued' || $active->created_at->greaterThan($cutoff))) {
                        return $active;
                    }
                    app(Synchronizer::class)->fail($active->id, 'heartbeat_expired');
                    Cache::lock(self::lockName('worker'))->forceRelease();
                }
                $run = SyncRun::query()->create(['heartbeat_at' => now(), 'status' => 'queued', 'stage' => 'queued', 'source' => $scheduled ? 'scheduled' : 'manual']);
                app(SyncJournal::class)->event($run, 'sync_queued');
                if ($scheduled) {
                    $settings->last_scheduled_at = now();
                    $settings->save();
                }
                $created = true;

                return $run;
            });
        });
        if ($created && $run !== null) {
            try {
                SyncDirectories::dispatch($run->id)->onQueue(config('nova-poshta.queue'));
            } catch (\Throwable) {
                app(Synchronizer::class)->fail($run->id, 'queue_dispatch_failed');
            }
        }

        return $run?->refresh();
    }

    public function tick(): void
    {
        Settings::query()->whereKey(1)->update(['scheduler_seen_at' => now()]);
        Cache::lock(self::lockName('dispatch'), 15)->block(5, function (): void {
            DB::transaction(function (): void {
                Settings::query()->lockForUpdate()->findOrFail(1);
                $cutoff = now()->subSeconds((int) config('nova-poshta.sync.stale_seconds', 900));
                $stale = SyncRun::query()->whereIn('status', ['queued', 'running'])
                    ->where(fn ($query) => $query->where('heartbeat_at', '<=', $cutoff)
                        ->orWhere(fn ($query) => $query->where('status', 'queued')->where('created_at', '<=', $cutoff)))->get();
                foreach ($stale as $run) {
                    app(Synchronizer::class)->fail($run->id, 'heartbeat_expired');
                    Cache::lock(self::lockName('worker'))->forceRelease();
                    if ($run->generation_id) {
                        DB::table(Tables::name('generations'))->where('id', $run->generation_id)->where('status', 'building')
                            ->update(['status' => 'failed', 'updated_at' => now()]);
                    }
                }
            });
        });
        // Recover a lost dispatch or a killed worker from the persisted checkpoint.
        // Duplicate deliveries are harmless: every job carries the expected revision.
        $recover = $this->active();
        if ($recover && ($recover->status === 'queued' || $recover->checkpoint !== null)
            && $recover->heartbeat_at->lessThan(now()->subSeconds((int) config('nova-poshta.sync.timeout', 120) + 90))
            && ! $recover->next_attempt_at?->isFuture()) {
            $recover->update(['heartbeat_at' => now()]);
            SyncDirectories::dispatch($recover->id, $recover->revision)->onQueue(config('nova-poshta.queue'));
        }
        $this->request(scheduled: true);
    }

    public function active(): ?SyncRun
    {
        return SyncRun::query()->whereIn('status', ['queued', 'running'])->latest('id')->first();
    }
}
