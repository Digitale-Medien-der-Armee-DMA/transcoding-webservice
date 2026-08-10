<?php

namespace Tests\Unit;

use App\Services\VideoFilterGraph;
use PHPUnit\Framework\TestCase;

class VideoFilterGraphTest extends TestCase
{
    public function test_nvenc_uses_the_available_cuda_scaler(): void
    {
        $filter = (new VideoFilterGraph())->forEncoder('h264_nvenc', 1920, 1080);

        $this->assertSame(
            'scale_cuda=w=1920:h=1080:force_original_aspect_ratio=decrease:force_divisible_by=2:interp_algo=lanczos',
            $filter
        );
        $this->assertStringNotContainsString('scale_npp', $filter);
        $this->assertStringNotContainsString('hwupload', $filter);
    }

    public function test_cpu_filter_preserves_even_dimensions(): void
    {
        $filter = (new VideoFilterGraph())->forEncoder('libx264', 854, 480);

        $this->assertSame(
            "scale=w=854:h=480:force_original_aspect_ratio=decrease,crop='iw-mod(iw\\,2)':'ih-mod(ih\\,2)'",
            $filter
        );
    }
}
