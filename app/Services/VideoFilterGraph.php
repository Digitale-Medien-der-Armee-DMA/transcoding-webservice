<?php

namespace App\Services;

class VideoFilterGraph
{
    public function forEncoder(string $encoder, int $width, int $height): string
    {
        switch ($encoder) {
            case 'h264_vaapi':
                return "scale_vaapi=w='if(gt(a\\,{$width}/{$height})\\,{$width}\\,oh*a)':h='if(gt(a\\,{$width}/{$height})\\,ow/a\\,{$height})'";

            case 'h264_nvenc':
                return "scale_cuda=w={$width}:h={$height}:force_original_aspect_ratio=decrease:force_divisible_by=2:interp_algo=lanczos";

            default:
                return "scale=w={$width}:h={$height}:force_original_aspect_ratio=decrease,crop='iw-mod(iw\\,2)':'ih-mod(ih\\,2)'";
        }
    }
}
