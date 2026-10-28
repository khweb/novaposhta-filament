<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Services\Synchronizer;
use NovaPoshta\Filament\Support\Tables;

final class SyncDirectories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = (int) config('nova-poshta.sync.timeout', 7200);
    }

    public function handle(Synchronizer $synchronizer): void
    {
        $lock = Cache::lock(SyncCoordinator::lockName('worker'), $this->timeout + 60);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }
        try {
            Settings::query()->whereKey(1)->update(['worker_seen_at' => now()]);
            if (SyncRun::query()->whereKey($this->runId)->where('status', 'queued')->update([
                'status' => 'running', 'started_at' => now(), 'heartbeat_at' => now(),
            ]) === 1) {
                $synchronizer->run($this->runId);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        SyncRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error' => 'worker_failed', 'finished_at' => now(),
        ]);
        $generation = SyncRun::query()->whereKey($this->runId)->where('status', 'failed')->value('generation_id');
        if ($generation) {
            DB::table(Tables::name('generations'))->where('id', $generation)->where('status', 'building')
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}
