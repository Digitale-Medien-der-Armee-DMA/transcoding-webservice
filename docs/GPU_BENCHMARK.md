# GPU Transcoding Benchmark

Status: 2026-08-11

This benchmark compares the same VIMP source, target formats, and callback flow between the `libx264` and `h264_nvenc` profiles.

## Recorded CPU Baseline

The initial target-host baseline used:

- GPU host: two NVIDIA L40S cards, driver `580.173.02`
- Effective encoder: `libx264`
- Source duration: `185.8` seconds
- HLS 480p conversion time: `47` seconds
- Throughput: `3.95x` real time
- Worker CPU: approximately `1212%`
- Worker memory: approximately `841 MiB`
- NVENC utilization: `0%`

This is a per-output baseline. A complete VIMP request can create multiple MP4 and HLS outputs, which the single video worker processes sequentially.

## Test Procedure

1. Use one source file and one unchanged VIMP format set for both profiles.
2. Clear or record pre-existing failed-job counters before each run.
3. Record the source duration with `ffprobe`.
4. Record each `Conversion ... took N seconds` log entry.
5. Capture worker CPU and memory with `docker stats`.
6. Capture GPU encoder utilization, decoder utilization, VRAM, and power once per second.
7. Verify every MP4/HLS callback and the final VIMP callback.
8. Probe every generated output for codec, dimensions, duration, and audio.

Monitoring commands:

```bash
cid=$(docker compose --env-file .env -f compose.yaml ps -q worker-video-gpu)
docker stats "$cid"

nvidia-smi \
  --query-gpu=timestamp,name,utilization.gpu,utilization.encoder,utilization.decoder,memory.used,memory.free,power.draw \
  --format=csv \
  --loop=1

docker compose --env-file .env -f compose.yaml exec worker-video-gpu \
  sh -lc 'tail -F storage/logs/laravel.log | grep -aE "Trying to encode clip|transcoded|Conversion in|Transcoding failed"'
```

## Calculations

For each output:

```text
real-time factor = source duration seconds / conversion wall-clock seconds
```

For the complete request:

```text
request throughput = source duration seconds / time from accepted request to final callback
```

## Acceptance

The GPU benchmark passes when:

- The effective command contains `h264_nvenc` and `scale_cuda`.
- NVENC utilization rises above zero during encoding.
- MP4 and HLS outputs pass `ffprobe` validation and are playable.
- VIMP receives the same callback fields as in the CPU baseline.
- No GPU worker restart, CUDA error, duplicate callback, or terminal queue failure occurs.
- GPU throughput and CPU reduction are recorded against the CPU baseline.
- The CPU fallback succeeds when NVENC is deliberately made unavailable for one controlled test.
