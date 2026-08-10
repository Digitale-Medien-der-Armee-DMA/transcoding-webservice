<?php

namespace App\Jobs\Concerns;

use App\Models\Download;
use App\Models\Video;
use App\Services\TranscodingFailureHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

trait HandlesVideoJobLifecycle
{
    public $deleteWhenMissingModels = true;

    protected function shouldProcessVideo(): bool
    {
        $this->video->refresh();

        if ($this->video->converted_at) {
            return false;
        }

        $download = Download::find($this->video->download_id);

        if (!$download || $download->processed === Download::FAILED) {
            Log::info('Skipping video job because its download is missing or failed', [
                'download_id' => $this->video->download_id,
                'video_id' => $this->video->id,
                'job' => static::class,
            ]);

            return false;
        }

        return true;
    }

    protected function retryVideoJob(Throwable $exception): void
    {
        $this->video->update([
            'processed' => Video::UNPROCESSED,
            'failed_at' => null,
        ]);

        throw $exception;
    }

    public function backoff(): int
    {
        return (int) config('workers.video_retry_backoff_seconds', 30);
    }

    public function failed(Throwable $exception): void
    {
        app(TranscodingFailureHandler::class)->handle($this->video, $exception);
    }
}
