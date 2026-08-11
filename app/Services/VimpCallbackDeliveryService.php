<?php

namespace App\Services;

use App\Exceptions\VimpCallbackDeliveryException;
use App\Models\User;
use App\Models\VimpCallback;
use Illuminate\Support\Facades\Log;

class VimpCallbackDeliveryService
{
    private $client;

    public function __construct(VimpCallbackHttpClient $client)
    {
        $this->client = $client;
    }

    public function deliver(VimpCallback $callback): void
    {
        $callback->refresh();

        if ($callback->status === VimpCallback::STATUS_SENT) {
            return;
        }

        $user = User::find($callback->user_id);

        if (!$user) {
            $this->recordFailure($callback, null, null, 'ViMP callback user no longer exists');
            throw new VimpCallbackDeliveryException('ViMP callback user no longer exists');
        }

        $callback->update([
            'status' => VimpCallback::STATUS_SENDING,
            'attempts' => $callback->attempts + 1,
            'last_error' => null,
        ]);

        $result = $this->client->send($user, $callback->payload);
        $statusCode = $result['status_code'];
        $successful = $statusCode !== null && $statusCode >= 200 && $statusCode < 300;

        Log::log($successful ? 'info' : 'warning', 'ViMP callback delivery result', [
            'callback_id' => $callback->id,
            'mediakey' => $callback->mediakey,
            'type' => $callback->type,
            'attempt' => $callback->attempts,
            'status_code' => $statusCode,
            'response' => $result['response'],
            'error' => $result['error'],
            'duration_ms' => $result['duration_ms'],
        ]);

        if ($successful) {
            $callback->update([
                'status' => VimpCallback::STATUS_SENT,
                'last_status_code' => $statusCode,
                'last_response' => $result['response'],
                'last_error' => null,
                'next_attempt_at' => null,
                'sent_at' => now(),
            ]);

            return;
        }

        $message = $result['error'] ?: 'ViMP callback returned HTTP ' . ($statusCode ?: 'transport error');
        $this->recordFailure($callback, $statusCode, $result['response'], $message);

        throw new VimpCallbackDeliveryException($message, (int) ($statusCode ?: 0));
    }

    private function recordFailure(VimpCallback $callback, ?int $statusCode, ?string $response, string $message): void
    {
        $callback->update([
            'status' => VimpCallback::STATUS_QUEUED,
            'last_status_code' => $statusCode,
            'last_response' => $response,
            'last_error' => $message,
            'next_attempt_at' => now()->addSeconds((int) config('vimp_callbacks.retry_backoff_seconds', 60)),
            'sent_at' => null,
        ]);
    }
}
