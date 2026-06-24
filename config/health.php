<?php

return [
    'worker_stale_after_seconds' => env('HEALTH_WORKER_STALE_AFTER_SECONDS', 300),
    'redis_required' => env('HEALTH_REDIS_REQUIRED', false),
    'ffmpeg_binaries' => [
        env('FFMPEG_BINARIES', 'ffmpeg'),
        env('FFPROBE_BINARIES', 'ffprobe'),
    ],
    'storage_disks' => [
        'uploaded',
        'converted',
    ],
    'queue_names' => [
        'download',
        'video',
        'default',
    ],
];
