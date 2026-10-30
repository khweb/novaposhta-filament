<?php

return [
    'table_prefix' => env('NOVA_POSHTA_TABLE_PREFIX', 'api_nova_poshta_'),
    'endpoint' => 'https://api.novaposhta.ua/v2.0/json/',
    'queue' => env('NOVA_POSHTA_QUEUE', 'default'),
    'http' => ['timeout' => 20, 'attempts' => 3, 'retry_ms' => 500, 'per_minute' => 120],
    'sync' => ['page_size' => 150, 'max_pages' => 10000, 'stale_seconds' => 900, 'timeout' => 120],
    'rest' => [
        'enabled' => env('NOVA_POSHTA_REST_ENABLED', true),
        'prefix' => 'api/nova-poshta/v1',
        'middleware' => ['api'],
    ],
    'parcel_locker_type_refs' => ['f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc'],
];
