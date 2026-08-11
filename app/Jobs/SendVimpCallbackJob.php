<?php

namespace App\Jobs;

use App\Models\VimpCallback;
use App\Services\VimpCallbackDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendVimpCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $callback;

    public $deleteWhenMissingModels = true;

    public function __construct(VimpCallback $callback)
    {
        $this->callback = $callback;
    }

    public function handle(VimpCallbackDeliveryService $delivery): void
    {
        $delivery->deliver($this->callback);
    }

    public function backoff(): int
    {
        return (int) config('vimp_callbacks.retry_backoff_seconds', 60);
    }

    public function failed(Throwable $exception): void
    {
        $this->callback->refresh();

        if ($this->callback->status === VimpCallback::STATUS_SENT) {
            return;
        }

        $this->callback->update([
            'status' => VimpCallback::STATUS_FAILED,
            'last_error' => $exception->getMessage(),
            'next_attempt_at' => now()->addSeconds((int) config('vimp_callbacks.failed_retry_seconds', 600)),
        ]);
    }
}
