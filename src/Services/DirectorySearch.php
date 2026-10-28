<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use NovaPoshta\Filament\Exceptions\DirectoryException;
use NovaPoshta\Filament\Models\DirectoryRecord;
use NovaPoshta\Filament\Models\Settings;
use NovaPoshta\Filament\Support\Tables;

final class DirectorySearch
{
    public const DIRECTORIES = ['areas', 'districts', 'settlements', 'warehouses', 'warehouse-types', 'streets'];

    public function snapshot(): int
    {
        $id = Settings::current()->active_generation_id;
        if ($id === null) {
            throw new DirectoryException('directory_not_ready');
        }

        return (int) $id;
    }

    public function filters(string $directory, array $filters): array
    {
        if (! in_array($directory, self::DIRECTORIES, true)) {
            throw ValidationException::withMessages(['directory' => 'Unknown directory.']);
        }

        if (isset($filters['q']) && is_string($filters['q'])) {
            $filters['q'] = trim($filters['q']);
        }

        return Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:100', ...($directory === 'streets' ? ['required', 'min:3'] : [])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'area_ref' => ['nullable', 'string', 'max:64', Rule::prohibitedIf(! in_array($directory, ['districts', 'settlements', 'warehouses'], true))],
            'district_ref' => ['nullable', 'string', 'max:64', Rule::prohibitedIf(! in_array($directory, ['settlements', 'warehouses'], true))],
            'settlement_ref' => ['nullable', 'string', 'max:64', Rule::prohibitedIf(! in_array($directory, ['warehouses', 'streets'], true)), ...($directory === 'streets' ? ['required'] : [])],
            'type' => ['nullable', Rule::in(['branch', 'parcel_locker']), Rule::prohibitedIf($directory !== 'warehouses')],
        ])->validate();
    }

    public function query(string $directory, array $filters = [], ?int $generation = null): Builder
    {
        $filters = $this->filters($directory, $filters);
        $generation ??= $this->snapshot();
        $table = str_replace('-', '_', $directory);
        if ($table === 'streets') {
            throw new \InvalidArgumentException('Use search() for streets.');
        }
        $builder = (new DirectoryRecord)->inDirectory($table)->newQuery()->where('generation_id', $generation);
        if ($directory === 'areas') {
            $builder->where('type', 'territorial');
        }
        foreach (['area_ref', 'district_ref', 'settlement_ref', 'type'] as $column) {
            if (! empty($filters[$column])) {
                $builder->where($column, $filters[$column]);
            }
        }
        if (trim((string) ($filters['q'] ?? '')) !== '') {
            // Store Unicode-folded search text for consistent behavior across databases.

            $this->applyTextSearch($builder, $filters['q']);
        }

        return $builder->orderBy('name')->orderBy('ref');
    }

    public function applyTextSearch(Builder $query, string $term): Builder
    {
        Validator::make(['q' => $term], ['q' => ['string', 'max:100']])->validate();

        return $query->where('search_text', 'like', '%'.mb_strtolower(trim($term)).'%');
    }

    public function search(string $directory, array $filters = []): LengthAwarePaginator
    {
        $filters = $this->filters($directory, $filters);
        $generation = $this->snapshot();
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        if ($directory === 'streets') {
            if (! DB::table(Tables::name('settlements'))->where('generation_id', $generation)->where('ref', $filters['settlement_ref'])->exists()) {
                throw ValidationException::withMessages(['settlement_ref' => 'Unknown settlement.']);
            }
            $rows = app(StreetService::class)->search($generation, $filters['settlement_ref'], $filters['q']);
            $result = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
        } else {
            $result = $this->query($directory, $filters, $generation)->paginate($perPage, ['*'], 'page', $page);
            $result->setCollection($result->getCollection()->map(fn (DirectoryRecord $row): array => $this->present($row)));
        }
        $result->withPath(LengthAwarePaginator::resolveCurrentPath())->appends($filters);
        // Snapshot metadata is carried by the caller separately when required.

        return $result;
    }

    public function present(DirectoryRecord $row): array
    {
        $payload = $row->payload;

        return [
            'ref' => $row->ref, 'name' => $row->name, 'name_ru' => $row->name_ru,
            'area_ref' => $row->area_ref, 'district_ref' => $row->district_ref,
            'settlement_ref' => $row->settlement_ref, 'city_ref' => $row->city_ref,
            'type_ref' => $row->type_ref, 'type' => $row->type,
            'address' => $payload['ShortAddress'] ?? null,
            'latitude' => isset($payload['Latitude']) ? (float) $payload['Latitude'] : null,
            'longitude' => isset($payload['Longitude']) ? (float) $payload['Longitude'] : null,
            'schedule' => $payload['Schedule'] ?? null,
            'limits' => array_intersect_key($payload, array_flip(['TotalMaxWeightAllowed', 'PlaceMaxWeightAllowed', 'SendingLimitationsOnDimensions', 'ReceivingLimitationsOnDimensions', 'MaxDeclaredCost'])),
        ];
    }
}
