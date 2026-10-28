<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;

final class ApiClient
{
    public function request(string $model, string $method, array $properties = [], #[\SensitiveParameter] ?string $key = null, bool $waitForRateLimit = false): array
    {
        $body = ['modelName' => $model, 'calledMethod' => $method, 'methodProperties' => $properties === [] ? (object) [] : $properties];
        if ($key !== null) {
            $body['apiKey'] = $key;
        }
        $attempts = max(1, (int) config('nova-poshta.http.attempts', 3));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $deadline = microtime(true) + 65;
            while (! RateLimiter::attempt('nova-poshta:external', (int) config('nova-poshta.http.per_minute', 120), fn () => true, 60)) {
                if (! $waitForRateLimit || microtime(true) >= $deadline) {
                    throw new DirectoryException('upstream_rate_limited');
                }
                sleep(min(5, max(1, RateLimiter::availableIn('nova-poshta:external'))));
            }
            try {
                $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout((int) config('nova-poshta.http.timeout', 20))
                    ->post(config('nova-poshta.endpoint'), $body);
            } catch (\Throwable) {
                if ($attempt === $attempts) {
                    throw new DirectoryException('upstream_transport_error');
                }
                usleep((int) config('nova-poshta.http.retry_ms', 500) * 1000);

                continue;
            }
            Settings::query()->whereKey(1)->update(['api_seen_at' => now()]);
            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt < $attempts) {
                    usleep((int) config('nova-poshta.http.retry_ms', 500) * 1000);

                    continue;
                }
                throw new DirectoryException('upstream_http_error');
            }
            if (! $response->successful()) {
                throw new DirectoryException('upstream_http_error');
            }

            return $this->normalize($response->json());
        }
        throw new DirectoryException;
    }

    public function normalize(#[\SensitiveParameter] mixed $json): array
    {
        if (is_array($json) && array_is_list($json) && count($json) === 1 && is_array($json[0]) && array_key_exists('success', $json[0])) {
            $json = $json[0];
        }
        if (! is_array($json) || ! array_key_exists('success', $json)) {
            throw new DirectoryException('upstream_invalid_response');
        }
        if ($json['success'] !== true || ! empty($json['errors'])) {
            throw new DirectoryException('upstream_api_error');
        }
        if (! isset($json['data']) || ! is_array($json['data']) || ! array_is_list($json['data'])) {
            throw new DirectoryException('upstream_invalid_response');
        }

        return ['data' => $json['data'], 'info' => is_array($json['info'] ?? null) ? $json['info'] : []];
    }

    public function connection(): bool
    {
        return count($this->request('Address', 'getAreas')['data']) > 0;
    }

    public function validateKey(#[\SensitiveParameter] string $key): bool
    {
        if (trim($key) === '') {
            throw new DirectoryException('key_required');
        }
        $this->request('Counterparty', 'getCounterparties', ['CounterpartyProperty' => 'Sender', 'Page' => '1', 'Limit' => '1'], $key);

        return true;
    }
}
