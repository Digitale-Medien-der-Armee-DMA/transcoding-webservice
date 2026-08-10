<?php

namespace Tests\Fixtures;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class MissingModelRedisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deleteWhenMissingModels = true;

    public $user;

    private $key;

    public function __construct(User $user, string $key)
    {
        $this->user = $user;
        $this->key = $key;
    }

    public function handle(): void
    {
        Redis::set($this->key, 'unexpectedly-handled');
    }
}
