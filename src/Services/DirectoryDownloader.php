<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Generator;
use NovaPoshta\Filament\Exceptions\DirectoryException;

final class DirectoryDownloader
{
    public function __construct(private readonly ApiClient $api) {}

    public function pages(string $method): Generator
    {
        $paged = ! in_array($method, ['getAreas', 'getWarehouseTypes'], true);
        $limit = $method === 'getSettlements' ? 150 : (int) config('nova-poshta.sync.page_size', 150);
        $seen = [];
        $count = 0;
        $advertised = null;
        for ($page = 1; $page <= (int) config('nova-poshta.sync.max_pages', 10000); $page++) {
            $response = $this->api->request('Address', $method, $paged ? ['Page' => (string) $page, 'Limit' => (string) $limit] : [], waitForRateLimit: true);
            $rows = $response['data'];
            $total = $response['info']['totalCount'] ?? null;
            // Malformed totals are ignored. Numeric totals are a lower-bound safety check,
            // never the stopping condition (the API has returned incorrect totals).
            if (is_numeric($total) && (int) $total >= 0) {
                $advertised = max($advertised ?? 0, (int) $total);
            }
            if ($rows === []) {
                if ($count === 0 || ($advertised !== null && $count < $advertised)) {
                    throw new DirectoryException('incomplete_directory');
                }

                return;
            }
            foreach ($rows as $row) {
                $ref = $row['Ref'] ?? null;
                if (! is_string($ref) || $ref === '' || strlen($ref) > 64 || isset($seen[$ref])) {
                    throw new DirectoryException('invalid_or_repeated_page');
                }
                $seen[$ref] = true;
            }
            $count += count($rows);
            yield $rows;
            if (! $paged) {
                return;
            }
            // Request the following page even after a short page: the upstream may cap Limit.
        }

        throw new DirectoryException('page_limit_exceeded');
    }
}
