#!/bin/sh
set -eu

MODE="${1:-gpu}"

case "$MODE" in
    cpu)
        exec ffmpeg-cpu-smoke
        ;;
    gpu)
        exec ffmpeg-gpu-smoke
        ;;
    *)
        echo "Usage: ffmpeg-smoke [cpu|gpu]" >&2
        exit 64
        ;;
esac
