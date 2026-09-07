<?php

declare(strict_types=1);

return [
    'discovery_url' => env(
        'EIX_DISCOVERY_URL',
        'https://european-investor-exchange.com/api/trade-files?tradeFileType=pretrade',
    ),

    'download_url' => env(
        'EIX_DOWNLOAD_URL',
        'https://european-investor-exchange.com/api/trade-file-contents',
    ),

    'http' => [
        'connect_timeout' => (int) env('EIX_HTTP_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('EIX_HTTP_TIMEOUT', 30),
        'download_timeout' => (int) env('EIX_HTTP_DOWNLOAD_TIMEOUT', 3600),
        'retries' => (int) env('EIX_HTTP_RETRIES', 3),
        'retry_delay_ms' => (int) env('EIX_HTTP_RETRY_DELAY_MS', 1000),
    ],

    'database' => [
        'connection' => env('EIX_DB_CONNECTION'),
    ],

    'storage' => [
        'disk' => env('EIX_STORAGE_DISK', 'local'),
        'prefix' => env('EIX_STORAGE_PREFIX', 'eix'),
    ],

    'import' => [
        'batch_size' => (int) env('EIX_IMPORT_BATCH_SIZE', 1000),
        'lock_store' => env('EIX_IMPORT_LOCK_STORE'),
        'lock_name' => env('EIX_IMPORT_LOCK_NAME', 'eix-pricing:import'),
        'lock_seconds' => (int) env('EIX_IMPORT_LOCK_SECONDS', 7200),
    ],

    'schedule' => [
        'enabled' => (bool) env('EIX_SCHEDULE_ENABLED', true),
        'cron' => env('EIX_SCHEDULE_CRON', '*/15 * * * *'),
        'overlap_minutes' => (int) env('EIX_SCHEDULE_OVERLAP_MINUTES', 180),
        'on_one_server' => (bool) env('EIX_SCHEDULE_ON_ONE_SERVER', false),
    ],

    'routes' => [
        'enabled' => (bool) env('EIX_ROUTES_ENABLED', true),
        'prefix' => env('EIX_ROUTES_PREFIX', 'api'),
        'middleware' => ['api'],
        'throttle' => env('EIX_ROUTES_THROTTLE', '60,1'),
    ],
];
