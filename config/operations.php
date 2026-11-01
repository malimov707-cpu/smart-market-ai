<?php

return [
    'smtp_test_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_SMTP_TEST_RECIPIENTS', ''))))),
    'max_queue_age_seconds' => 300,
    'worker_heartbeat' => [
        'enabled' => (bool) env('OPS_WORKER_HEARTBEAT_ENABLED', false),
        'path' => env('OPS_WORKER_HEARTBEAT_PATH', storage_path('framework/import-worker-heartbeat.json')),
        'interval_seconds' => 10,
        'max_age_seconds' => 120,
    ],
];
