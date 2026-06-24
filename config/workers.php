<?php

return [
    'queues' => [
        'download' => env('WORKER_DOWNLOAD_QUEUE', 'download'),
        'video' => env('WORKER_VIDEO_QUEUE', 'video'),
    ],

    'heartbeat' => [
        'enabled' => env('WORKER_HEARTBEAT_ENABLED', true),
    ],

    'gpu_guard' => [
        'enabled' => env('GPU_GUARD_ENABLED', false),
        'nvidia_smi_binary' => env('NVIDIA_SMI_BINARY', 'nvidia-smi'),
        'min_free_mb' => env('GPU_GUARD_MIN_FREE_MB', 12288),
        'retry_delay_seconds' => env('GPU_GUARD_RETRY_DELAY_SECONDS', 60),
        'command_timeout_seconds' => env('GPU_GUARD_COMMAND_TIMEOUT_SECONDS', 5),
        'fail_open' => env('GPU_GUARD_FAIL_OPEN', false),
    ],
];
