<?php

namespace Tests\Feature;

use App\Models\Download;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploaded');
        Storage::fake('converted');

        config([
            'health.ffmpeg_binaries' => [PHP_BINARY],
            'health.redis_required' => false,
            'health.worker_stale_after_seconds' => 300,
            'health.queue_names' => ['download', 'video', 'default'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_live_endpoint_reports_ok(): void
    {
        $this->getJson('/internal/health/live')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => config('app.name'),
            ])
            ->assertJsonStructure([
                'status',
                'service',
                'timestamp',
            ]);
    }

    public function test_ready_endpoint_checks_database_storage_binaries_and_skips_redis_when_not_required(): void
    {
        $response = $this->getJson('/internal/health/ready');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'checks' => [
                    'database' => ['status' => 'ok'],
                    'storage' => [
                        'uploaded' => ['status' => 'ok'],
                        'converted' => ['status' => 'ok'],
                    ],
                    'redis' => ['status' => 'skipped'],
                ],
            ]);

        $ffmpegChecks = $response->json('checks.ffmpeg');
        $this->assertSame('ok', $ffmpegChecks[PHP_BINARY]['status']);
    }

    public function test_ready_endpoint_fails_when_required_binary_is_missing(): void
    {
        config(['health.ffmpeg_binaries' => ['definitely-missing-ffmpeg-binary']]);

        $this->getJson('/internal/health/ready')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'failed',
                'checks' => [
                    'ffmpeg' => [
                        'definitely-missing-ffmpeg-binary' => [
                            'status' => 'failed',
                        ],
                    ],
                ],
            ]);
    }

    public function test_metrics_endpoint_reports_queue_worker_storage_and_transcoding_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 08:00:00'));

        DB::table('jobs')->insert([
            [
                'queue' => 'download',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => Carbon::now()->timestamp,
                'created_at' => Carbon::now()->subMinutes(10)->timestamp,
            ],
            [
                'queue' => 'video',
                'payload' => '{}',
                'attempts' => 1,
                'reserved_at' => Carbon::now()->subMinute()->timestamp,
                'available_at' => Carbon::now()->subMinutes(5)->timestamp,
                'created_at' => Carbon::now()->subMinutes(5)->timestamp,
            ],
        ]);

        DB::table('workers')->insert([
            [
                'host' => 'fresh-worker',
                'description' => '127.0.0.1',
                'last_seen_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'host' => 'stale-worker',
                'description' => '127.0.0.2',
                'last_seen_at' => Carbon::now()->subMinutes(10),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'connection' => 'database',
            'queue' => 'video',
            'payload' => '{}',
            'exception' => 'failed',
            'failed_at' => Carbon::now(),
        ]);

        $user = $this->createUser();
        $download = Download::create([
            'user_id' => $user->id,
            'mediakey' => 'abcdefabcdefabcdefabcdefabcdef12',
            'processed' => Download::PROCESSING,
            'payload' => [],
        ]);

        Video::create([
            'user_id' => $user->id,
            'download_id' => $download->id,
            'title' => 'processed',
            'mediakey' => $download->mediakey,
            'disk' => 'uploaded',
            'path' => $download->mediakey,
            'target' => [],
            'processed' => Video::PROCESSED,
            'converted_at' => Carbon::now()->subMinute(),
        ]);

        Video::create([
            'user_id' => $user->id,
            'download_id' => $download->id,
            'title' => 'failed',
            'mediakey' => $download->mediakey,
            'disk' => 'uploaded',
            'path' => $download->mediakey,
            'target' => [],
            'processed' => Video::FAILED,
        ]);

        $response = $this->getJson('/internal/metrics');

        $response
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'queues' => [
                    'download' => [
                        'waiting' => 1,
                        'running' => 0,
                        'oldest_waiting_age_seconds' => 600,
                    ],
                    'video' => [
                        'waiting' => 0,
                        'running' => 1,
                    ],
                ],
                'workers' => [
                    'total' => 2,
                    'stale' => 1,
                    'stale_after_seconds' => 300,
                ],
                'transcoding' => [
                    'running_jobs' => 0,
                    'failed_jobs' => 1,
                    'failed_videos' => 1,
                    'error_rate' => 0.5,
                ],
            ])
            ->assertJsonStructure([
                'timestamp',
                'storage' => [
                    'uploaded' => ['path', 'free_bytes', 'total_bytes', 'used_bytes'],
                    'converted' => ['path', 'free_bytes', 'total_bytes', 'used_bytes'],
                ],
                'runtime' => [
                    'php_version',
                    'ffmpeg',
                ],
            ]);

        $this->assertNotNull($response->json('transcoding.last_successful_transcoding_at'));

    }

    private function createUser(): User
    {
        DB::table('profiles')->insertOrIgnore([
            'id' => 1,
            'encoder' => 'libx264',
            'fallback_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = new User();
        $user->name = 'VIMP';
        $user->email = 'vimp-health@example.org';
        $user->password = bcrypt('secret');
        $user->api_token = str_repeat('a', 32);
        $user->url = 'http://vimp.test';
        $user->profile_id = 1;
        $user->save();

        return $user;
    }
}
