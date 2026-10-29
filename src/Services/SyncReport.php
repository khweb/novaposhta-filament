<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncEvent;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Support\Lang;

final class SyncReport
{
    public function summary(SyncRun $run): array
    {
        $end = $run->finished_at ?? now();

        return [
            Lang::get('status') => Lang::get($run->status),
            Lang::get('source_label') => Lang::get($run->source),
            Lang::get('stage') => Lang::get($run->stage),
            Lang::get('started') => $run->started_at?->toDateTimeString() ?? Lang::get('waiting_worker'),
            Lang::get('finished') => $run->finished_at?->toDateTimeString() ?? '—',
            Lang::get('duration') => Lang::get('seconds_value', ['seconds' => (int) ($run->started_at?->diffInSeconds($end) ?? 0)]),
            Lang::get('request_count') => (string) $run->request_count,
            Lang::get('error_count') => (string) $run->error_count,
            Lang::get('retry_count') => (string) $run->retry_count,
            Lang::get('warning_count') => (string) $run->warning_count,
            Lang::get('key_health') => Lang::get($run->key_status ?? 'unchecked'),
            Lang::get('last_success') => Settings::current()->last_success_at?->toDateTimeString() ?? Lang::get('never'),
        ];
    }

    public function message(SyncEvent $event): string
    {
        return Lang::get($event->code, $event->context ?? []);
    }

    public function progress(SyncRun $run): int
    {
        return $run->status === 'completed' ? 7 : min(6, (int) ($run->checkpoint['stage'] ?? 0));
    }
}
