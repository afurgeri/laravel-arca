<?php

return [
    'cuit' => env('ARCA_CUIT'),
    'access_token' => env('ARCA_ACCESS_TOKEN'),
    'certificate_path' => env('ARCA_CERTIFICATE_PATH'),
    'private_key_path' => env('ARCA_PRIVATE_KEY_PATH'),
    'production' => (bool) env('ARCA_PRODUCTION', false),
    'table_prefix' => env('ARCA_TABLE_PREFIX', 'arca_'),
    'route_prefix' => env('ARCA_ROUTE_PREFIX', 'api/arca'),
    'route_middleware' => ['web', 'auth', 'verified'],
];
