<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueMetrics
{
    public function collect(array $queueNames): array
    {
        $queues = $this->emptyMetrics($queueNames);
        $connectionName = config('queue.default');
        $driver = config("queue.connections.{$connectionName}.driver");

        if ($driver === 'redis') {
            return $this->redisMetrics($queues, $connectionName);
        }

        if ($driver === 'database') {
            return $this->databaseMetrics($queues, $connectionName);
        }

        return $queues;
    }

    private function redisMetrics(array $queues, string $connectionName): array
    {
        $redisConnection = config("queue.connections.{$connectionName}.connection", 'default');

        try {
            $redis = Redis::connection($redisConnection);

            foreach (array_keys($queues) as $queue) {
                $key = 'queues:' . $queue;
                $immediate = (int) $redis->llen($key);
                $delayed = (int) $redis->zcard($key . ':delayed');
                $reserved = (int) $redis->zcard($key . ':reserved');

                $queues[$queue] = [
                    'waiting' => $immediate + $delayed,
                    'running' => $reserved,
                    'oldest_waiting_age_seconds' => $this->oldestRedisJobAge($redis, $key),
                ];
            }
        } catch (Throwable $exception) {
            return $queues;
        }

        return $queues;
    }

    private function databaseMetrics(array $queues, string $connectionName): array
    {
        $table = config("queue.connections.{$connectionName}.table", 'jobs');
        $databaseConnection = config("queue.connections.{$connectionName}.connection");
        $schema = Schema::connection($databaseConnection);

        if (!$schema->hasTable($table)) {
            return $queues;
        }

        $database = DB::connection($databaseConnection);

        foreach (array_keys($queues) as $queue) {
            $waiting = $database->table($table)
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->count();
            $running = $database->table($table)
                ->where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->count();
            $oldest = $database->table($table)
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->min('created_at');

            $queues[$queue] = [
                'waiting' => $waiting,
                'running' => $running,
                'oldest_waiting_age_seconds' => $oldest
                    ? max(0, Carbon::now()->timestamp - (int) $oldest)
                    : null,
            ];
        }

        return $queues;
    }

    private function oldestRedisJobAge($redis, string $key): ?int
    {
        $payloads = [];
        $immediate = $redis->lindex($key, 0);

        if (is_string($immediate)) {
            $payloads[] = $immediate;
        }

        $delayed = $redis->zrange($key . ':delayed', 0, 0);

        if (is_array($delayed) && isset($delayed[0])) {
            $payloads[] = $delayed[0];
        }

        $pushedAt = collect($payloads)
            ->map(function ($payload) {
                return json_decode($payload, true)['pushedAt'] ?? null;
            })
            ->filter(function ($timestamp) {
                return is_numeric($timestamp);
            })
            ->map(function ($timestamp) {
                return (int) $timestamp;
            })
            ->min();

        return $pushedAt ? max(0, Carbon::now()->timestamp - $pushedAt) : null;
    }

    private function emptyMetrics(array $queueNames): array
    {
        $queues = [];

        foreach ($queueNames as $queue) {
            $queues[$queue] = [
                'waiting' => 0,
                'running' => 0,
                'oldest_waiting_age_seconds' => null,
            ];
        }

        return $queues;
    }
}
