<?php

namespace NovaPoshta\Filament\Tests;

use NovaPoshta\Filament\Services\RecordNormalizer;

final class RecordNormalizerTest extends TestCase
{
    public function test_full_day_service_is_not_a_parcel_locker_type(): void
    {
        // Real API: Kyiv branch 400 has a nonempty PostMachineType.
        $source = [
            'Ref' => 'branch-400', 'Description' => 'Відділення №400',
            'CityRef' => 'city', 'SettlementRef' => 'settlement',
            'TypeOfWarehouse' => '841339c7-591a-42e2-8233-7a0a00f0ed6f',
            'CategoryOfWarehouse' => 'Branch', 'PostMachineType' => 'FullDayService',
        ];
        $row = app(RecordNormalizer::class)->row('warehouses', $source, 1);

        $this->assertSame('branch', $row['type']);
        $this->assertSame($source, json_decode($row['payload'], true));
    }

    public function test_parcel_lockers_are_identified_by_category_or_supported_type_ref(): void
    {
        $normalizer = app(RecordNormalizer::class);
        $this->assertSame('parcel_locker', $normalizer->warehouseType(['CategoryOfWarehouse' => 'Postomat']));
        foreach (['f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc'] as $ref) {
            $this->assertSame('parcel_locker', $normalizer->warehouseType(['TypeOfWarehouse' => $ref]));
        }
        $this->assertSame('branch', $normalizer->warehouseType(['PostMachineType' => 'FullDayService']));
    }
}
