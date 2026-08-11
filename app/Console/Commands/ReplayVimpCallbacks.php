<?php

namespace App\Console\Commands;

use App\Jobs\ConvertHLSVideoJob;
use App\Jobs\CreateSpritemapJob;
use App\Jobs\CreateThumbnailJob;
use App\Models\Video;
use App\Models\VimpCallback;
use App\Services\VimpCallbackOutbox;
use App\Services\VimpCallbackPayloadBuilder;
use Illuminate\Console\Command;
use Throwable;

class ReplayVimpCallbacks extends Command
{
    protected $signature = 'vimp:callbacks-replay
                            {--mediakey= : Replay callbacks for one mediakey}
                            {--id=* : Replay callback IDs}
                            {--rebuild : Rebuild callback payloads from converted videos}
                            {--force : Replay callbacks already marked as sent}
                            {--dry-run : Show callbacks without changing or dispatching them}';

    protected $description = 'Inspect, rebuild, and replay persisted ViMP callbacks';

    public function handle(VimpCallbackOutbox $outbox, VimpCallbackPayloadBuilder $payloads): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('id')));
        $mediakey = $this->option('mediakey');

        if (!$mediakey && !$ids) {
            $this->error('Specify --mediakey or at least one --id.');
            return 1;
        }

        $callbacks = VimpCallback::query()
            ->when($mediakey, function ($query) use ($mediakey) {
                $query->where('mediakey', $mediakey);
            })
            ->when($ids, function ($query) use ($ids) {
                $query->whereIn('id', $ids);
            })
            ->orderBy('id')
            ->get();

        if ($this->option('rebuild')) {
            $mediakeys = $mediakey ? [$mediakey] : $callbacks->pluck('mediakey')->unique()->all();
            $callbacks = $this->rebuild($mediakeys, $callbacks, $outbox, $payloads);
        }

        if ($callbacks->isEmpty()) {
            $this->warn('No matching ViMP callbacks found.');
            return 1;
        }

        $this->table(
            ['ID', 'Mediakey', 'Type', 'Status', 'Attempts', 'HTTP', 'Last error'],
            $callbacks->map(function (VimpCallback $callback) {
                return [
                    $callback->id,
                    $callback->mediakey,
                    $callback->type,
                    $callback->status,
                    $callback->attempts,
                    $callback->last_status_code,
                    $callback->last_error,
                ];
            })->all()
        );

        if ($this->option('dry-run')) {
            return 0;
        }

        $dispatched = 0;
        foreach ($callbacks as $callback) {
            if ($outbox->replay($callback, (bool) $this->option('force'))) {
                $dispatched++;
            }
        }

        $this->info('Dispatched ' . $dispatched . ' ViMP callback(s).');

        return 0;
    }

    private function rebuild(array $mediakeys, $callbacks, VimpCallbackOutbox $outbox, VimpCallbackPayloadBuilder $payloads)
    {
        $byVideo = $callbacks->whereNotNull('video_id')->keyBy(function (VimpCallback $callback) {
            return $callback->type . ':' . $callback->video_id;
        });

        Video::whereIn('mediakey', $mediakeys)
            ->whereNotNull('converted_at')
            ->orderBy('id')
            ->each(function (Video $video) use ($byVideo, $outbox, $payloads) {
                try {
                    list($type, $payload) = $this->payloadForVideo($video, $payloads);
                    $existing = $byVideo->get($type . ':' . $video->id);

                    if ($this->option('dry-run')) {
                        $this->line('Would rebuild ' . $type . ' callback for video ' . $video->id);
                    } elseif ($existing) {
                        $existing->update([
                            'payload' => $payload,
                            'status' => VimpCallback::STATUS_FAILED,
                        ]);
                    } else {
                        $outbox->enqueueForVideo($video, $type, $payload);
                    }
                } catch (Throwable $exception) {
                    $this->warn('Video ' . $video->id . ': ' . $exception->getMessage());
                }
            });

        if ($this->option('mediakey')) {
            return VimpCallback::whereIn('mediakey', $mediakeys)->orderBy('id')->get();
        }

        return VimpCallback::whereIn('id', $callbacks->pluck('id'))->orderBy('id')->get();
    }

    private function payloadForVideo(Video $video, VimpCallbackPayloadBuilder $payloads): array
    {
        if ($video->title === CreateThumbnailJob::class) {
            return [VimpCallback::TYPE_THUMBNAIL, $payloads->thumbnail($video)];
        }

        if ($video->title === CreateSpritemapJob::class) {
            return [VimpCallback::TYPE_SPRITEMAP, $payloads->spritemap($video)];
        }

        if ($video->title === ConvertHLSVideoJob::class) {
            return [VimpCallback::TYPE_MEDIUM, $payloads->hlsMedium($video, $video->file)];
        }

        return [VimpCallback::TYPE_MEDIUM, $payloads->medium($video)];
    }
}
