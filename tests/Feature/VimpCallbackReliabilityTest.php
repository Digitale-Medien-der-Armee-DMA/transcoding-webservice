<?php

namespace Tests\Feature;

use App\Exceptions\VimpCallbackDeliveryException;
use App\Http\Controllers\TranscodingController;
use App\Jobs\ConvertHLSVideoJob;
use App\Jobs\ConvertVideoJob;
use App\Jobs\SendVimpCallbackJob;
use App\Models\Download;
use App\Models\User;
use App\Models\Video;
use App\Models\VimpCallback;
use App\Services\VimpCallbackDeliveryService;
use App\Services\VimpCallbackOutbox;
use App\Services\VimpCallbackPayloadBuilder;
use FFMpeg\Coordinate\Dimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeVimpServer;
use Tests\TestCase;

class VimpCallbackReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://transcoder.test']);
        URL::forceRootUrl('http://transcoder.test');
    }

    public function test_http_404_is_persisted_without_resetting_a_converted_video(): void
    {
        $server = FakeVimpServer::start('fake-video', ['callback_status' => 404]);

        try {
            Queue::fake();
            list($download, $video) = $this->createConvertedVideo($server->url());
            $callback = app(VimpCallbackOutbox::class)->enqueueForVideo(
                $video,
                VimpCallback::TYPE_MEDIUM,
                ['mediakey' => $video->mediakey, 'medium' => ['label' => '720p', 'url' => 'http://transcoder.test/file']]
            );

            Queue::assertPushedOn('callback', SendVimpCallbackJob::class);

            try {
                app(VimpCallbackDeliveryService::class)->deliver($callback);
                $this->fail('The 404 callback was expected to fail.');
            } catch (VimpCallbackDeliveryException $exception) {
                $this->assertSame(404, $exception->getCode());
            }

            $callback->refresh();
            $this->assertSame(VimpCallback::STATUS_QUEUED, $callback->status);
            $this->assertSame(404, $callback->last_status_code);
            $this->assertSame(1, $callback->attempts);
            $this->assertSame(Video::PROCESSED, $video->fresh()->processed);
            $this->assertNotNull($video->fresh()->converted_at);
            $this->assertSame(Download::PROCESSING, $download->fresh()->processed);
        } finally {
            $server->stop();
        }
    }

    public function test_failed_callback_can_be_delivered_after_vimp_recovers(): void
    {
        $server = FakeVimpServer::start('fake-video', ['callback_status' => 404]);

        try {
            Queue::fake();
            list(, $video) = $this->createConvertedVideo($server->url());
            $callback = app(VimpCallbackOutbox::class)->enqueueForVideo(
                $video,
                VimpCallback::TYPE_MEDIUM,
                ['mediakey' => $video->mediakey, 'medium' => ['label' => '720p']]
            );

            try {
                app(VimpCallbackDeliveryService::class)->deliver($callback);
            } catch (VimpCallbackDeliveryException $exception) {
                // Expected first attempt.
            }

            $server->setOptions(['callback_status' => 200]);
            app(VimpCallbackDeliveryService::class)->deliver($callback);

            $callback->refresh();
            $this->assertSame(VimpCallback::STATUS_SENT, $callback->status);
            $this->assertSame(2, $callback->attempts);
            $this->assertCount(2, $server->requestsFor('/transcoderwebservice/callback'));
        } finally {
            $server->stop();
        }
    }

    public function test_semantic_fake_rejects_an_unknown_medium_label(): void
    {
        $server = FakeVimpServer::start('fake-video', [
            'allowed_mediakeys' => [str_repeat('a', 32)],
            'allowed_labels' => ['720p'],
        ]);

        try {
            Queue::fake();
            list(, $video) = $this->createConvertedVideo($server->url());
            $callback = app(VimpCallbackOutbox::class)->enqueueForVideo(
                $video,
                VimpCallback::TYPE_MEDIUM,
                ['mediakey' => $video->mediakey, 'medium' => ['label' => 'not-configured']]
            );

            try {
                app(VimpCallbackDeliveryService::class)->deliver($callback);
                $this->fail('The semantic fake should reject the label.');
            } catch (VimpCallbackDeliveryException $exception) {
                $this->assertSame(404, $exception->getCode());
            }

            $this->assertStringContainsString('unknown medium label', $callback->fresh()->last_response);
        } finally {
            $server->stop();
        }
    }

    public function test_download_acknowledgements_finalize_once_and_queue_one_finished_callback(): void
    {
        Queue::fake();
        $user = $this->createUser('http://vimp.test');
        $download = $this->createDownload($user);
        $first = $this->createVideo($download, ['file' => 'first.mp4', 'downloaded_at' => now()]);
        $second = $this->createVideo($download, ['file' => 'second.mp4']);

        $this->withHeader('Authorization', 'Bearer ' . $user->api_token)
            ->postJson('/api/download/' . $second->file . '/finished')
            ->assertOk();

        $this->assertSame(Download::PROCESSED, $download->fresh()->processed);
        $this->assertNotNull($first->fresh()->downloaded_at);
        $this->assertNotNull($second->fresh()->downloaded_at);
        $this->assertSame(1, VimpCallback::where('type', VimpCallback::TYPE_FINISHED)->count());
        Queue::assertPushed(SendVimpCallbackJob::class, 1);
        Queue::assertPushedOn('callback', SendVimpCallbackJob::class);

        $this->withHeader('Authorization', 'Bearer ' . $user->api_token)
            ->postJson('/api/download/' . $second->file . '/finished')
            ->assertOk();
        $this->artisan('transcode:cleanup')->assertExitCode(0);

        $this->assertSame(1, VimpCallback::where('type', VimpCallback::TYPE_FINISHED)->count());
        Queue::assertPushed(SendVimpCallbackJob::class, 1);
    }

    public function test_hls_callback_404_does_not_fail_the_completed_encode(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required for HLS callback test.');
        }

        Storage::fake('converted');
        $server = FakeVimpServer::start('fake-video', ['callback_status' => 404]);

        try {
            list(, $video) = $this->createConvertedVideo($server->url(), ConvertHLSVideoJob::class);
            Storage::disk('converted')->put($video->mediakey . '_720p_mp4/playlist.m3u8', '#EXTM3U');
            Storage::disk('converted')->put($video->mediakey . '_720p_mp4/segment_000.ts', 'segment');

            $transcoder = new TranscodingController($video, new Dimension(1280, 720), 1);
            $transcoder->setHLS(true);
            $transcoder->executeCallback();

            $this->assertSame(Video::PROCESSED, $video->fresh()->processed);
            $this->assertNotNull($video->fresh()->converted_at);
            $this->assertSame(VimpCallback::STATUS_FAILED, VimpCallback::firstOrFail()->status);
            Storage::disk('converted')->assertExists($video->mediakey . '_720p_mp4.zip');
        } finally {
            $server->stop();
        }
    }

    public function test_callback_compatibility_overrides_are_opt_in(): void
    {
        Storage::fake('converted');
        list(, $video) = $this->createConvertedVideo('http://vimp.test', ConvertHLSVideoJob::class);
        $archive = $video->mediakey . '_720p_mp4.zip';
        Storage::disk('converted')->put($archive, 'archive');

        config([
            'vimp_callbacks.artifact_base_url' => 'https://transcoder.example.org',
            'vimp_callbacks.label_map' => json_encode(['720p' => 'HD']),
            'vimp_callbacks.medium_fields' => 'label,url',
        ]);

        $payload = app(VimpCallbackPayloadBuilder::class)->hlsMedium($video, $archive);

        $this->assertSame([
            'label' => 'HD',
            'url' => 'https://transcoder.example.org/api/download/' . rawurlencode($archive),
        ], $payload['medium']);
    }

    public function test_payload_preparation_failure_requires_an_explicit_rebuild(): void
    {
        Queue::fake();
        list(, $video) = $this->createConvertedVideo('http://vimp.test');

        $callback = app(VimpCallbackOutbox::class)->recordPreparationFailureForVideo(
            $video,
            VimpCallback::TYPE_MEDIUM,
            new \RuntimeException('artifact metadata unavailable')
        );

        $this->assertSame(VimpCallback::STATUS_PREPARATION_FAILED, $callback->status);
        $this->assertSame(0, app(VimpCallbackOutbox::class)->dispatchDue());
        $this->assertFalse(app(VimpCallbackOutbox::class)->replay($callback));
        Queue::assertNothingPushed();
        $this->getJson('/internal/metrics')
            ->assertOk()
            ->assertJsonPath('callbacks.failed', 1)
            ->assertJsonPath('callbacks.pending', 0);
    }

    public function test_rebuild_command_makes_a_preparation_failure_replayable(): void
    {
        Queue::fake();
        Storage::fake('converted');
        list(, $video) = $this->createConvertedVideo('http://vimp.test', ConvertHLSVideoJob::class);
        $archive = $video->mediakey . '_720p_mp4.zip';
        $video->update(['file' => $archive]);
        Storage::disk('converted')->put($archive, 'archive');
        $callback = app(VimpCallbackOutbox::class)->recordPreparationFailureForVideo(
            $video,
            VimpCallback::TYPE_MEDIUM,
            new \RuntimeException('archive was not ready')
        );

        $this->artisan('vimp:callbacks-replay', [
            '--id' => [$callback->id],
            '--rebuild' => true,
        ])->assertExitCode(0);

        $callback->refresh();
        $this->assertSame(VimpCallback::STATUS_QUEUED, $callback->status);
        $this->assertSame('720p', $callback->payload['medium']['label']);
        Queue::assertPushedOn('callback', SendVimpCallbackJob::class);
    }

    private function createConvertedVideo(string $url, string $title = ConvertVideoJob::class): array
    {
        $user = $this->createUser($url);
        $download = $this->createDownload($user);
        $video = $this->createVideo($download, ['title' => $title]);

        return [$download, $video];
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
        $user->email = uniqid('callback-', true) . '@example.org';
        $user->password = bcrypt('secret');
        $user->api_token = str_repeat('b', 32);
        $user->url = $url;
        $user->profile_id = 1;
        $user->save();

        return $user;
    }

    private function createDownload(User $user): Download
    {
        return Download::create([
            'user_id' => $user->id,
            'mediakey' => str_repeat('a', 32),
            'processed' => Download::PROCESSING,
            'payload' => ['source' => ['url' => 'https://vimp.test/source.mp4']],
        ]);
    }

    private function createVideo(Download $download, array $attributes = []): Video
    {
        return Video::create([
            'user_id' => $download->user_id,
            'download_id' => $download->id,
            'title' => $attributes['title'] ?? ConvertVideoJob::class,
            'mediakey' => $download->mediakey,
            'disk' => 'uploaded',
            'path' => $download->mediakey,
            'file' => $attributes['file'] ?? $download->mediakey . '_1700000000_720p.mp4',
            'target' => [
                'label' => '720p',
                'size' => '1280x720',
                'vbr' => 2400,
                'abr' => 128,
                'extension' => 'mp4',
                'created_at' => 1700000000,
                'default' => false,
            ],
            'processed' => Video::PROCESSED,
            'converted_at' => now(),
            'downloaded_at' => $attributes['downloaded_at'] ?? null,
        ]);
    }
}
