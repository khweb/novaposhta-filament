<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use NovaPoshta\Filament\Jobs\CheckApiKey;
use NovaPoshta\Filament\Models\Settings;

final class SettingsService
{
    public function save(array $schedule, #[\SensitiveParameter] ?string $replacementKey = null): void
    {
        $schedule = [...(Settings::current()->schedule ?? []), ...$schedule];
        $schedule = Validator::make($schedule, [
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(['hourly', 'daily', 'weekly', 'monthly'])],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'day' => ['required', 'integer', 'min:1', 'max:31'],
        ])->validate();
        Validator::make(['key' => $replacementKey], ['key' => ['nullable', 'string', 'max:512']])->validate();
        $settings = Settings::current();
        $settings->schedule = $schedule;
        if ($replacementKey !== null && trim($replacementKey) !== '') {
            $settings->api_key = trim($replacementKey);
            $settings->key_revision = (string) Str::uuid();
            $settings->key_status = 'unchecked';
            $settings->key_checked_at = null;
        }
        $settings->save();
        if ($settings->wasChanged('key_revision')) {
            CheckApiKey::dispatch($settings->key_revision)->onQueue(config('nova-poshta.queue'))->afterCommit();
        }
    }
}
