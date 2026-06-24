<?php

namespace Tests\Feature;

use App\Http\Controllers\TranscodingController;
use App\Jobs\ConvertHLSVideoJob;
use App\Jobs\ConvertPreviewVideoJob;
use App\Jobs\ConvertVideoJob;
use App\Jobs\CreateSpritemapJob;
use App\Jobs\CreateThumbnailJob;
use App\Jobs\DownloadFileJob;
use App\Models\Download;
use App\Models\DownloadJob;
use App\Models\User;
use App\Models\Video;
use FFMpeg\Coordinate\Dimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\FakeVimpServer;
use Tests\TestCase;

class VimpContractTest extends TestCase
{
    use RefreshDatabase;

    private const API_TOKEN = '0123456789abcdef0123456789abcdef';
    private const MEDIAKEY = 'abcdefabcdefabcdefabcdefabcdef12';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://transcoder.test']);
        URL::forceRootUrl('http://transcoder.test');
    }

    public function test_transcode_requires_api_token(): void
    {
        $this->postJson('/api/transcode', $this->transcodePayload())
            ->assertStatus(401);
    }

    public function test_transcode_persists_request_without_api_token_and_queues_download(): void
    {
        Queue::fake();
        $user = $this->createApiUser(['url' => 'http://vimp.test']);

        $response = $this->withApiToken($user)
            ->postJson('/api/transcode', $this->transcodePayload());

        $response
            ->assertOk()
            ->assertExactJson([
                'message' => 'File is queued for download',
                'status' => 'success',
            ]);

        $download = Download::where('mediakey', self::MEDIAKEY)->firstOrFail();

        $this->assertSame($user->id, (int) $download->user_id);
        $this->assertSame(Download::UNPROCESSED, (int) $download->processed);
        $this->assertArrayNotHasKey('api_token', $download->payload);
        $this->assertSame(
            'http://vimp.test/transcoderwebservice/source/input.mp4',
            $download->payload['source']['url']
        );

        Queue::assertPushedOn('download', DownloadFileJob::class, function (DownloadFileJob $job) use ($download) {
            return $job->download->is($download);
        });
    }

    public function test_transcode_validation_failure_keeps_current_response_shape(): void
    {
        $user = $this->createApiUser(['url' => 'http://vimp.test']);
        $payload = $this->transcodePayload([
            'mediakey' => 'not-valid',
            'target' => ['format' => []],
        ]);

        $response = $this->withApiToken($user)->postJson('/api/transcode', $payload);

        $response->assertStatus(400);
        $response->assertJson([
            'status' => 'failed',
        ]);
        $this->assertIsArray($response->json('message'));
    }

    public function test_download_file_requires_matching_api_user_and_existing_file(): void
    {
        Storage::fake('converted');
        $user = $this->createApiUser();
        $filename = self::MEDIAKEY . '_1700000000_720p.mp4';

        Storage::disk('converted')->put($filename, 'converted-body');
        $this->createDownloadWithVideos($user, [
            ['file' => $filename, 'processed' => Video::PROCESSED],
        ]);

        $response = $this->withApiToken($user)
            ->get('/api/download/' . $filename);

        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);

        $otherUser = $this->createApiUser([
            'email' => 'other@example.org',
            'api_token' => str_repeat('b', 32),
        ]);

        $this->withApiToken($otherUser)
            ->get('/api/download/' . $filename)
            ->assertStatus(500);
    }

    public function test_download_file_returns_404_when_database_row_exists_but_file_is_missing(): void
    {
        Storage::fake('converted');
        $user = $this->createApiUser();
        $filename = self::MEDIAKEY . '_1700000000_720p.mp4';

        $this->createDownloadWithVideos($user, [
            ['file' => $filename, 'processed' => Video::PROCESSED],
        ]);

        $this->withApiToken($user)
            ->get('/api/download/' . $filename)
            ->assertStatus(404)
            ->assertJson(['message' => 'File not found']);
    }

    public function test_finished_endpoint_marks_video_as_downloaded(): void
    {
        $user = $this->createApiUser();
        $filename = self::MEDIAKEY . '_1700000000_720p.mp4';

        $this->createDownloadWithVideos($user, [
            ['file' => $filename, 'processed' => Video::PROCESSED],
        ]);

        $this->withApiToken($user)
            ->postJson('/api/download/' . $filename . '/finished')
            ->assertOk()
            ->assertExactJson(['message' => 'ok']);

        $this->assertNotNull(Video::where('file', $filename)->firstOrFail()->downloaded_at);
    }

    public function test_status_endpoint_returns_current_percentage_contract(): void
    {
        $user = $this->createApiUser();

        $this->createDownloadWithVideos($user, [
            ['file' => self::MEDIAKEY . '_1700000000_720p.mp4', 'processed' => Video::PROCESSED],
            ['file' => self::MEDIAKEY . '_1700000000_380p.mp4', 'processed' => Video::UNPROCESSED],
        ]);

        $statusResponse = $this->withApiToken($user)
            ->get('/api/status/' . self::MEDIAKEY);

        $statusResponse->assertOk();
        $this->assertSame('50', $statusResponse->getContent());

        $missingResponse = $this->withApiToken($user)
            ->get('/api/status/' . str_repeat('9', 32));

        $missingResponse->assertStatus(404);
        $this->assertSame('"Not found"', $missingResponse->getContent());
    }

    public function test_delete_endpoint_removes_download_video_rows_and_storage_artifacts(): void
    {
        Storage::fake('uploaded');
        Storage::fake('converted');
        $user = $this->createApiUser();
        $filename = self::MEDIAKEY . '_1700000000_720p.mp4';

        Storage::disk('uploaded')->put(self::MEDIAKEY, 'source');
        Storage::disk('converted')->put($filename, 'converted');

        $download = $this->createDownloadWithVideos($user, [
            ['file' => $filename, 'processed' => Video::PROCESSED],
        ]);

        DownloadJob::create([
            'download_id' => $download->id,
            'job_id' => 123,
        ]);

        $this->withApiToken($user)
            ->delete('/api/delete/' . self::MEDIAKEY)
            ->assertOk();

        $this->assertDatabaseMissing('downloads', ['id' => $download->id]);
        $this->assertDatabaseMissing('videos', ['mediakey' => self::MEDIAKEY]);
        $this->assertDatabaseMissing('download_jobs', ['download_id' => $download->id]);
        Storage::disk('uploaded')->assertMissing(self::MEDIAKEY);
        Storage::disk('converted')->assertMissing($filename);
    }

    public function test_download_job_fetches_source_from_vimp_and_fans_out_work_items(): void
    {
        Queue::fake();
        Storage::fake('uploaded');
        $server = FakeVimpServer::start('video-source-body');

        try {
            $user = $this->createApiUser(['url' => $server->url()]);
            $payload = $this->transcodePayload([
                'source' => [
                    'url' => $server->url('/transcoderwebservice/source/input.mp4'),
                    'created_at' => 1700000000,
                ],
            ]);
            unset($payload['api_token']);

            $download = Download::create([
                'user_id' => $user->id,
                'mediakey' => self::MEDIAKEY,
                'processed' => Download::UNPROCESSED,
                'payload' => $payload,
            ]);

            (new DownloadFileJob($download))->handle();

            Storage::disk('uploaded')->assertExists(self::MEDIAKEY);
            $this->assertSame('video-source-body', Storage::disk('uploaded')->get(self::MEDIAKEY));

            $sourceRequests = $server->requestsFor('/transcoderwebservice/source/input.mp4');
            $this->assertCount(1, $sourceRequests);
            $this->assertSame(['api_token' => self::API_TOKEN], json_decode($sourceRequests[0]['body'], true));

            Queue::assertPushed(CreateThumbnailJob::class, 1);
            Queue::assertPushed(CreateSpritemapJob::class, 1);
            Queue::assertPushed(ConvertPreviewVideoJob::class, 3);
            Queue::assertPushed(ConvertHLSVideoJob::class, 3);
            Queue::assertPushed(ConvertVideoJob::class, 3);

            $this->assertSame(11, Video::where('download_id', $download->id)->count());
        } finally {
            $server->stop();
        }
    }

    public function test_hls_callback_payload_matches_current_vimp_contract(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required for HLS archive contract test.');
        }

        Storage::fake('converted');
        $server = FakeVimpServer::start();

        try {
            $user = $this->createApiUser(['url' => $server->url()]);
            $download = $this->createDownloadWithVideos($user, []);
            $video = $this->createVideo($download, [
                'file' => null,
                'processed' => Video::PROCESSING,
                'target' => [
                    'label' => '720p',
                    'size' => '1280x720',
                    'vbr' => 2400,
                    'abr' => 128,
                    'extension' => 'mp4',
                    'created_at' => 1700000000,
                    'default' => false,
                ],
            ]);

            Storage::disk('converted')->put(self::MEDIAKEY . '_720p_mp4/playlist.m3u8', '#EXTM3U');
            Storage::disk('converted')->put(self::MEDIAKEY . '_720p_mp4/segment_000.ts', 'segment');

            $transcoder = new TranscodingController($video, new Dimension(1280, 720), 1);
            $transcoder->setHLS(true);
            $transcoder->executeCallback();

            $callbacks = $server->requestsFor('/transcoderwebservice/callback');
            $this->assertCount(1, $callbacks);

            $payload = json_decode($callbacks[0]['body'], true);
            $archiveFile = self::MEDIAKEY . '_720p_mp4.zip';

            $this->assertSame(self::API_TOKEN, $payload['api_token']);
            $this->assertSame(self::MEDIAKEY, $payload['mediakey']);
            $this->assertSame([
                'label' => '720p',
                'url' => 'http://transcoder.test/api/download/' . $archiveFile,
                'hls' => true,
                'vbr' => 2400,
                'abr' => 128,
                'size' => '1280x720',
                'extension' => 'mp4',
                'created_at' => 1700000000,
                'default' => false,
                'checksum' => md5_file(Storage::disk('converted')->path($archiveFile)),
            ], $payload['medium']);

            $this->assertSame($archiveFile, $video->fresh()->file);
            Storage::disk('converted')->assertExists($archiveFile);
        } finally {
            $server->stop();
        }
    }

    public function test_final_callback_payload_matches_current_vimp_contract(): void
    {
        $server = FakeVimpServer::start();

        try {
            $user = $this->createApiUser(['url' => $server->url()]);
            $download = $this->createDownloadWithVideos($user, []);
            $video = $this->createVideo($download, [
                'processed' => Video::PROCESSED,
            ]);

            $transcoder = new TranscodingController($video, new Dimension(1280, 720), 1);
            $transcoder->executeFinalCallback();

            $callbacks = $server->requestsFor('/transcoderwebservice/callback');
            $this->assertCount(1, $callbacks);

            $payload = json_decode($callbacks[0]['body'], true);
            $this->assertSame([
                'api_token' => self::API_TOKEN,
                'mediakey' => self::MEDIAKEY,
                'finished' => true,
            ], $payload);
        } finally {
            $server->stop();
        }
    }

    public function test_error_callback_payload_matches_current_vimp_contract(): void
    {
        $server = FakeVimpServer::start();

        try {
            $user = $this->createApiUser(['url' => $server->url()]);
            $download = $this->createDownloadWithVideos($user, []);
            $video = $this->createVideo($download, [
                'processed' => Video::FAILED,
            ]);

            TranscodingController::executeErrorCallback($video, 'transcoding failed');

            $callbacks = $server->requestsFor('/transcoderwebservice/callback');
            $this->assertCount(1, $callbacks);

            $payload = json_decode($callbacks[0]['body'], true);
            $this->assertSame([
                'api_token' => self::API_TOKEN,
                'mediakey' => self::MEDIAKEY,
                'error' => [
                    'message' => 'transcoding failed',
                ],
            ], $payload);
        } finally {
            $server->stop();
        }
    }

    private function withApiToken(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $user->api_token);
    }

    private function createApiUser(array $attributes = []): User
    {
        DB::table('profiles')->insertOrIgnore([
            'id' => 1,
            'encoder' => 'libx264',
            'fallback_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = new User();
        $user->name = $attributes['name'] ?? 'VIMP';
        $user->email = $attributes['email'] ?? 'vimp@example.org';
        $user->password = bcrypt('secret');
        $user->api_token = $attributes['api_token'] ?? self::API_TOKEN;
        $user->url = $attributes['url'] ?? 'http://vimp.test';
        $user->profile_id = $attributes['profile_id'] ?? 1;
        $user->save();

        return $user;
    }

    private function createDownloadWithVideos(User $user, array $videos): Download
    {
        $download = Download::create([
            'user_id' => $user->id,
            'mediakey' => self::MEDIAKEY,
            'processed' => Download::PROCESSING,
            'payload' => $this->transcodePayload(),
        ]);

        foreach ($videos as $video) {
            $this->createVideo($download, $video);
        }

        return $download;
    }

    private function createVideo(Download $download, array $attributes = []): Video
    {
        return Video::create([
            'user_id' => $download->user_id,
            'download_id' => $download->id,
            'disk' => 'uploaded',
            'mediakey' => self::MEDIAKEY,
            'path' => self::MEDIAKEY,
            'title' => $attributes['title'] ?? ConvertVideoJob::class,
            'target' => $attributes['target'] ?? [
                'label' => '720p',
                'size' => '1280x720',
                'vbr' => 2400,
                'abr' => 128,
                'extension' => 'mp4',
                'created_at' => 1700000000,
                'default' => false,
            ],
            'file' => $attributes['file'] ?? null,
            'processed' => $attributes['processed'] ?? Video::UNPROCESSED,
            'downloaded_at' => $attributes['downloaded_at'] ?? null,
            'converted_at' => $attributes['converted_at'] ?? null,
        ]);
    }

    private function transcodePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'api_token' => self::API_TOKEN,
            'mediakey' => self::MEDIAKEY,
            'source' => [
                'url' => '/transcoderwebservice/source/input.mp4',
                'created_at' => 1700000000,
            ],
            'target' => [
                'start' => 3,
                'duration' => 12,
                'hls' => true,
                'format' => [
                    [
                        'label' => '1080p',
                        'size' => '1920x1080',
                        'vbr' => 4500,
                        'abr' => 128,
                        'extension' => 'mp4',
                        'default' => true,
                    ],
                    [
                        'label' => '720p',
                        'size' => '1280x720',
                        'vbr' => 2400,
                        'abr' => 128,
                        'extension' => 'mp4',
                        'default' => false,
                    ],
                    [
                        'label' => '380p',
                        'size' => '640x380',
                        'vbr' => 900,
                        'abr' => 96,
                        'extension' => 'mp4',
                        'default' => false,
                    ],
                ],
            ],
            'thumbnail' => [
                'poster' => ['second' => 1],
            ],
            'spritemap' => [
                'count' => 12,
                'width' => 142,
                'height' => 80,
            ],
        ], $overrides);
    }
}
