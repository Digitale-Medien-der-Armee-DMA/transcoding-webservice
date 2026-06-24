#!/bin/sh
set -eu

DURATION="${FFMPEG_SMOKE_DURATION:-2}"
SIZE="${FFMPEG_SMOKE_SIZE:-1280x720}"
RATE="${FFMPEG_SMOKE_RATE:-30}"
WORKDIR="$(mktemp -d)"
OUTPUT="${WORKDIR}/cpu-smoke.mp4"

cleanup() {
    rm -rf "$WORKDIR"
}
trap cleanup EXIT INT TERM

echo "ffmpeg:"
ffmpeg -hide_banner -version | head -n 1
echo "ffprobe:"
ffprobe -hide_banner -version | head -n 1

ffmpeg -hide_banner -nostdin -y \
    -f lavfi -i "testsrc2=size=${SIZE}:rate=${RATE}" \
    -f lavfi -i "sine=frequency=1000:sample_rate=48000" \
    -t "$DURATION" \
    -c:v libx264 \
    -preset veryfast \
    -pix_fmt yuv420p \
    -c:a aac \
    -b:a 128k \
    -movflags +faststart \
    "$OUTPUT"

VIDEO_INFO="$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name,width,height -of csv=p=0 "$OUTPUT")"
AUDIO_CODEC="$(ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 "$OUTPUT")"

case "$VIDEO_INFO" in
    h264,*) ;;
    *)
        echo "Unexpected video stream: ${VIDEO_INFO}" >&2
        exit 1
        ;;
esac

if [ "$AUDIO_CODEC" != "aac" ]; then
    echo "Unexpected audio codec: ${AUDIO_CODEC}" >&2
    exit 1
fi

echo "CPU FFmpeg smoke passed: ${VIDEO_INFO}, audio=${AUDIO_CODEC}"
