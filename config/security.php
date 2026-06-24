<?php

return [
    'source_url' => [
        'allowlist_enabled' => env('SECURITY_SOURCE_URL_ALLOWLIST_ENABLED', false),
        'allowed_hosts' => array_filter(array_map('trim', explode(',', env('SECURITY_SOURCE_URL_ALLOWED_HOSTS', '')))),
        'allow_user_host' => env('SECURITY_SOURCE_URL_ALLOW_USER_HOST', true),
        'allowed_schemes' => ['http', 'https'],
    ],

    'downloads' => [
        'timeout_seconds' => env('SECURITY_DOWNLOAD_TIMEOUT_SECONDS', 300),
        'connect_timeout_seconds' => env('SECURITY_DOWNLOAD_CONNECT_TIMEOUT_SECONDS', 10),
        'max_bytes' => env('SECURITY_DOWNLOAD_MAX_BYTES', 0),
    ],

    'logs' => [
        'scrubbing_enabled' => env('SECURITY_LOG_SCRUBBING_ENABLED', true),
        'redacted_value' => '[redacted]',
        'sensitive_keys' => [
            'api_token',
            'token',
            'authorization',
            'password',
            'secret',
        ],
    ],
];
