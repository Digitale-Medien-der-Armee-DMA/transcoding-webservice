#!/bin/sh
set -eu

DURATION="${FFMPEG_SMOKE_DURATION:-2}"
SIZE="${FFMPEG_SMOKE_SIZE:-1280x720}"
RATE="${FFMPEG_SMOKE_RATE:-30}"
WORKDIR="$(mktemp -d)"
INPUT="${WORKDIR}/input-h264.mp4"
OUTPUT="${WORKDIR}/gpu-nvenc.mp4"

cleanup() {
    rm -rf "$WORKDIR"
}
trap cleanup EXIT INT TERM

command -v nvidia-smi >/dev/null 2>&1 || {
    echo "nvidia-smi is not available. Check NVIDIA Container Toolkit and driver capabilities." >&2
    exit 1
}

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

ffmpeg -hide_banner -decoders 2>/dev/null | grep -Eq "h264_cuvid|hevc_cuvid|av1_cuvid|h264" || {
    echo "No expected H.264/NVDEC-capable decoder is visible in FFmpeg." >&2
    exit 1
}

ffmpeg -hide_banner -nostdin -y \
    -f lavfi -i "testsrc2=size=${SIZE}:rate=${RATE}" \
    -t "$DURATION" \
    -c:v libx264 \
    -pix_fmt yuv420p \
    "$INPUT"

ffmpeg -hide_banner -nostdin -y \
    -f lavfi -i "testsrc2=size=${SIZE}:rate=${RATE}" \
    -t "$DURATION" \
    -c:v h264_nvenc \
    -preset p4 \
    -b:v 2500k \
    -pix_fmt yuv420p \
    "$OUTPUT"

ffmpeg -hide_banner -nostdin -y \
    -hwaccel cuda \
    -i "$INPUT" \
    -f null -

VIDEO_CODEC="$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 "$OUTPUT")"

if [ "$VIDEO_CODEC" != "h264" ]; then
    echo "Unexpected NVENC output codec: ${VIDEO_CODEC}" >&2
    exit 1
fi

echo "GPU FFmpeg smoke passed: NVENC output=${VIDEO_CODEC}, CUDA decode path completed"
