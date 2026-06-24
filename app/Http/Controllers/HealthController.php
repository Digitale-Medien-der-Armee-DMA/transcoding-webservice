<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name', 'transcoding-webservice'),
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'storage' => $this->checkStorage(),
            'ffmpeg' => $this->checkBinaries(),
            'redis' => $this->checkRedis(),
        ];

        $ready = collect($this->flattenChecks($checks))->every(function ($check) {
            return in_array($check['status'], ['ok', 'skipped'], true);
        });

        return response()->json([
            'status' => $ready ? 'ok' : 'failed',
            'timestamp' => Carbon::now()->toIso8601String(),
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    public function metrics(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => Carbon::now()->toIso8601String(),
            'queues' => $this->queueMetrics(),
            'workers' => $this->workerMetrics(),
            'storage' => $this->storageMetrics(),
            'transcoding' => $this->transcodingMetrics(),
            'runtime' => [
                'php_version' => PHP_VERSION,
                'ffmpeg' => $this->binaryMetrics(),
            ],
        ]);
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function flattenChecks(array $checks): array
    {
        $flat = [];

        foreach ($checks as $check) {
            if (isset($check['status'])) {
                $flat[] = $check;
                continue;
            }

            foreach ($check as $nestedCheck) {
                if (isset($nestedCheck['status'])) {
                    $flat[] = $nestedCheck;
                }
            }
        }

        return $flat;
    }

    private function checkStorage(): array
    {
        $checks = [];

        foreach (config('health.storage_disks', []) as $disk) {
            try {
                $file = '.healthcheck-' . getmypid();
                Storage::disk($disk)->put($file, Carbon::now()->toIso8601String());
                Storage::disk($disk)->delete($file);
                $checks[$disk] = ['status' => 'ok'];
            } catch (Throwable $exception) {
                $checks[$disk] = [
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $checks;
    }

    private function checkBinaries(): array
    {
        $checks = [];

        foreach (config('health.ffmpeg_binaries', []) as $binary) {
            $checks[$binary] = $this->binaryExists($binary)
                ? ['status' => 'ok']
                : ['status' => 'failed', 'message' => 'Binary not found or not executable'];
        }

        return $checks;
    }

    private function checkRedis(): array
    {
        if (!$this->redisIsRequired()) {
            return ['status' => 'skipped', 'message' => 'Redis is not required by current configuration'];
        }

        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function redisIsRequired(): bool
    {
        if ((bool) config('health.redis_required')) {
            return true;
        }

        return in_array(config('cache.default'), ['redis'], true)
            || in_array(config('queue.default'), ['redis'], true)
            || in_array(config('session.driver'), ['redis'], true);
    }

    private function queueMetrics(): array
    {
        $queues = [];

        foreach (config('health.queue_names', []) as $queue) {
            $queues[$queue] = [
                'waiting' => 0,
                'running' => 0,
                'oldest_waiting_age_seconds' => null,
            ];
        }

        if (!Schema::hasTable('jobs')) {
            return $queues;
        }

        foreach (array_keys($queues) as $queue) {
            $waiting = DB::table('jobs')
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->count();
            $running = DB::table('jobs')
                ->where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->count();
            $oldest = DB::table('jobs')
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->min('created_at');

            $queues[$queue] = [
                'waiting' => $waiting,
                'running' => $running,
                'oldest_waiting_age_seconds' => $oldest ? max(0, Carbon::now()->timestamp - (int) $oldest) : null,
            ];
        }

        return $queues;
    }

    private function workerMetrics(): array
    {
        if (!Schema::hasTable('workers')) {
            return [
                'total' => 0,
                'stale' => 0,
                'stale_after_seconds' => (int) config('health.worker_stale_after_seconds'),
                'heartbeat_enabled' => (bool) config('workers.heartbeat.enabled', true),
                'gpu_guard' => $this->gpuGuardMetrics(),
            ];
        }

        $staleAfter = (int) config('health.worker_stale_after_seconds');
        $staleBefore = Carbon::now()->subSeconds($staleAfter);

        return [
            'total' => DB::table('workers')->count(),
            'stale' => DB::table('workers')
                ->where(function ($query) use ($staleBefore) {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', $staleBefore);
                })
                ->count(),
            'stale_after_seconds' => $staleAfter,
            'heartbeat_enabled' => (bool) config('workers.heartbeat.enabled', true),
            'gpu_guard' => $this->gpuGuardMetrics(),
        ];
    }

    private function gpuGuardMetrics(): array
    {
        return [
            'enabled' => (bool) config('workers.gpu_guard.enabled', false),
            'min_free_mb' => (int) config('workers.gpu_guard.min_free_mb', 0),
            'retry_delay_seconds' => (int) config('workers.gpu_guard.retry_delay_seconds', 0),
            'fail_open' => (bool) config('workers.gpu_guard.fail_open', false),
        ];
    }

    private function storageMetrics(): array
    {
        $metrics = [];

        foreach (config('health.storage_disks', []) as $disk) {
            $root = config("filesystems.disks.{$disk}.root");

            if (!$root) {
                $metrics[$disk] = ['path' => null, 'free_bytes' => null, 'total_bytes' => null, 'used_bytes' => null];
                continue;
            }

            if (!is_dir($root)) {
                @mkdir($root, 0775, true);
            }

            $free = @disk_free_space($root);
            $total = @disk_total_space($root);

            $metrics[$disk] = [
                'path' => $root,
                'free_bytes' => $free === false ? null : $free,
                'total_bytes' => $total === false ? null : $total,
                'used_bytes' => $free === false || $total === false ? null : $total - $free,
            ];
        }

        return $metrics;
    }

    private function transcodingMetrics(): array
    {
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        if (!Schema::hasTable('videos')) {
            return [
                'running_jobs' => 0,
                'failed_jobs' => $failedJobs,
                'failed_videos' => 0,
                'last_successful_transcoding_at' => null,
                'error_rate' => null,
            ];
        }

        $processed = DB::table('videos')->where('processed', Video::PROCESSED)->count();
        $failed = DB::table('videos')->where('processed', Video::FAILED)->count();
        $totalFinished = $processed + $failed;

        return [
            'running_jobs' => DB::table('videos')->where('processed', Video::PROCESSING)->count(),
            'failed_jobs' => $failedJobs,
            'failed_videos' => $failed,
            'last_successful_transcoding_at' => DB::table('videos')->whereNotNull('converted_at')->max('converted_at'),
            'error_rate' => $totalFinished > 0 ? round($failed / $totalFinished, 4) : null,
        ];
    }

    private function binaryMetrics(): array
    {
        $metrics = [];

        foreach (config('health.ffmpeg_binaries', []) as $binary) {
            $metrics[$binary] = [
                'available' => $this->binaryExists($binary),
            ];
        }

        return $metrics;
    }

    private function binaryExists(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (strpos($binary, DIRECTORY_SEPARATOR) !== false) {
            return is_executable($binary);
        }

        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');

        foreach ($paths as $path) {
            if ($path !== '' && is_executable($path . DIRECTORY_SEPARATOR . $binary)) {
                return true;
            }
        }

        return false;
    }
}
