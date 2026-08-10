<?php

namespace App\Services;

use App\Http\Controllers\TranscodingController;
use App\Models\Download;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranscodingFailureHandler
{
    public function handle(Video $video, Throwable $exception): void
    {
        $notifyVimp = DB::transaction(function () use ($video) {
            $download = Download::whereKey($video->download_id)->lockForUpdate()->first();

            if (!$download || $download->processed === Download::FAILED) {
                return false;
            }

            $download->update(['processed' => Download::FAILED]);

            Video::where('download_id', $video->download_id)
                ->whereNull('converted_at')
                ->update([
                    'processed' => Video::FAILED,
                    'failed_at' => Carbon::now(),
                ]);

            return true;
        });

        if (!$notifyVimp) {
            return;
        }

        Log::error('Transcoding failed; remaining video jobs will be skipped', [
            'download_id' => $video->download_id,
            'video_id' => $video->id,
            'job' => $video->title,
            'exception' => $exception,
        ]);

        try {
            TranscodingController::executeErrorCallback($video, $exception->getMessage());
        } catch (Throwable $callbackException) {
            Log::error('VIMP error callback failed', [
                'download_id' => $video->download_id,
                'video_id' => $video->id,
                'message' => $callbackException->getMessage(),
            ]);
        }
    }
}
