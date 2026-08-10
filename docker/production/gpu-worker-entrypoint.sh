#!/bin/sh
set -eu

if [ "${GPU_WORKER_PREFLIGHT:-true}" = "true" ]; then
  gpu-worker-preflight
fi

exec app-entrypoint "$@"
