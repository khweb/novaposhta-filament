<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Services\SyncCoordinator;
use NovaPoshta\Filament\Services\Synchronizer;

final class SyncDirectories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId, public readonly int $revision = 0)
    {
        $this->timeout = (int) config('nova-poshta.sync.timeout', 120);
    }

    public function handle(Synchronizer $synchronizer): void
    {
        $lock = Cache::lock(SyncCoordinator::lockName('worker'), $this->timeout + 60);
        if (! $lock->get()) {
            if (! $this->isSynchronous()) {
                self::dispatch($this->runId, $this->revision)->onQueue(config('nova-poshta.queue'))->delay(now()->addSeconds(10));
            }

            return;
        }
        $delay = null;
        try {
            Settings::query()->whereKey(1)->update(['worker_seen_at' => now()]);
            $delay = $synchronizer->step($this->runId, $this->revision);
        } catch (DirectoryException $exception) {
            $this->fail($exception);
        } finally {
            $lock->release();
        }
        if ($delay !== null) {
            if ($delay > 0 && $this->isSynchronous()) {
                $synchronizer->fail($this->runId, 'async_queue_required');

                return;
            }
            $run = SyncRun::query()->findOrFail($this->runId);
            self::dispatch($run->id, $run->revision)->onQueue(config('nova-poshta.queue'))->delay(now()->addSeconds($delay));
        }
    }

    private function isSynchronous(): bool
    {
        return config('queue.connections.'.($this->connection ?? config('queue.default')).'.driver') === 'sync';
    }

    public function failed(?\Throwable $exception): void
    {
        $run = SyncRun::query()->find($this->runId);
        if ($run && $run->revision === $this->revision) {
            app(Synchronizer::class)->fail($this->runId, $exception instanceof DirectoryException ? $exception->reason : 'worker_failed');
        }
    }
}
