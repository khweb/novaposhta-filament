<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use NovaPoshta\Filament\Models\DirectoryRecord;
use NovaPoshta\Filament\Support\Lang;
use NovaPoshta\Filament\Support\Tables;

final class DirectoryBrowser
{
    public const DIRECTORIES = ['areas', 'districts', 'settlements', 'warehouses', 'warehouse-types'];

    public function parents(string $directory): array
    {
        return match ($directory) {
            'districts' => ['area_ref' => 'areas'],
            'settlements' => ['area_ref' => 'areas', 'district_ref' => 'districts'],
            'warehouses' => ['area_ref' => 'areas', 'district_ref' => 'districts', 'settlement_ref' => 'settlements', 'type_ref' => 'warehouse-types'],
            default => [],
        };
    }

    public function query(string $directory, ?int $generation): Builder
    {
        if (! in_array($directory, self::DIRECTORIES, true)) {
            throw new \InvalidArgumentException('Unknown directory');
        }
        if ($generation === null) {
            return (new DirectoryRecord)->inDirectory(str_replace('-', '_', $directory))->newQuery()->whereRaw('1 = 0');
        }
        $query = app(DirectorySearch::class)->query($directory, generation: $generation);
        $table = $query->getModel()->getTable();
        $query->select($table.'.*');
        foreach ($this->parents($directory) as $column => $parent) {
            $parentTable = Tables::name(str_replace('-', '_', $parent));
            $query->selectSub(DB::table($parentTable)->select('name')
                ->whereColumn($parentTable.'.generation_id', $table.'.generation_id')
                ->whereColumn($parentTable.'.ref', $table.'.'.$column)->limit(1), 'parent_'.$column);
        }

        return $query;
    }

    public function counts(?int $generation): array
    {
        $counts = [];
        foreach (self::DIRECTORIES as $directory) {
            $counts[$directory] = $this->query($directory, $generation)->count();
        }

        return $counts;
    }

    public function filter(Builder $query, string $directory, array $data): Builder
    {
        $rules = [];
        foreach ($this->parents($directory) as $column => $parent) {
            $rules[$column] = ['nullable', 'string', 'max:64'];
        }
        if ($directory === 'warehouses') {
            $rules['type'] = ['nullable', Rule::in(['branch', 'parcel_locker'])];
        }
        foreach (Validator::make($data, $rules)->validate() as $column => $value) {
            if (filled($value)) {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    public function context(string $directory, DirectoryRecord $record): array
    {
        $context = [];
        foreach ($this->parents($directory) as $column => $parent) {
            if ($record->{$column}) {
                $context[$column] = $record->{'parent_'.$column} ?: 'Ref: '.$record->{$column};
            }
        }

        return $context;
    }

    public function options(string $directory, ?int $generation, array $filters = [], ?string $search = null): array
    {
        $query = $this->query($directory, $generation);
        foreach (array_intersect_key($filters, $this->parents($directory)) as $column => $value) {
            if (filled($value)) {
                $query->where($column, $value);
            }
        }
        if (filled($search)) {
            app(DirectorySearch::class)->applyTextSearch($query, $search);
        }

        return $query->limit(50)->get()->mapWithKeys(fn (DirectoryRecord $record): array => [
            $record->ref => $this->optionLabel($directory, $record),
        ])->all();
    }

    public function label(string $directory, ?int $generation, ?string $ref): ?string
    {
        if (! filled($ref)) {
            return null;
        }
        $record = $this->query($directory, $generation)->where('ref', $ref)->first();

        return $record ? $this->optionLabel($directory, $record) : $ref;
    }

    private function optionLabel(string $directory, DirectoryRecord $record): string
    {
        return implode(' · ', [$record->name, ...array_values($this->context($directory, $record)), 'Ref: '.$record->ref]);
    }

    public function details(DirectoryRecord $record): array
    {
        $payload = $record->payload ?? [];
        $schedule = [];
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $value = $payload['Schedule'][$day] ?? null;
            if (is_array($value)) {
                $value = implode(' · ', array_filter($value, fn ($part): bool => is_scalar($part)));
            }
            if (is_scalar($value) && (string) $value !== '') {
                $schedule[Lang::get('directory_'.strtolower($day))] = (string) $value;
            }
        }

        return [
            'address' => is_string($payload['ShortAddress'] ?? null) ? $payload['ShortAddress'] : null,
            'coordinates' => is_numeric($payload['Latitude'] ?? null) && is_numeric($payload['Longitude'] ?? null)
                ? implode(', ', array_map(fn ($value): string => rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.'), [$payload['Latitude'], $payload['Longitude']])) : null,
            'total_weight' => is_numeric($payload['TotalMaxWeightAllowed'] ?? null) && $payload['TotalMaxWeightAllowed'] > 0 ? $payload['TotalMaxWeightAllowed'] : null,
            'place_weight' => is_numeric($payload['PlaceMaxWeightAllowed'] ?? null) && $payload['PlaceMaxWeightAllowed'] > 0 ? $payload['PlaceMaxWeightAllowed'] : null,
            'schedule' => $schedule,
        ];
    }
}
