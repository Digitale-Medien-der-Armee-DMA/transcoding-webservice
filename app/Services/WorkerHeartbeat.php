<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WorkerHeartbeat
{
    public function touch(?string $queue = null, ?string $job = null): void
    {
        if (!(bool) config('workers.heartbeat.enabled', true)) {
            return;
        }

        try {
            if (!Schema::hasTable('workers')) {
                return;
            }

            $host = config('workers.heartbeat.name') ?: (gethostname() ?: 'unknown');
            $now = Carbon::now();
            $values = [
                'description' => $this->description($queue, $job),
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];

            if (DB::table('workers')->where('host', $host)->exists()) {
                DB::table('workers')->where('host', $host)->update($values);
                return;
            }

            DB::table('workers')->insert(array_merge($values, [
                'host' => $host,
                'created_at' => $now,
            ]));
        } catch (Throwable $exception) {
            // Heartbeats must never prevent queue work.
        }
    }

    private function description(?string $queue, ?string $job): string
    {
        $description = [
            'ip' => gethostbyname(gethostname() ?: 'localhost'),
            'queue' => $queue,
            'job' => $job,
            'role' => config('workers.runtime.role'),
        ];

        if ($description['role'] === 'gpu-video') {
            $description['runtime'] = [
                'ffmpeg_version' => config('workers.runtime.ffmpeg_version'),
                'video_encoder' => config('workers.runtime.video_encoder'),
                'video_filter' => config('workers.runtime.video_filter'),
            ];
        }

        return json_encode($description);
    }
}
