<?php

return [
    'driver' => env('ARCA_DRIVER', 'soap'),
    'cuit' => env('ARCA_CUIT'),
    'access_token' => env('ARCA_ACCESS_TOKEN'),
    'certificate_path' => env('ARCA_CERTIFICATE_PATH'),
    'private_key_path' => env('ARCA_PRIVATE_KEY_PATH'),
    'certificates' => [
        'testing' => [
            'certificate_path' => env('ARCA_TESTING_CERTIFICATE_PATH'),
            'private_key_path' => env('ARCA_TESTING_PRIVATE_KEY_PATH'),
        ],
        'production' => [
            'certificate_path' => env('ARCA_PRODUCTION_CERTIFICATE_PATH'),
            'private_key_path' => env('ARCA_PRODUCTION_PRIVATE_KEY_PATH'),
        ],
    ],
    'production' => (bool) env('ARCA_PRODUCTION', false),
    'timeout' => (int) env('ARCA_TIMEOUT', 20),
    'connect_timeout' => (int) env('ARCA_CONNECT_TIMEOUT', 5),
    'ssl_cipher_list' => env('ARCA_SSL_CIPHER_LIST', 'ECDHE+AESGCM'),
    'ticket_cache_store' => env('ARCA_TICKET_CACHE_STORE'),
    'ticket_cache_safety_seconds' => (int) env('ARCA_TICKET_CACHE_SAFETY_SECONDS', 300),
    'endpoints' => [
        'testing' => [
            'wsaa' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            'wsfe' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
        ],
        'production' => [
            'wsaa' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            'wsfe' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
        ],
    ],
    'table_prefix' => env('ARCA_TABLE_PREFIX', 'arca_'),
    'route_prefix' => env('ARCA_ROUTE_PREFIX', 'api/arca'),
    'route_middleware' => ['web', 'auth', 'verified'],
];
