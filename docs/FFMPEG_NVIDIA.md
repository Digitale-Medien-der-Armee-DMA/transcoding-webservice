# FFmpeg and NVIDIA GPU Runtime

Status: 2026-08-11

The production video worker uses a dedicated image built by `docker/production/Dockerfile.worker-gpu`. It contains the same Laravel application and PHP 7.4 queue runtime as the other workers, plus a pinned FFmpeg 6.1.1 build with NVENC, NVDEC, CUDA hardware acceleration, `scale_cuda`, and CPU `libx264` fallback support.

## Runtime Contract

The GPU worker build is pinned to:

- PHP base: `php:7.4-cli-bullseye`
- FFmpeg: `6.1.1`, verified by SHA-256
- NVCodec headers: `12.1.14.0`, verified by SHA-256
- GPU encoder: `h264_nvenc`
- GPU scaler: `scale_cuda`
- CPU fallback encoder: `libx264`

FFmpeg is compiled with `cuda-llvm` on the same Debian 11 ABI as the PHP worker. NVIDIA driver libraries are not installed in the image. The host driver is injected by NVIDIA Container Toolkit through the `compute`, `utility`, and `video` capabilities.

The general `app`, `worker-download`, and `scheduler` services remain on the regular application image. Only `worker-video-gpu` uses the GPU image and receives an NVIDIA device reservation.

## Startup Preflight

The worker does not consume queue jobs until `gpu-worker-preflight` confirms:

- `nvidia-smi` is available and at least one GPU is visible.
- FFmpeg exposes `h264_nvenc`.
- FFmpeg exposes `scale_cuda`.
- FFmpeg exposes the `cuda` hardware acceleration method.

The container exits instead of silently using a CPU-only FFmpeg build when this contract is not met. The healthcheck repeats the capability check. Set `GPU_WORKER_PREFLIGHT=false` only for image inspection without an NVIDIA runtime; never disable it for production queue consumption.

## Production Filter Path

The seeded `h264_nvenc` profile supplies CUDA hardware decode options. The application uses this filter graph:

```text
scale_cuda=w=<width>:h=<height>:force_original_aspect_ratio=decrease:force_divisible_by=2:interp_algo=lanczos
```

The previous `hwupload,scale_npp` graph was invalid for the distribution runtime observed on the target host: `scale_npp` was absent, while `scale_cuda` was available. Because hardware decode already returns CUDA frames, an additional `hwupload` is neither needed nor valid in this path.

## Build and Smoke Test

Build the production worker:

```bash
docker compose --env-file .env -f compose.yaml build worker-video-gpu
```

Run the GPU smoke on the target host:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

The smoke uses the production GPU-worker image and validates:

- CPU `libx264` source generation with AAC audio.
- CUDA hardware decode.
- CUDA scaling with the production filter options.
- H.264 NVENC MP4 output.
- H.264 NVENC HLS output and segment generation.
- Output codec, audio codec, and dimensions with `ffprobe`.

GitHub Actions builds the same image and verifies its static capabilities without requiring a GPU. A real encode remains a target-host release gate.

## Enable the VIMP User

Keep the VIMP webservice user on `libx264` until the target-host GPU smoke passes. Then select the seeded `h264_nvenc` profile for that user in `/admin/users`.

During a live job, confirm the effective command and GPU activity:

```bash
docker compose --env-file .env -f compose.yaml exec worker-video-gpu \
  sh -lc 'ps -eo pid,etime,%cpu,%mem,args | grep "[f]fmpeg"'

nvidia-smi \
  --query-gpu=timestamp,name,utilization.gpu,utilization.encoder,utilization.decoder,memory.used,memory.free,power.draw \
  --format=csv \
  --loop=1
```

The FFmpeg command must contain `-vcodec h264_nvenc`, and `utilization.encoder` must rise above zero while frames are encoded.

## CPU Fallback

The `h264_nvenc` profile has `libx264` as its fallback profile. A thrown first-attempt GPU exception is retried with the configured queue backoff; the second attempt selects the CPU profile. Terminal failure still follows the shared PR17 queue lifecycle and sends one VIMP error callback.

## Monitoring

`/internal/metrics` reports the stable GPU worker heartbeat under `workers.gpu_worker`. A healthy worker reports `status=ok` and runtime metadata containing FFmpeg version, encoder, and filter. This metadata is published only after the preflight has passed and the queue loop has started.

See `docs/GPU_BENCHMARK.md` for performance acceptance.
