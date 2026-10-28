<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Models\SyncRun;
use NovaPoshta\Filament\Support\Tables;

final class Synchronizer
{
    public function __construct(private readonly DirectoryDownloader $downloader, private readonly RecordNormalizer $normalizer) {}

    public function run(int $runId): void
    {
        if (SyncRun::query()->whereKey($runId)->value('status') !== 'running') {
            return;
        }
        $generation = DB::table(Tables::name('generations'))->insertGetId(['status' => 'building', 'created_at' => now(), 'updated_at' => now()]);
        SyncRun::query()->whereKey($runId)->update(['generation_id' => $generation]);
        $counts = [];
        try {
            foreach (['areas' => 'getAreas', 'settlements' => 'getSettlements', 'cities' => 'getCities', 'warehouse_types' => 'getWarehouseTypes', 'warehouses' => 'getWarehouses'] as $directory => $method) {
                $counts[$directory] = 0;
                $this->heartbeat($runId, $directory, $counts);
                foreach ($this->downloader->pages($method) as $rows) {
                    $this->heartbeat($runId, $directory, $counts);
                    $normalized = array_map(fn (array $row): array => $this->normalizer->row($directory, $row, $generation), $rows);
                    foreach (array_chunk($normalized, 50) as $chunk) {
                        DB::table(Tables::name($directory))->insert($chunk);
                    }
                    if ($directory === 'settlements') {
                        $this->territories($generation, $rows);
                    }
                    if ($directory === 'warehouses') {
                        $this->mappings($generation, $rows);
                    }
                    $counts[$directory] += count($rows);
                    $this->heartbeat($runId, $directory, $counts);
                }
            }
            foreach (Tables::directories() as $directory) {
                $counts[$directory] = DB::table(Tables::name($directory))->where('generation_id', $generation)->count();
            }
            $this->heartbeat($runId, 'validation', $counts);
            $this->validate($generation, fn () => $this->heartbeat($runId, 'validation', $counts));
            DB::transaction(function () use ($runId, $generation, $counts): void {
                $settings = Settings::query()->lockForUpdate()->findOrFail(1);
                $run = SyncRun::query()->lockForUpdate()->findOrFail($runId);
                if ($run->status !== 'running') {
                    throw new DirectoryException('obsolete_job');
                }
                $settings->active_generation_id = $generation;
                $settings->save();
                DB::table(Tables::name('generations'))->where('id', $generation)->update(['status' => 'published', 'updated_at' => now()]);
                $run->update(['status' => 'completed', 'stage' => 'completed', 'counts' => $counts, 'heartbeat_at' => now(), 'finished_at' => now()]);
            });
        } catch (\Throwable $exception) {
            DB::table(Tables::name('generations'))->where('id', $generation)->update(['status' => 'failed', 'updated_at' => now()]);
            SyncRun::query()->whereKey($runId)->where('status', 'running')->update([
                'status' => 'failed', 'counts' => $counts, 'error' => $exception instanceof DirectoryException ? $exception->reason : 'synchronization_failed', 'finished_at' => now(),
            ]);
            // Only safe, package-controlled error codes reach queue logs.
        }
    }

    private function heartbeat(int $runId, string $stage, array $counts): void
    {
        if (SyncRun::query()->whereKey($runId)->where('status', 'running')->update([
            'stage' => $stage, 'counts' => json_encode($counts, JSON_THROW_ON_ERROR), 'heartbeat_at' => now(),
        ]) === 0 && ! SyncRun::query()->whereKey($runId)->where('status', 'running')->exists()) {
            throw new DirectoryException('obsolete_job');
        }
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

    public function validate(int $generation, ?callable $heartbeat = null): void
    {
        foreach ([
            ['settlements', 'area_ref', 'areas'], ['settlements', 'district_ref', 'districts'],
            ['cities', 'area_ref', 'areas'], ['warehouses', 'city_ref', 'cities'],
            ['warehouses', 'settlement_ref', 'settlements'], ['warehouses', 'type_ref', 'warehouse_types'],
            ['city_mappings', 'city_ref', 'cities'], ['city_mappings', 'settlement_ref', 'settlements'],
        ] as [$child, $column, $parent]) {
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
        // Copy canonical territorial filters to warehouses using the exact SettlementRef.
        DB::table(Tables::name('warehouses'))->where('generation_id', $generation)->orderBy('id')->chunkById(200, function ($rows) use ($generation, $heartbeat): void {
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
            DB::table(Tables::name('warehouses'))->upsert($updates, ['generation_id', 'ref'], ['area_ref', 'district_ref']);
            if ($heartbeat !== null) {
                $heartbeat();
            }
        });
    }
}
