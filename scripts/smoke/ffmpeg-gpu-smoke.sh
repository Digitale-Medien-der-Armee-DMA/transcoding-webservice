#!/bin/sh
set -eu

DURATION="${FFMPEG_SMOKE_DURATION:-2}"
SIZE="${FFMPEG_SMOKE_SIZE:-1280x720}"
RATE="${FFMPEG_SMOKE_RATE:-30}"
TARGET_SIZE="${FFMPEG_SMOKE_TARGET_SIZE:-640x360}"
TARGET_WIDTH="${TARGET_SIZE%x*}"
TARGET_HEIGHT="${TARGET_SIZE#*x}"
WORKDIR="$(mktemp -d)"
INPUT="${WORKDIR}/input-h264.mp4"
OUTPUT="${WORKDIR}/gpu-nvenc.mp4"
HLS_OUTPUT="${WORKDIR}/gpu-nvenc.m3u8"
HLS_SEGMENTS="${WORKDIR}/gpu-nvenc-%03d.ts"

cleanup() {
    rm -rf "$WORKDIR"
}
trap cleanup EXIT INT TERM

gpu-worker-preflight

echo "nvidia-smi:"
nvidia-smi

echo "ffmpeg:"
ffmpeg -hide_banner -version | head -n 1

echo "ffmpeg hwaccels:"
ffmpeg -hide_banner -hwaccels

ffmpeg -hide_banner -encoders 2>/dev/null | grep -q "h264_nvenc" || {
    echo "FFmpeg encoder h264_nvenc is not available in this image." >&2
    exit 1
}

ffmpeg -hide_banner -filters 2>/dev/null | grep -q "scale_cuda" || {
    echo "FFmpeg filter scale_cuda is not available in this image." >&2
    exit 1
}

ffmpeg -hide_banner -decoders 2>/dev/null | grep -Eq "h264_cuvid|hevc_cuvid|av1_cuvid|h264" || {
    echo "No expected H.264/NVDEC-capable decoder is visible in FFmpeg." >&2
    exit 1
}

ffmpeg -hide_banner -nostdin -y \
    -f lavfi -i "testsrc2=size=${SIZE}:rate=${RATE}" \
    -f lavfi -i "sine=frequency=1000:sample_rate=48000" \
    -t "$DURATION" \
    -shortest \
    -c:v libx264 \
    -c:a aac \
    -pix_fmt yuv420p \
    "$INPUT"

ffmpeg -hide_banner -nostdin -y \
    -hwaccel cuda \
    -hwaccel_output_format cuda \
    -i "$INPUT" \
    -vf "scale_cuda=w=${TARGET_WIDTH}:h=${TARGET_HEIGHT}:force_original_aspect_ratio=decrease:force_divisible_by=2:interp_algo=lanczos" \
    -c:v h264_nvenc \
    -preset p4 \
    -b:v 2500k \
    -c:a aac \
    -ac 2 \
    "$OUTPUT"

ffmpeg -hide_banner -nostdin -y \
    -hwaccel cuda \
    -hwaccel_output_format cuda \
    -i "$INPUT" \
    -vf "scale_cuda=w=${TARGET_WIDTH}:h=${TARGET_HEIGHT}:force_original_aspect_ratio=decrease:force_divisible_by=2:interp_algo=lanczos" \
    -c:v h264_nvenc \
    -preset p4 \
    -b:v 2500k \
    -c:a aac \
    -ac 2 \
    -hls_time 1 \
    -hls_playlist_type vod \
    -hls_segment_filename "$HLS_SEGMENTS" \
    "$HLS_OUTPUT"

VIDEO_CODEC="$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 "$OUTPUT")"
AUDIO_CODEC="$(ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 "$OUTPUT")"
OUTPUT_SIZE="$(ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 "$OUTPUT")"

if [ "$VIDEO_CODEC" != "h264" ]; then
    echo "Unexpected NVENC output codec: ${VIDEO_CODEC}" >&2
    exit 1
fi

if [ "$AUDIO_CODEC" != "aac" ]; then
    echo "Unexpected NVENC output audio codec: ${AUDIO_CODEC}" >&2
    exit 1
fi

if [ "$OUTPUT_SIZE" != "$TARGET_SIZE" ]; then
    echo "Unexpected NVENC output size: ${OUTPUT_SIZE}" >&2
    exit 1
fi

test -s "$HLS_OUTPUT"
find "$WORKDIR" -maxdepth 1 -name 'gpu-nvenc-*.ts' -size +0c | grep -q .

echo "GPU worker smoke passed: NVENC=${VIDEO_CODEC}, audio=${AUDIO_CODEC}, size=${OUTPUT_SIZE}, HLS and CUDA scaling completed"
