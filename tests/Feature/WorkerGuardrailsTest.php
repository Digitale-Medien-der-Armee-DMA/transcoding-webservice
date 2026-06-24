<?php

namespace Tests\Feature;

use App\Services\GpuMemoryGuard;
use App\Services\WorkerHeartbeat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkerGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_gpu_memory_guard_allows_jobs_when_disabled(): void
    {
        config([
            'workers.gpu_guard.enabled' => false,
            'workers.gpu_guard.nvidia_smi_binary' => 'definitely-missing-nvidia-smi',
            'workers.gpu_guard.min_free_mb' => 999999,
        ]);

        $decision = app(GpuMemoryGuard::class)->check();

        $this->assertTrue($decision['allowed']);
        $this->assertSame('disabled', $decision['reason']);
    }

    public function test_gpu_memory_guard_rejects_when_free_memory_is_below_threshold(): void
    {
        config([
            'workers.gpu_guard.enabled' => true,
            'workers.gpu_guard.nvidia_smi_binary' => $this->fakeNvidiaSmi("4096\n"),
            'workers.gpu_guard.min_free_mb' => 8192,
            'workers.gpu_guard.command_timeout_seconds' => 2,
            'workers.gpu_guard.fail_open' => false,
        ]);

        $decision = app(GpuMemoryGuard::class)->check();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('insufficient_free_memory', $decision['reason']);
        $this->assertSame(4096, $decision['max_free_mb']);
        $this->assertSame(8192, $decision['min_free_mb']);
    }

    public function test_gpu_memory_guard_allows_when_one_visible_gpu_has_enough_memory(): void
    {
        config([
            'workers.gpu_guard.enabled' => true,
            'workers.gpu_guard.nvidia_smi_binary' => $this->fakeNvidiaSmi("2048\n16384\n"),
            'workers.gpu_guard.min_free_mb' => 8192,
            'workers.gpu_guard.command_timeout_seconds' => 2,
            'workers.gpu_guard.fail_open' => false,
        ]);

        $decision = app(GpuMemoryGuard::class)->check();

        $this->assertTrue($decision['allowed']);
        $this->assertSame('sufficient_free_memory', $decision['reason']);
        $this->assertSame(16384, $decision['max_free_mb']);
    }

    public function test_worker_heartbeat_upserts_current_worker(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00'));

        app(WorkerHeartbeat::class)->touch('video', 'App\\Jobs\\ConvertVideoJob');

        $worker = DB::table('workers')->where('host', gethostname())->first();

        $this->assertNotNull($worker);
        $this->assertSame('2026-06-24 12:00:00', Carbon::parse($worker->last_seen_at)->format('Y-m-d H:i:s'));

        $description = json_decode($worker->description, true);

        $this->assertSame('video', $description['queue']);
        $this->assertSame('App\\Jobs\\ConvertVideoJob', $description['job']);
        $this->assertArrayHasKey('ip', $description);
    }

    private function fakeNvidiaSmi(string $output): string
    {
        $directory = storage_path('framework/testing');

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory . '/nvidia-smi-' . md5($output);
        file_put_contents($path, "#!/bin/sh\ncat <<'EOF'\n" . $output . "EOF\n");
        chmod($path, 0755);

        return $path;
    }
}
