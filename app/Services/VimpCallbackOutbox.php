<?php

namespace App\Services;

use App\Jobs\SendVimpCallbackJob;
use App\Models\Download;
use App\Models\Video;
use App\Models\VimpCallback;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class VimpCallbackOutbox
{
    public function enqueueForVideo(Video $video, string $type, array $payload): VimpCallback
    {
        return $this->enqueue([
            'user_id' => $video->user_id,
            'download_id' => $video->download_id,
            'video_id' => $video->id,
            'mediakey' => $video->mediakey,
            'type' => $type,
            'dedupe_key' => hash('sha256', $type . ':video:' . $video->id),
            'payload' => $payload,
        ]);
    }

    public function enqueueForDownload(Download $download, string $type, array $payload): VimpCallback
    {
        return $this->enqueue([
            'user_id' => $download->user_id,
            'download_id' => $download->id,
            'video_id' => null,
            'mediakey' => $download->mediakey,
            'type' => $type,
            'dedupe_key' => hash('sha256', $type . ':download:' . $download->id),
            'payload' => $payload,
        ]);
    }

    public function recordPreparationFailureForVideo(Video $video, string $type, Throwable $exception): VimpCallback
    {
        $callback = VimpCallback::firstOrCreate(
            ['dedupe_key' => hash('sha256', $type . ':video:' . $video->id)],
            [
                'user_id' => $video->user_id,
                'download_id' => $video->download_id,
                'video_id' => $video->id,
                'mediakey' => $video->mediakey,
                'type' => $type,
                'status' => VimpCallback::STATUS_PREPARATION_FAILED,
                'payload' => ['mediakey' => $video->mediakey],
                'attempts' => 0,
                'last_error' => 'Could not prepare ViMP callback: ' . $exception->getMessage(),
                'next_attempt_at' => null,
            ]
        );

        return $callback->fresh();
    }

    public function replay(VimpCallback $callback, bool $force = false): bool
    {
        $callback->refresh();

        if ($callback->status === VimpCallback::STATUS_SENT && !$force) {
            return false;
        }

        if ($callback->status === VimpCallback::STATUS_PREPARATION_FAILED) {
            return false;
        }

        $callback->update([
            'status' => VimpCallback::STATUS_PENDING,
            'last_status_code' => null,
            'last_response' => null,
            'last_error' => null,
            'next_attempt_at' => null,
            'sent_at' => null,
        ]);

        return $this->dispatch($callback);
    }

    public function replacePayloadAndReplay(VimpCallback $callback, array $payload, bool $force = false): bool
    {
        unset($payload['api_token']);

        $callback->update([
            'payload' => $payload,
            'status' => VimpCallback::STATUS_FAILED,
        ]);

        return $this->replay($callback, $force);
    }

    public function dispatchDue(): int
    {
        $staleBefore = now()->subSeconds((int) config('vimp_callbacks.stale_after_seconds', 900));

        VimpCallback::whereIn('status', [VimpCallback::STATUS_QUEUED, VimpCallback::STATUS_SENDING])
            ->where('updated_at', '<', $staleBefore)
            ->update(['status' => VimpCallback::STATUS_PENDING]);

        $callbacks = VimpCallback::where(function ($query) {
                $query->where(function ($pending) {
                        $pending->where('status', VimpCallback::STATUS_PENDING)
                            ->where(function ($due) {
                                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                            });
                    })
                    ->orWhere(function ($failed) {
                        $failed->where('status', VimpCallback::STATUS_FAILED)
                            ->where(function ($due) {
                                $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                            });
                    });
            })
            ->orderBy('id')
            ->limit((int) config('vimp_callbacks.dispatch_batch_size', 100))
            ->get();

        $dispatched = 0;

        foreach ($callbacks as $callback) {
            if ($this->dispatch($callback)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    private function enqueue(array $attributes): VimpCallback
    {
        unset($attributes['payload']['api_token']);

        $callback = VimpCallback::where('dedupe_key', $attributes['dedupe_key'])->first();
        $created = false;

        if (!$callback) {
            try {
                $callback = VimpCallback::create(array_merge($attributes, [
                    'status' => VimpCallback::STATUS_PENDING,
                    'attempts' => 0,
                ]));
                $created = true;
            } catch (QueryException $exception) {
                $callback = VimpCallback::where('dedupe_key', $attributes['dedupe_key'])->first();

                if (!$callback) {
                    throw $exception;
                }
            }
        }

        if ($created) {
            $this->dispatch($callback);
        }

        return $callback->fresh();
    }

    private function dispatch(VimpCallback $callback): bool
    {
        $claimed = VimpCallback::whereKey($callback->id)
            ->whereIn('status', [VimpCallback::STATUS_PENDING, VimpCallback::STATUS_FAILED])
            ->update([
                'status' => VimpCallback::STATUS_QUEUED,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            SendVimpCallbackJob::dispatch($callback->fresh())
                ->onQueue(config('vimp_callbacks.queue', 'callback'));

            return true;
        } catch (Throwable $exception) {
            $callback->refresh();

            if (!in_array($callback->status, [VimpCallback::STATUS_SENT, VimpCallback::STATUS_FAILED], true)) {
                $callback->update([
                    'status' => VimpCallback::STATUS_PENDING,
                    'last_error' => 'Could not dispatch ViMP callback: ' . $exception->getMessage(),
                    'next_attempt_at' => now()->addSeconds((int) config('vimp_callbacks.retry_backoff_seconds', 60)),
                ]);
            }

            Log::error('Could not dispatch ViMP callback', [
                'callback_id' => $callback->id,
                'mediakey' => $callback->mediakey,
                'type' => $callback->type,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
