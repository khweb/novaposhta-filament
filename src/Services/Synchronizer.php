<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Support\Tables;

final class Synchronizer
{
    public const STAGES = ['areas' => 'getAreas', 'settlements' => 'getSettlements', 'cities' => 'getCities', 'warehouse_types' => 'getWarehouseTypes', 'warehouses' => 'getWarehouses'];

    public function __construct(private readonly ApiClient $api, private readonly RecordNormalizer $normalizer, private readonly SyncJournal $journal) {}

    public function run(int $runId): void
    {
        // Synchronous service entry point; production uses one step per queued job.
        do {
            $run = SyncRun::query()->findOrFail($runId);
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            try {
                $delay = $this->step($runId, $run->revision);
            } catch (DirectoryException) {
                return;
            }
            if ($delay > 0) {
                return; // Delayed retries belong to the queue, never sleep inside this service.
            }
        } while ($delay !== null);
    }

    public function step(int $runId, int $revision): ?int
    {
        $run = SyncRun::query()->findOrFail($runId);
        if (! in_array($run->status, ['queued', 'running'], true) || $run->revision !== $revision) {
            return null;
        }
        if ($run->next_attempt_at?->isFuture()) {
            return max(1, (int) ceil(now()->diffInSeconds($run->next_attempt_at)));
        }
        try {
            if (! $run->generation_id) {
                DB::transaction(function () use ($run, $revision): void {
                    $locked = SyncRun::query()->lockForUpdate()->findOrFail($run->id);
                    if (! in_array($locked->status, ['queued', 'running'], true) || $locked->revision !== $revision) {
                        throw new DirectoryException('obsolete_job');
                    }
                    $generation = DB::table(Tables::name('generations'))->insertGetId(['status' => 'building', 'created_at' => now(), 'updated_at' => now()]);
                    $run->update(['generation_id' => $generation, 'status' => 'running', 'stage' => 'areas', 'started_at' => $run->started_at ?? now(), 'heartbeat_at' => now(), 'checkpoint' => ['stage' => 0, 'page' => 1, 'attempt' => 0]]);
                    $this->journal->event($run, 'sync_started');
                });
                $status = app(KeyHealth::class)->check();
                $run->update(['key_status' => $status]);
                $warning = in_array($status, ['key_rejected', 'key_unavailable'], true);
                if ($warning) {
                    $run->increment('warning_count');
                }
                $this->journal->event($run, $status, $warning ? 'warning' : 'info');
            }
            $checkpoint = $run->checkpoint;
            $attempt = (int) ($checkpoint['attempt'] ?? 0) + 1;
            if ($attempt > max(1, (int) config('nova-poshta.http.attempts', 3))) {
                throw new DirectoryException('worker_failed');
            }
            $run->update(['checkpoint' => [...$checkpoint, 'attempt' => $attempt], 'heartbeat_at' => now()]);
            if ($checkpoint['stage'] === count(self::STAGES)) {
                return $this->validateBatch($run, $revision);
            }
            if ($checkpoint['stage'] > count(self::STAGES)) {
                return $this->publish($run, $revision);
            }
            $stage = array_keys(self::STAGES)[$checkpoint['stage']];
            $page = (int) $checkpoint['page'];
            if ($page > (int) config('nova-poshta.sync.max_pages', 10000)) {
                throw new DirectoryException('page_limit_exceeded');
            }
            $run->update(['stage' => $stage, 'checkpoint' => [...$checkpoint, 'attempt' => $attempt], 'heartbeat_at' => now()]);
            $this->journal->event($run, 'page_requested', context: ['page' => $page, 'attempt' => $attempt]);
            $paged = ! in_array($stage, ['areas', 'warehouse_types'], true);
            $limit = $stage === 'settlements' ? 150 : (int) config('nova-poshta.sync.page_size', 150);
            $start = hrtime(true);
            $response = $this->api->request('Address', self::STAGES[$stage], $paged ? ['Page' => (string) $page, 'Limit' => (string) $limit] : [], runId: $runId);
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            DB::transaction(function () use ($run, $revision, $checkpoint, $response, $stage, $page, $paged, $ms): void {
                $locked = SyncRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($locked->status !== 'running' || $locked->revision !== $revision) {
                    throw new DirectoryException('obsolete_job');
                }
                $rows = $response['data'];
                $count = (int) ($locked->counts[$stage] ?? 0);
                $advertised = $checkpoint['advertised'] ?? 0;
                $total = $response['info']['totalCount'] ?? null;
                if (is_numeric($total) && (int) $total >= 0) {
                    $advertised = max($advertised, (int) $total);
                }
                if ($rows === [] && ($count === 0 || $count < $advertised)) {
                    throw new DirectoryException('incomplete_directory');
                }
                $refs = [];
                foreach ($rows as $row) {
                    $ref = is_array($row) ? ($row['Ref'] ?? null) : null;
                    if (! is_string($ref) || $ref === '' || strlen($ref) > 64 || isset($refs[$ref])) {
                        throw new DirectoryException('invalid_or_repeated_page');
                    }
                    $refs[$ref] = true;
                }
                if ($refs && DB::table(Tables::name($stage))->where('generation_id', $locked->generation_id)->whereIn('ref', array_keys($refs))->exists()) {
                    throw new DirectoryException('invalid_or_repeated_page');
                }
                $this->journal->event($locked, 'page_received', context: ['page' => $page, 'records' => count($rows), 'ms' => $ms]);
                $normalized = array_map(fn (array $row): array => $this->normalizer->row($stage, $row, $locked->generation_id), $rows);
                foreach (array_chunk($normalized, 50) as $chunk) {
                    DB::table(Tables::name($stage))->insert($chunk);
                }
                if ($stage === 'settlements') {
                    $this->territories($locked->generation_id, $rows);
                }
                if ($stage === 'warehouses') {
                    $this->mappings($locked->generation_id, $rows);
                }
                $counts = [...($locked->counts ?? []), $stage => $count + count($rows)];
                $finished = ! $paged || $rows === [];
                $next = $finished ? ['stage' => $checkpoint['stage'] + 1, 'page' => 1, 'attempt' => 0] : [...$checkpoint, 'page' => $page + 1, 'attempt' => 0, 'advertised' => $advertised];
                $nextStage = array_keys(self::STAGES)[$next['stage']] ?? 'validation';
                $locked->update(['checkpoint' => $next, 'counts' => $counts, 'revision' => $revision + 1, 'stage' => $nextStage, 'heartbeat_at' => now(), 'next_attempt_at' => null]);
                $this->journal->event($run, $finished ? 'directory_completed' : 'page_saved', context: ['page' => $page, 'records' => $finished ? $counts[$stage] : count($rows)]);
            });

            return 0;
        } catch (\Throwable $exception) {
            $reason = $exception instanceof DirectoryException ? $exception->reason : 'synchronization_failed';
            if ($reason === 'obsolete_job') {
                return null;
            }
            $run->refresh();
            if (! in_array($run->status, ['queued', 'running'], true) || $run->revision !== $revision) {
                return null;
            }
            if ($exception instanceof DirectoryException && $exception->retryable && ($run->checkpoint['attempt'] ?? 0) < max(1, (int) config('nova-poshta.http.attempts', 3))) {
                $delay = max($exception->retryAfter, min(300, 5 * (2 ** max(0, ($run->checkpoint['attempt'] ?? 1) - 1))) + random_int(0, 3));
                $saved = DB::transaction(function () use ($runId, $revision, $delay, $exception, $reason): bool {
                    $locked = SyncRun::query()->lockForUpdate()->findOrFail($runId);
                    if ($locked->status !== 'running' || $locked->revision !== $revision) {
                        return false;
                    }
                    $locked->update([
                        'revision' => $revision + 1, 'next_attempt_at' => now()->addSeconds($delay), 'heartbeat_at' => now(),
                        'retry_count' => $locked->retry_count + 1, 'error_count' => $locked->error_count + 1,
                    ]);
                    $this->journal->event($locked, $reason, 'error', ['http' => $exception->httpStatus, 'page' => $locked->checkpoint['page'] ?? 1]);
                    $this->journal->event($locked, 'retry_scheduled', 'warning', ['seconds' => $delay, 'attempt' => ($locked->checkpoint['attempt'] ?? 0) + 1]);

                    return true;
                });

                return $saved ? $delay : null;
            }
            $this->fail($runId, $reason, $exception instanceof DirectoryException ? $exception->httpStatus : null);
            // Safe domain failure reaches failed_jobs; raw upstream exceptions never do.
            throw new DirectoryException($reason);
        }
    }

    private function publish(SyncRun $run, int $revision): ?int
    {
        DB::transaction(function () use ($run, $revision): void {
            $settings = Settings::query()->lockForUpdate()->findOrFail(1);
            $locked = SyncRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'running' || $locked->revision !== $revision) {
                throw new DirectoryException('obsolete_job');
            }
            $counts = [];
            foreach (Tables::directories() as $directory) {
                $counts[$directory] = DB::table(Tables::name($directory))->where('generation_id', $locked->generation_id)->count();
            }
            $settings->update(['active_generation_id' => $locked->generation_id, 'last_success_at' => now()]);
            DB::table(Tables::name('generations'))->where('id', $locked->generation_id)->update(['status' => 'published', 'updated_at' => now()]);
            $locked->update(['status' => 'completed', 'stage' => 'completed', 'counts' => $counts, 'finished_at' => now(), 'heartbeat_at' => now(), 'next_attempt_at' => null]);
            $this->journal->event($locked, 'sync_published');
        });

        return null;
    }

    private function validateBatch(SyncRun $run, int $revision): int
    {
        $part = (int) ($run->checkpoint['validation'] ?? 0);
        $cursor = (int) ($run->checkpoint['cursor'] ?? 0);
        $updates = [];
        if ($part < 8) {
            $this->validate($run->generation_id, relation: $part, warehouses: false);
        } else {
            $rows = DB::table(Tables::name('warehouses'))->where('generation_id', $run->generation_id)->where('id', '>', $cursor)->orderBy('id')->limit(200)->get();
            $updates = $this->warehouseFilters($run->generation_id, $rows);
            $cursor = (int) ($rows->last()?->id ?? $cursor);
        }
        DB::transaction(function () use ($run, $revision, $part, $cursor, $updates): void {
            $locked = SyncRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'running' || $locked->revision !== $revision) {
                throw new DirectoryException('obsolete_job');
            }
            if ($part === 0) {
                $this->journal->event($locked, 'validation_started');
            }
            if ($updates) {
                DB::table(Tables::name('warehouses'))->upsert($updates, ['generation_id', 'ref'], ['area_ref', 'district_ref']);
            }
            $done = $part >= 8 && $updates === [];
            $locked->update([
                'checkpoint' => [...$locked->checkpoint, 'attempt' => 0, 'validation' => min(8, $part + 1), 'cursor' => $cursor, 'stage' => $done ? 6 : 5],
                'stage' => $done ? 'publication' : 'validation', 'revision' => $revision + 1, 'heartbeat_at' => now(),
            ]);
            $this->journal->event($locked, $part < 8 ? 'relations_checked' : 'validation_batch', context: ['records' => count($updates)]);
        });

        return 0;
    }

    public function fail(int $runId, string $reason, ?int $httpStatus = null): void
    {
        DB::transaction(function () use ($runId, $reason, $httpStatus): void {
            $run = SyncRun::query()->lockForUpdate()->find($runId);
            if (! $run || ! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $run->update(['status' => 'failed', 'error' => $reason, 'error_count' => $run->error_count + 1, 'finished_at' => now(), 'next_attempt_at' => null]);
            DB::table(Tables::name('generations'))->where('id', $run->generation_id)->where('status', 'building')->update(['status' => 'failed', 'updated_at' => now()]);
            $this->journal->event($run, $reason, 'error', ['http' => $httpStatus, 'page' => $run->checkpoint['page'] ?? 1]);
            $this->journal->event($run, 'restart_needed', 'warning');
        });
    }

    private function territories(int $generation, array $rows): void
    {
        foreach ($rows as $row) {
            if (! empty($row['Area'])) {
                $area = $this->normalizer->row('areas', ['Ref' => $row['Area'], 'Description' => $row['AreaDescription'] ?? '', 'DescriptionRu' => $row['AreaDescriptionRu'] ?? ''], $generation);
                $area['type'] = 'territorial';
                DB::table(Tables::name('areas'))->upsert([$area], ['generation_id', 'ref'], ['name', 'name_ru', 'search_text', 'type', 'payload']);
            }
            if (! empty($row['Region'])) {
                $district = $this->normalizer->row('districts', ['Ref' => $row['Region'], 'Area' => $row['Area'] ?? null, 'Description' => $row['RegionsDescription'] ?? '', 'DescriptionRu' => $row['RegionsDescriptionRu'] ?? ''], $generation);
                DB::table(Tables::name('districts'))->upsert([$district], ['generation_id', 'ref'], ['name', 'name_ru', 'search_text', 'area_ref', 'payload']);
            }
        }
    }

    private function mappings(int $generation, array $rows): void
    {
        foreach ($rows as $row) {
            if (empty($row['CityRef']) || empty($row['SettlementRef'])) {
                throw new DirectoryException('missing_address_mapping');
            }
            $mapping = $this->normalizer->row('city_mappings', [
                'Ref' => hash('sha256', $row['CityRef'].':'.$row['SettlementRef']),
                'CityRef' => $row['CityRef'], 'SettlementRef' => $row['SettlementRef'],
            ], $generation);
            DB::table(Tables::name('city_mappings'))->upsert([$mapping], ['generation_id', 'ref'], ['city_ref', 'settlement_ref']);
        }
    }

    public function validate(int $generation, ?callable $heartbeat = null, ?int $relation = null, bool $warehouses = true): void
    {
        foreach ([
            ['settlements', 'area_ref', 'areas'], ['settlements', 'district_ref', 'districts'],
            ['cities', 'area_ref', 'areas'], ['warehouses', 'city_ref', 'cities'],
            ['warehouses', 'settlement_ref', 'settlements'], ['warehouses', 'type_ref', 'warehouse_types'],
            ['city_mappings', 'city_ref', 'cities'], ['city_mappings', 'settlement_ref', 'settlements'],
        ] as $index => [$child, $column, $parent]) {
            if ($relation !== null && $relation !== $index) {
                continue;
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $parentTable = Tables::name($parent);
            $childTable = Tables::name($child);
            $invalid = DB::table($childTable)->where('generation_id', $generation)->whereNotNull($column)
                ->whereNotExists(function (Builder $query) use ($parentTable, $childTable, $column): void {
                    $query->selectRaw('1')->from($parentTable)
                        ->whereColumn($parentTable.'.generation_id', $childTable.'.generation_id')
                        ->whereColumn($parentTable.'.ref', $childTable.'.'.$column);
                })->exists();
            if ($invalid) {
                throw new DirectoryException('broken_directory_relationship');
            }
        }
        if (! $warehouses) {
            return;
        }
        // Copy canonical territorial filters to warehouses using the exact SettlementRef.
        DB::table(Tables::name('warehouses'))->where('generation_id', $generation)->orderBy('id')->chunkById(200, function ($rows) use ($generation, $heartbeat): void {
            $updates = $this->warehouseFilters($generation, $rows);
            DB::table(Tables::name('warehouses'))->upsert($updates, ['generation_id', 'ref'], ['area_ref', 'district_ref']);
            if ($heartbeat !== null) {
                $heartbeat();
            }
        });
    }

    private function warehouseFilters(int $generation, Collection $rows): array
    {
        $settlements = DB::table(Tables::name('settlements'))->where('generation_id', $generation)
            ->whereIn('ref', $rows->pluck('settlement_ref'))->get()->keyBy('ref');
        $updates = [];
        foreach ($rows as $row) {
            $settlement = $settlements->get($row->settlement_ref);
            if ($settlement === null) {
                throw new DirectoryException('broken_directory_relationship');
            }
            $updates[] = [...(array) $row, 'area_ref' => $settlement->area_ref, 'district_ref' => $settlement->district_ref];
        }

        return $updates;
    }
}
