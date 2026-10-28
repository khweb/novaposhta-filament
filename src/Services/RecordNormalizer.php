<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Services;

use NovaPoshta\Filament\Exceptions\DirectoryException;

final class RecordNormalizer
{
    public function row(string $directory, array $source, int $generation): array
    {
        if (trim((string) ($source['Description'] ?? '')) === '' && $directory !== 'city_mappings') {
            throw new DirectoryException('upstream_invalid_response');
        }
        $required = match ($directory) {
            'settlements', 'cities' => ['Area'],
            'warehouses' => ['CityRef', 'SettlementRef', 'TypeOfWarehouse'],
            default => [],
        };
        foreach ($required as $field) {
            if ($this->ref($source[$field] ?? null) === null) {
                throw new DirectoryException('missing_address_mapping');
            }
        }

        return [
            'generation_id' => $generation,
            'ref' => $source['Ref'],
            'name' => mb_substr((string) ($source['Description'] ?? ''), 0, 255),
            'name_ru' => isset($source['DescriptionRu']) ? mb_substr($source['DescriptionRu'], 0, 255) : null,
            'search_text' => mb_strtolower((string) ($source['Description'] ?? '').' '.($source['DescriptionRu'] ?? '')),
            'area_ref' => $this->ref($source['Area'] ?? $source['AreaRef'] ?? null),
            'district_ref' => $this->ref($source['Region'] ?? null),
            'settlement_ref' => $this->ref($source['SettlementRef'] ?? null),
            'city_ref' => $this->ref($source['CityRef'] ?? null),
            'type_ref' => $this->ref($source['TypeOfWarehouse'] ?? null),
            'type' => match ($directory) {
                'areas' => 'company',
                'warehouses' => $this->warehouseType($source),
                default => null,
            },
            'payload' => json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function ref(mixed $value): ?string
    {
        return ! is_string($value) || trim($value) === '' || $value === '00000000-0000-0000-0000-000000000000' ? null : $value;
    }

    public function warehouseType(array $row): string
    {
        return in_array($row['TypeOfWarehouse'] ?? '', config('nova-poshta.parcel_locker_type_refs', []), true)
            || ($row['CategoryOfWarehouse'] ?? '') === 'Postomat'
            || ! empty($row['PostMachineType'])
            ? 'parcel_locker' : 'branch';
    }
}
