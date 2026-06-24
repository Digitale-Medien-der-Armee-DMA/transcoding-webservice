<?php

namespace Tests\Feature;

use App\Jobs\DownloadFileJob;
use App\Models\Download;
use App\Models\Profile;
use App\Models\User;
use App\Models\Video;
use App\Services\Security\MediaPathGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeVimpServer;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const API_TOKEN = '12345678901234567890123456789012';
    private const MEDIAKEY = 'abcdefabcdefabcdefabcdefabcdef12';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'security.source_url.allowlist_enabled' => false,
            'security.source_url.allowed_hosts' => [],
            'security.source_url.allow_user_host' => true,
            'security.downloads.max_bytes' => 0,
        ]);
    }

    public function test_source_url_allowlist_rejects_unlisted_vimp_host_when_enabled(): void
    {
        $user = $this->createApiUser(['url' => 'http://vimp.test']);

        config([
            'security.source_url.allowlist_enabled' => true,
            'security.source_url.allowed_hosts' => ['media.example.org'],
            'security.source_url.allow_user_host' => false,
        ]);

        $response = $this->withApiToken($user)->postJson('/api/transcode', $this->transcodePayload());

        $response
            ->assertStatus(400)
            ->assertJson([
                'message' => ['Source URL is not allowed'],
                'status' => 'failed',
            ]);

        $this->assertSame(0, Download::count());
    }

    public function test_source_url_allowlist_can_allow_configured_user_host(): void
    {
        Queue::fake();
        $user = $this->createApiUser(['url' => 'http://vimp.test']);

        config([
            'security.source_url.allowlist_enabled' => true,
            'security.source_url.allowed_hosts' => [],
            'security.source_url.allow_user_host' => true,
        ]);

        $this->withApiToken($user)
            ->postJson('/api/transcode', $this->transcodePayload())
            ->assertOk();

        $this->assertSame(1, Download::count());
        Queue::assertPushedOn('download', DownloadFileJob::class);
    }

    public function test_media_path_guard_rejects_traversal_absolute_and_empty_paths(): void
    {
        $guard = app(MediaPathGuard::class);

        $this->assertTrue($guard->isSafeRelativePath(self::MEDIAKEY . '_720p_mp4/playlist.m3u8'));
        $this->assertFalse($guard->isSafeRelativePath('../secret.mp4'));
        $this->assertFalse($guard->isSafeRelativePath('/var/tmp/secret.mp4'));
        $this->assertFalse($guard->isSafeRelativePath(''));
    }

    public function test_finished_endpoint_only_marks_files_owned_by_authenticated_user(): void
    {
        $owner = $this->createApiUser();
        $otherUser = $this->createApiUser([
            'email' => 'other@example.org',
            'api_token' => str_repeat('b', 32),
        ]);
        $filename = self::MEDIAKEY . '_1700000000_720p.mp4';
        $download = $this->createDownload($owner);
        $video = $this->createVideo($download, ['file' => $filename]);

        $this->withApiToken($otherUser)
            ->postJson('/api/download/' . $filename . '/finished')
            ->assertStatus(404)
            ->assertJson(['message' => 'File not found']);

        $this->assertNull($video->fresh()->downloaded_at);
    }

    public function test_download_job_stops_when_configured_source_size_limit_is_exceeded(): void
    {
        Queue::fake();
        Storage::fake('uploaded');
        config(['security.downloads.max_bytes' => 4]);
        $server = FakeVimpServer::start('video-source-body');

        try {
            $user = $this->createApiUser(['url' => $server->url()]);
            $download = Download::create([
                'user_id' => $user->id,
                'mediakey' => self::MEDIAKEY,
                'processed' => Download::UNPROCESSED,
                'payload' => $this->transcodePayload([
                    'source' => [
                        'url' => $server->url('/transcoderwebservice/source/input.mp4'),
                        'created_at' => 1700000000,
                    ],
                ]),
            ]);

            (new DownloadFileJob($download))->handle();

            Storage::disk('uploaded')->assertMissing(self::MEDIAKEY);
            $this->assertSame(Download::FAILED, $download->fresh()->processed);
            $this->assertSame(0, Video::count());
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
        Profile::query()->insertOrIgnore([
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

    private function createDownload(User $user): Download
    {
        return Download::create([
            'user_id' => $user->id,
            'mediakey' => self::MEDIAKEY,
            'processed' => Download::PROCESSING,
            'payload' => $this->transcodePayload(),
        ]);
    }

    private function createVideo(Download $download, array $attributes = []): Video
    {
        return Video::create([
            'user_id' => $download->user_id,
            'download_id' => $download->id,
            'disk' => 'uploaded',
            'mediakey' => self::MEDIAKEY,
            'path' => self::MEDIAKEY,
            'title' => DownloadFileJob::class,
            'target' => [],
            'file' => $attributes['file'] ?? null,
            'processed' => $attributes['processed'] ?? Video::PROCESSED,
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
                'format' => [
                    [
                        'label' => '720p',
                        'size' => '1280x720',
                        'vbr' => 2400,
                        'abr' => 128,
                        'extension' => 'mp4',
                        'default' => true,
                    ],
                ],
            ],
        ], $overrides);
    }
}
