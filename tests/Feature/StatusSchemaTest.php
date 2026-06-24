<?php

namespace Tests\Feature;

use App\Models\Download;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_and_video_status_values_are_persisted_as_integers(): void
    {
        foreach ($this->statusValues() as $status) {
            $download = Download::create([
                'user_id' => 1,
                'mediakey' => 'download-' . $status,
                'payload' => ['mediakey' => 'download-' . $status],
                'processed' => $status,
            ]);

            $video = Video::create([
                'user_id' => 1,
                'download_id' => $download->id,
                'title' => 'video-' . $status,
                'mediakey' => $download->mediakey,
                'disk' => 'uploaded',
                'path' => $download->mediakey,
                'target' => [],
                'processed' => $status,
            ]);

            $this->assertSame($status, $download->fresh()->processed);
            $this->assertSame($status, $video->fresh()->processed);
        }
    }

    public function test_failed_status_and_failed_jobs_are_reported_in_metrics(): void
    {
        $download = Download::create([
            'user_id' => 1,
            'mediakey' => 'failed-mediakey',
            'payload' => ['mediakey' => 'failed-mediakey'],
            'processed' => Download::PROCESSING,
        ]);

        Video::create([
            'user_id' => 1,
            'download_id' => $download->id,
            'title' => 'failed-video',
            'mediakey' => $download->mediakey,
            'disk' => 'uploaded',
            'path' => $download->mediakey,
            'target' => [],
            'processed' => Video::FAILED,
            'failed_at' => Carbon::parse('2026-06-24 12:30:00'),
        ]);

        DB::table('failed_jobs')->insert([
            'connection' => 'redis',
            'queue' => 'video',
            'payload' => '{}',
            'exception' => 'transcoding failed',
            'failed_at' => Carbon::parse('2026-06-24 12:31:00'),
        ]);

        $this->getJson('/internal/metrics')
            ->assertOk()
            ->assertJson([
                'transcoding' => [
                    'failed_jobs' => 1,
                    'failed_videos' => 1,
                    'error_rate' => 1,
                ],
            ]);
    }

    public function test_admin_status_maps_expose_all_four_status_values(): void
    {
        $expected = [
            '0' => 'unprocessed',
            '1' => 'processed',
            '2' => 'processing',
            '3' => 'failed',
        ];

        $this->assertSame($expected, Download::$status);
        $this->assertSame($expected, Video::$status);
    }

    private function statusValues(): array
    {
        return [
            Download::UNPROCESSED,
            Download::PROCESSED,
            Download::PROCESSING,
            Download::FAILED,
        ];
    }
}
