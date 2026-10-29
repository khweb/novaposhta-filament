<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;

final class KeyHealth
{
    public function check(?string $revision = null): string
    {
        $settings = Settings::current();
        if ($revision !== null && $settings->key_revision !== $revision) {
            return 'unchecked';
        }
        $status = 'key_missing';
        if ($settings->api_key) {
            try {
                app(ApiClient::class)->validateKey($settings->api_key);
                $status = 'key_valid';
            } catch (DirectoryException $exception) {
                $status = $exception->reason === 'key_rejected' ? 'key_rejected' : 'key_unavailable';
            }
        }
        // Compare revision AND ciphertext to guard replacement during an in-flight check.
        Settings::query()->whereKey(1)->where('key_revision', $settings->key_revision)
            ->where('api_key', $settings->getRawOriginal('api_key'))
            ->update(['key_status' => $status, 'key_checked_at' => now()]);

        return $status;
    }
}
