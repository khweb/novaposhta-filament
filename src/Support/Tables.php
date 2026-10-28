<?php

declare(strict_types=1);

namespace NovaPoshta\Filament\Support;

final class Tables
{
    public static function name(string $suffix): string
    {
        $prefix = (string) config('nova-poshta.table_prefix', 'api_nova_poshta_');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,31}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid Nova Poshta table prefix (1–32 identifier characters).');
        }

        return $prefix.$suffix;
    }

    public static function directories(): array
    {
        return ['areas', 'districts', 'settlements', 'cities', 'city_mappings', 'warehouse_types', 'warehouses'];
    }
}
