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

            $host = gethostname() ?: 'unknown';
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
        return json_encode([
            'ip' => gethostbyname(gethostname() ?: 'localhost'),
            'queue' => $queue,
            'job' => $job,
        ]);
    }
}
