<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class GpuMemoryGuard
{
    public function check(): array
    {
        if (!(bool) config('workers.gpu_guard.enabled')) {
            return [
                'allowed' => true,
                'reason' => 'disabled',
            ];
        }

        $minFreeMb = (int) config('workers.gpu_guard.min_free_mb', 0);

        if ($minFreeMb <= 0) {
            return [
                'allowed' => true,
                'reason' => 'no_threshold',
            ];
        }

        try {
            $freeMemory = $this->freeMemoryByGpu();

            if ($freeMemory === []) {
                return $this->failure('no_gpu_memory_reported');
            }

            $maxFreeMb = max($freeMemory);

            return [
                'allowed' => $maxFreeMb >= $minFreeMb,
                'reason' => $maxFreeMb >= $minFreeMb ? 'sufficient_free_memory' : 'insufficient_free_memory',
                'free_mb' => $freeMemory,
                'max_free_mb' => $maxFreeMb,
                'min_free_mb' => $minFreeMb,
            ];
        } catch (Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    private function freeMemoryByGpu(): array
    {
        $binary = (string) config('workers.gpu_guard.nvidia_smi_binary', 'nvidia-smi');
        $process = new Process([
            $binary,
            '--query-gpu=memory.free',
            '--format=csv,noheader,nounits',
        ]);

        $process->setTimeout((int) config('workers.gpu_guard.command_timeout_seconds', 5));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($process->getOutput())) as $line) {
            $line = trim($line);

            if ($line === '' || !is_numeric($line)) {
                continue;
            }

            $values[] = (int) $line;
        }

        return $values;
    }

    private function failure(string $reason): array
    {
        return [
            'allowed' => (bool) config('workers.gpu_guard.fail_open', false),
            'reason' => $reason,
            'fail_open' => (bool) config('workers.gpu_guard.fail_open', false),
        ];
    }
}
