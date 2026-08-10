<?php

namespace Tests\Feature;

use App\Jobs\ConvertVideoJob;
use App\Jobs\Concerns\HandlesVideoJobLifecycle;
use App\Models\Download;
use App\Models\User;
use App\Models\Video;
use App\Services\TranscodingFailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeVimpServer;
use Tests\TestCase;

class QueueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_failure_marks_download_and_pending_videos_and_notifies_vimp_once(): void
    {
        $server = FakeVimpServer::start();

        try {
            $user = $this->createUser($server->url());
            $download = $this->createDownload($user);
            $failedVideo = $this->createVideo($download, 'failed-video');
            $siblingVideo = $this->createVideo($download, 'sibling-video');
            $handler = app(TranscodingFailureHandler::class);

            $handler->handle($failedVideo, new RuntimeException('encoder failed'));
            $handler->handle($siblingVideo, new RuntimeException('second failure'));

            $this->assertSame(Download::FAILED, $download->fresh()->processed);
            $this->assertSame(Video::FAILED, $failedVideo->fresh()->processed);
            $this->assertSame(Video::FAILED, $siblingVideo->fresh()->processed);
            $this->assertNotNull($failedVideo->fresh()->failed_at);
            $this->assertNotNull($siblingVideo->fresh()->failed_at);

            $callbacks = $server->requestsFor('/transcoderwebservice/callback');
            $this->assertCount(1, $callbacks);
            $this->assertSame('encoder failed', json_decode($callbacks[0]['body'], true)['error']['message']);
        } finally {
            $server->stop();
        }
    }

    public function test_video_job_skips_failed_download_without_tracking_queue_id(): void
    {
        $user = $this->createUser('http://vimp.test');
        $download = $this->createDownload($user, Download::FAILED);
        $video = $this->createVideo($download, ConvertVideoJob::class);

        (new ConvertVideoJob($video))->handle();

        $this->assertDatabaseCount('download_jobs', 0);
        $this->assertSame(Video::UNPROCESSED, $video->fresh()->processed);
    }

    public function test_video_jobs_discard_missing_models_and_use_retry_backoff(): void
    {
        $user = $this->createUser('http://vimp.test');
        $download = $this->createDownload($user);
        $job = new ConvertVideoJob($this->createVideo($download, ConvertVideoJob::class));

        config(['workers.video_retry_backoff_seconds' => 45]);

        $this->assertTrue($job->deleteWhenMissingModels);
        $this->assertSame(45, $job->backoff());
    }

    public function test_retry_resets_transient_status_and_rethrows_original_exception(): void
    {
        $user = $this->createUser('http://vimp.test');
        $download = $this->createDownload($user);
        $video = $this->createVideo($download, ConvertVideoJob::class);
        $video->update(['processed' => Video::PROCESSING, 'failed_at' => now()]);
        $harness = new class($video) {
            use HandlesVideoJobLifecycle;

            public $video;

            public function __construct(Video $video)
            {
                $this->video = $video;
            }

            public function retry(RuntimeException $exception): void
            {
                $this->retryVideoJob($exception);
            }
        };

        try {
            $harness->retry(new RuntimeException('original failure'));
            $this->fail('The original exception was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('original failure', $exception->getMessage());
        }

        $this->assertSame(Video::UNPROCESSED, $video->fresh()->processed);
        $this->assertNull($video->fresh()->failed_at);
    }

    public function test_redis_block_for_configuration_is_typed(): void
    {
        $blockFor = config('queue.connections.redis.block_for');

        $this->assertTrue(is_int($blockFor) || is_null($blockFor));
    }

    private function createUser(string $url): User
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
        $user->email = uniqid('queue-', true) . '@example.org';
        $user->password = bcrypt('secret');
        $user->api_token = str_repeat('a', 32);
        $user->url = $url;
        $user->profile_id = 1;
        $user->save();

        return $user;
    }

    private function createDownload(User $user, int $processed = Download::PROCESSING): Download
    {
        return Download::create([
            'user_id' => $user->id,
            'mediakey' => md5(uniqid('', true)),
            'processed' => $processed,
            'payload' => [],
        ]);
    }

    private function createVideo(Download $download, string $title): Video
    {
        return Video::create([
            'user_id' => $download->user_id,
            'download_id' => $download->id,
            'title' => $title,
            'mediakey' => $download->mediakey,
            'disk' => 'uploaded',
            'path' => $download->mediakey,
            'target' => [
                'label' => '720p',
                'size' => '1280x720',
                'vbr' => 2400,
                'abr' => 128,
                'extension' => 'mp4',
                'created_at' => 1700000000,
            ],
            'processed' => Video::UNPROCESSED,
        ]);
    }
}
