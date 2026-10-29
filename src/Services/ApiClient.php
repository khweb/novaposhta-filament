<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;

final class ApiClient
{
    public function request(string $model, string $method, array $properties = [], #[\SensitiveParameter] ?string $key = null, bool $waitForRateLimit = false, ?int $runId = null): array
    {
        $body = ['modelName' => $model, 'calledMethod' => $method, 'methodProperties' => $properties === [] ? (object) [] : $properties];
        if ($key !== null) {
            $body['apiKey'] = $key;
        }
        $attempts = $runId === null ? max(1, (int) config('nova-poshta.http.attempts', 3)) : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $deadline = microtime(true) + 65;
            while (! RateLimiter::attempt('nova-poshta:external', (int) config('nova-poshta.http.per_minute', 120), fn () => true, 60)) {
                if (! $waitForRateLimit || microtime(true) >= $deadline) {
                    throw new DirectoryException('upstream_rate_limited', true, max(1, RateLimiter::availableIn('nova-poshta:external')));
                }
                sleep(min(5, max(1, RateLimiter::availableIn('nova-poshta:external'))));
            }
            try {
                if ($runId !== null) {
                    SyncRun::query()->whereKey($runId)->increment('request_count');
                }
                $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout((int) config('nova-poshta.http.timeout', 20))
                    ->post(config('nova-poshta.endpoint'), $body);
            } catch (\Throwable) {
                if ($attempt === $attempts) {
                    throw new DirectoryException('upstream_transport_error', true);
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
                $retry = $response->header('Retry-After');
                $seconds = is_numeric($retry) ? (int) $retry : max(0, (int) strtotime($retry ?? '') - time());
                throw new DirectoryException('upstream_http_error', true, min(600, max(0, $seconds)), $response->status());
            }
            if (! $response->successful()) {
                if ($key !== null && in_array($response->status(), [401, 403], true)) {
                    throw new DirectoryException('key_rejected', httpStatus: $response->status());
                }
                throw new DirectoryException('upstream_http_error', httpStatus: $response->status());
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
            foreach ((array) ($json['errors'] ?? []) as $error) {
                if (is_string($error) && preg_match('/(?:api.?key.*(?:invalid|expired|incorrect|not.?found)|(?:invalid|expired|incorrect).*api.?key)/i', $error)) {
                    throw new DirectoryException('key_rejected');
                }
            }
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
