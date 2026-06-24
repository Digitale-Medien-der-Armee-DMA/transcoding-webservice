<?php

namespace App\Jobs\Concerns;

use App\Services\GpuMemoryGuard;
use Illuminate\Support\Facades\Log;

trait GuardsVideoWorker
{
    protected function guardVideoWorker($job): bool
    {
        $decision = app(GpuMemoryGuard::class)->check();

        if ($decision['allowed']) {
            return true;
        }

        $delay = (int) config('workers.gpu_guard.retry_delay_seconds', 60);

        Log::warning('Video job delayed by GPU guardrail', [
            'job' => static::class,
            'delay_seconds' => $delay,
            'decision' => $decision,
        ]);

        if ($job && method_exists($job, 'release')) {
            $job->release($delay);
        }

        return false;
    }
}
