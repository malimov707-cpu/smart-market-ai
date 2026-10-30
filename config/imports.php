<?php

return [
    'queue_connection' => 'database',
    'queue' => 'imports',
    'max_rows' => 1000,
    'max_columns' => 40,
    'max_bytes' => 5242880,
    'unconfirmed_retention_days' => 7,
    'scan_required' => env('IMPORT_SCAN_REQUIRED', false),
    'provider' => env('IMPORT_AI_PROVIDER', 'unavailable'),
    'model' => env('IMPORT_AI_MODEL'),
    'endpoint' => env('IMPORT_AI_ENDPOINT'),
    'key' => env('IMPORT_AI_KEY'),
    'timeout' => 20,
    'attempts' => 2,
    'output_bytes' => 2097152,
];
