<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class FailingRedisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function handle(): void
    {
        throw new RuntimeException('redis-integration-failure');
    }

    public function failed(Throwable $exception): void
    {
        Redis::set($this->key, $exception->getMessage());
    }
}
