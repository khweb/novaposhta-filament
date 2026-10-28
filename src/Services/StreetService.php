<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Support\Tables;

final class StreetService
{
    public function __construct(private readonly ApiClient $api) {}

    public function search(int $generation, string $settlement, string $query): array
    {
        $id = hash('sha256', $generation.':'.$settlement.':'.mb_strtolower(trim($query)));
        $cached = $this->cached($id);
        if ($cached !== null) {
            return $cached;
        }
        try {
            return Cache::lock(SyncCoordinator::lockName('streets:'.$id), 180)->block(5, function () use ($id, $generation, $settlement, $query): array {
                if (($cached = $this->cached($id)) !== null) {
                    return $cached;
                }
                $rows = [];
                $seen = [];
                $deadline = microtime(true) + 60;
                for ($page = 1; $page <= 100; $page++) {
                    if (microtime(true) > $deadline) {
                        throw new DirectoryException('street_search_busy');
                    }
                    $result = $this->api->request('Address', 'searchSettlementStreets', [
                        'SettlementRef' => $settlement, 'StreetName' => trim($query), 'Page' => (string) $page, 'Limit' => '100',
                    ]);
                    $envelope = $result['data'][0] ?? ['TotalCount' => 0, 'Addresses' => []];
                    if (! is_array($envelope['Addresses'] ?? null)) {
                        throw new DirectoryException('upstream_invalid_streets');
                    }
                    foreach ($envelope['Addresses'] as $street) {
                        $ref = $street['SettlementStreetRef'] ?? $street['Ref'] ?? null;
                        if (! is_string($ref) || isset($seen[$ref]) || ($street['SettlementRef'] ?? $settlement) !== $settlement) {
                            throw new DirectoryException('upstream_invalid_streets');
                        }
                        $seen[$ref] = true;
                        $rows[] = [
                            'ref' => $ref, 'name' => $street['Present'] ?? $street['SettlementStreetDescription'] ?? '',
                            'name_ru' => $street['SettlementStreetDescriptionRu'] ?? null, 'settlement_ref' => $settlement,
                        ];
                    }
                    $total = $envelope['TotalCount'] ?? null;
                    if ($total !== null && is_numeric($total) && count($rows) >= (int) $total) {
                        break;
                    }
                    if ($envelope['Addresses'] === []) {
                        if (is_numeric($total) && count($rows) < (int) $total) {
                            throw new DirectoryException('incomplete_streets');
                        }
                        break;
                    }
                    if ($page === 100) {
                        throw new DirectoryException('incomplete_streets');
                    }
                }
                usort($rows, fn (array $a, array $b): int => [$a['name'], $a['ref']] <=> [$b['name'], $b['ref']]);
                DB::table(Tables::name('street_cache'))->upsert([[
                    'id' => $id, 'generation_id' => $generation, 'settlement_ref' => $settlement,
                    'payload' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'expires_at' => now()->addDay(),
                ]], ['id'], ['payload', 'expires_at']);

                return $rows;
            });
        } catch (LockTimeoutException) {
            throw new DirectoryException('street_search_busy');
        }
    }

    private function cached(string $id): ?array
    {
        $row = DB::table(Tables::name('street_cache'))->where('id', $id)->where('expires_at', '>', now())->first();

        return $row === null ? null : json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
