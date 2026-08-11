<?php

namespace App\Services;

use App\Http\Controllers\TranscodingController;
use App\Models\Download;
use App\Models\Video;
use FFMpeg;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class VimpCallbackPayloadBuilder
{
    public function medium(Video $video): array
    {
        $file = $video->file;
        $this->requireConvertedFile($file);

        $ffprobe = FFMpeg\FFProbe::create(TranscodingController::getFFmpegConfig());
        $sourceFormat = $ffprobe->streams(Storage::disk('uploaded')->path($video->path))->videos()->first();
        $targetFormat = $ffprobe->streams(Storage::disk('converted')->path($file))->videos()->first();
        $tags = $targetFormat->get('tags');
        $orientation = empty($tags['rotate']) ? 0 : (int) $tags['rotate'];

        $payload = [
            'mediakey' => $video->mediakey,
            'medium' => $this->filterMediumFields([
                'label' => $this->label($video->target['label']),
                'url' => $this->artifactUrl($file),
                'checksum' => md5_file(Storage::disk('converted')->path($file)),
                'default' => $video->target['default'] ?? false,
            ]),
        ];

        if ((bool) config('vimp_callbacks.include_properties', true)) {
            $payload['properties'] = [
                'source_width' => $sourceFormat->get('width'),
                'source_height' => $sourceFormat->get('height'),
                'duration' => round($targetFormat->get('duration'), 0),
                'filesize' => filesize(Storage::disk('converted')->path($file)),
                'width' => $targetFormat->get('width'),
                'height' => $targetFormat->get('height'),
                'orientation' => $orientation,
                'vbitrate' => $targetFormat->get('bit_rate'),
                'source_is360video' => $this->is360Video($sourceFormat),
            ];
        }

        return $payload;
    }

    public function hlsMedium(Video $video, string $archiveFile): array
    {
        $this->requireConvertedFile($archiveFile);

        return [
            'mediakey' => $video->mediakey,
            'medium' => $this->filterMediumFields([
                'label' => $this->label($video->target['label']),
                'url' => $this->artifactUrl($archiveFile),
                'hls' => true,
                'vbr' => $video->target['vbr'],
                'abr' => $video->target['abr'],
                'size' => $video->target['size'],
                'extension' => $video->target['extension'],
                'created_at' => $video->target['created_at'],
                'default' => $video->target['default'] ?? false,
                'checksum' => md5_file(Storage::disk('converted')->path($archiveFile)),
            ]),
        ];
    }

    public function thumbnail(Video $video): array
    {
        $this->requireConvertedFile($video->file);

        return [
            'mediakey' => $video->mediakey,
            'thumbnail' => ['url' => $this->artifactUrl($video->file)],
        ];
    }

    public function spritemap(Video $video): array
    {
        $this->requireConvertedFile($video->file);

        return [
            'mediakey' => $video->mediakey,
            'spritemap' => [
                'count' => $video->target['spritemap']['count'],
                'url' => $this->artifactUrl($video->file),
            ],
        ];
    }

    public function finished(Download $download): array
    {
        return ['mediakey' => $download->mediakey, 'finished' => true];
    }

    public function error(Video $video, string $message): array
    {
        return ['mediakey' => $video->mediakey, 'error' => ['message' => $message]];
    }

    private function requireConvertedFile(?string $file): void
    {
        if (!$file || !Storage::disk('converted')->exists($file)) {
            throw new RuntimeException('Converted callback artifact is missing: ' . ($file ?: '[unset]'));
        }
    }

    private function artifactUrl(string $file): string
    {
        $baseUrl = config('vimp_callbacks.artifact_base_url');

        if (!$baseUrl) {
            return route('getFile', $file);
        }

        return rtrim($baseUrl, '/') . '/api/download/' . rawurlencode($file);
    }

    private function label(string $label): string
    {
        $configured = config('vimp_callbacks.label_map');
        $mapping = is_array($configured) ? $configured : json_decode((string) $configured, true);

        return is_array($mapping) && isset($mapping[$label]) ? (string) $mapping[$label] : $label;
    }

    private function filterMediumFields(array $medium): array
    {
        $configured = trim((string) config('vimp_callbacks.medium_fields'));

        if ($configured === '') {
            return $medium;
        }

        return Arr::only($medium, array_filter(array_map('trim', explode(',', $configured))));
    }

    private function is360Video($sourceFormat): bool
    {
        $sideData = $sourceFormat->get('side_data_list')[0] ?? null;

        return isset($sideData['side_data_type'])
            && Str::contains(Arr::get($sideData, 'side_data_type'), 'Spherical Mapping');
    }
}
