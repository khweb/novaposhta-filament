<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use NovaPoshta\Filament\Models\SyncEvent;
use NovaPoshta\Filament\Models\SyncRun;

final class SyncJournal
{
    // Only package-generated codes and numeric metadata are persisted, never API bodies.
    public function event(SyncRun $run, string $code, string $level = 'info', array $context = []): void
    {
        $safe = [];
        foreach (['page', 'records', 'attempt', 'seconds', 'ms', 'http'] as $key) {
            if (isset($context[$key]) && is_numeric($context[$key])) {
                $safe[$key] = (int) $context[$key];
            }
        }
        SyncEvent::query()->create([
            'sync_run_id' => $run->id, 'stage' => $run->stage,
            'code' => $code, 'level' => $level, 'context' => $safe, 'created_at' => now(),
        ]);
    }

    public function prune(): void
    {
        SyncRun::query()->whereNotIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subDays(7))->orderBy('id')
            ->chunkById(100, function ($runs): void {
                SyncRun::query()->whereIn('id', $runs->modelKeys())->delete();
            });
    }
}
