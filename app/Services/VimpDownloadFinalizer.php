<?php

namespace App\Services;

use App\Models\Download;
use App\Models\Video;
use App\Models\VimpCallback;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VimpDownloadFinalizer
{
    private $outbox;

    private $payloads;

    public function __construct(VimpCallbackOutbox $outbox, VimpCallbackPayloadBuilder $payloads)
    {
        $this->outbox = $outbox;
        $this->payloads = $payloads;
    }

    public function finalizeIfComplete(int $downloadId): bool
    {
        $download = DB::transaction(function () use ($downloadId) {
            $download = Download::whereKey($downloadId)->lockForUpdate()->first();

            if (!$download || $download->processed === Download::FAILED) {
                return null;
            }

            $total = Video::where('download_id', $download->id)->count();
            $complete = Video::where('download_id', $download->id)
                ->where('processed', Video::PROCESSED)
                ->whereNotNull('downloaded_at')
                ->count();

            if ($total === 0 || $complete !== $total) {
                return null;
            }

            if ($download->processed !== Download::PROCESSED) {
                $download->update(['processed' => Download::PROCESSED]);
                Log::info('All transcoded files were downloaded by ViMP', [
                    'download_id' => $download->id,
                    'mediakey' => $download->mediakey,
                    'files' => $total,
                ]);
            }

            return $download->fresh();
        });

        if (!$download) {
            return false;
        }

        $this->ensureFinishedCallback($download);

        return true;
    }

    public function ensureFinishedCallback(Download $download): VimpCallback
    {
        return $this->outbox->enqueueForDownload(
            $download,
            VimpCallback::TYPE_FINISHED,
            $this->payloads->finished($download)
        );
    }
}
