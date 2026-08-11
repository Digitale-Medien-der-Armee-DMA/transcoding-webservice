<?php

return [
    'queue' => env('WORKER_CALLBACK_QUEUE', 'callback'),
    'connect_timeout_seconds' => env('VIMP_CALLBACK_CONNECT_TIMEOUT_SECONDS', 10),
    'timeout_seconds' => env('VIMP_CALLBACK_TIMEOUT_SECONDS', 120),
    'retry_backoff_seconds' => env('VIMP_CALLBACK_RETRY_BACKOFF_SECONDS', 60),
    'failed_retry_seconds' => env('VIMP_CALLBACK_FAILED_RETRY_SECONDS', 600),
    'stale_after_seconds' => env('VIMP_CALLBACK_STALE_AFTER_SECONDS', 900),
    'max_response_bytes' => env('VIMP_CALLBACK_MAX_RESPONSE_BYTES', 2048),
    'dispatch_batch_size' => env('VIMP_CALLBACK_DISPATCH_BATCH_SIZE', 100),
    'artifact_base_url' => env('VIMP_ARTIFACT_BASE_URL'),
    'label_map' => env('VIMP_CALLBACK_LABEL_MAP'),
    'medium_fields' => env('VIMP_CALLBACK_MEDIUM_FIELDS'),
    'include_properties' => env('VIMP_CALLBACK_INCLUDE_PROPERTIES', true),
];
