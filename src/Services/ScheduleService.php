<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Carbon\CarbonImmutable;
use NovaPoshta\Filament\Models\Settings;

final class ScheduleService
{
    public function due(Settings $settings, ?CarbonImmutable $instant = null): bool
    {
        $s = $settings->schedule ?? [];
        if (! ($s['enabled'] ?? false)) {
            return false;
        }
        $now = ($instant ?? CarbonImmutable::now())->setTimezone($s['timezone'] ?? 'Europe/Kyiv');
        $last = $settings->last_scheduled_at?->setTimezone($now->timezone);
        if (($s['frequency'] ?? 'daily') === 'hourly') {
            return $last === null || $now->getTimestamp() >= $last->getTimestamp() + ((int) ($s['hours'] ?? 24) * 3600);
        }
        $time = $s['time'] ?? '03:00';
        if ($now->format('H:i') !== $time || $last?->toDateString() === $now->toDateString()) {
            return false;
        }

        return match ($s['frequency'] ?? 'daily') {
            'daily' => true,
            'weekly' => $now->isoWeekday() === (int) ($s['weekday'] ?? 1),
            'monthly' => $now->day === min((int) ($s['day'] ?? 1), $now->daysInMonth),
            default => false,
        };
    }
}
