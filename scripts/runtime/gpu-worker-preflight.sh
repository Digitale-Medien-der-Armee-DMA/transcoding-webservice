#!/bin/sh
set -eu

ENCODER="${GPU_VIDEO_ENCODER:-h264_nvenc}"
FILTER="${GPU_VIDEO_FILTER:-scale_cuda}"

command -v nvidia-smi >/dev/null 2>&1 || {
  echo "GPU preflight failed: nvidia-smi is unavailable" >&2
  exit 1
}

nvidia-smi -L >/dev/null 2>&1 || {
  echo "GPU preflight failed: no NVIDIA GPU is visible" >&2
  exit 1
}

ffmpeg -hide_banner -encoders 2>/dev/null | grep -q "${ENCODER}" || {
  echo "GPU preflight failed: FFmpeg encoder ${ENCODER} is unavailable" >&2
  exit 1
}

ffmpeg -hide_banner -filters 2>/dev/null | grep -q "${FILTER}" || {
  echo "GPU preflight failed: FFmpeg filter ${FILTER} is unavailable" >&2
  exit 1
}

ffmpeg -hide_banner -hwaccels 2>/dev/null | grep -qx 'cuda' || {
  echo "GPU preflight failed: FFmpeg CUDA acceleration is unavailable" >&2
  exit 1
}

echo "GPU preflight passed: encoder=${ENCODER}, filter=${FILTER}"
