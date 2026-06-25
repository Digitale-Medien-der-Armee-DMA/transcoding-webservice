# Staging Runbook

Status: 2026-06-25

This runbook verifies a clean installation before production acceptance. It proves that the VIMP contract, queue behavior, FFmpeg/GPU path, health checks, and operational metrics work together.

## Preconditions

- Branch or release commit is based on current `master`.
- GitHub Actions are green for the release commit.
- Staging uses a fresh database or the documented dev override.
- `.env` is created from `.env.example` and contains no committed secrets.
- `APP_KEY`, DB credentials, Redis target, `APP_URL`, `HTTP_BIND`, and VIMP test user/token are set.
- `ADMIN_UPLOADS_ENABLED=false`.
- Target host has Docker Compose.
- GPU staging requires NVIDIA host driver and NVIDIA Container Toolkit.

## Preflight

```bash
git status --short
docker compose --env-file .env -f compose.yaml config >/tmp/transcoding-compose-production.yaml
docker compose --profile smoke --profile gpu-smoke --env-file .env -f compose.yaml config >/tmp/transcoding-compose-smoke.yaml
```

If staging uses a local database:

```bash
docker compose --env-file .env -f compose.yaml -f compose.dev.yaml config >/tmp/transcoding-compose-staging.yaml
```

Record before starting:

- Git commit SHA.
- Image tags or build timestamp.
- DB host and database name, without passwords.
- Redis host.
- VIMP staging URL.
- GPU model, driver version, and `nvidia-smi` output, if applicable.

## Build And Start

```bash
docker compose --env-file .env -f compose.yaml build
docker compose --env-file .env -f compose.yaml up -d
```

For CPU-only staging:

```bash
docker compose --env-file .env -f compose.yaml up -d app web redis scheduler worker-download
```

Run migrations against the fresh database:

```bash
docker compose --env-file .env -f compose.yaml exec app php artisan migrate --force
```

Run release-specific bootstrap/seed commands only if documented for the current release.

## Health

```bash
docker compose --env-file .env -f compose.yaml ps
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Expected:

- Required containers are running.
- Live health is `ok`.
- Ready health is `ok` or has only explicitly accepted staging deviations.
- Metrics contain DB, queue, storage, worker, runtime, and FFmpeg signals.

## FFmpeg Smoke

CPU smoke:

```bash
docker compose --env-file .env --profile smoke -f compose.yaml run --rm ffmpeg-smoke-cpu
```

GPU smoke on an NVIDIA staging host:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

The GPU smoke test passes only if `nvidia-smi`, NVENC encoding, and CUDA decode succeed. A failing GPU smoke blocks GPU production use even when PHP tests are green.

## VIMP Staging Flow

1. Create or select a VIMP staging user with a dedicated API token.
2. Create the matching webservice user/configuration with VIMP staging URL and token.
3. Submit a small MP4 through `/api/transcode`.
4. Observe queue progress in `/internal/metrics`.
5. Verify callback receipt in VIMP staging.
6. Query `/api/status/{mediakey}` and compare with expected status.
7. Call the download-finished endpoint with the generated filename.
8. Test delete flow for the test mediakey.

Acceptance:

- Response formats remain VIMP-compatible.
- Download and video jobs use separate queues.
- Generated artifacts are stored in the expected volume.
- VIMP receives the expected callback fields.
- No API token or authorization header appears in logs.

## Observation During Staging

Record at least:

- Waiting jobs per queue.
- Worker staleness.
- Transcode duration per profile.
- Download duration and file size.
- CPU, RAM, and disk usage.
- GPU utilization, encoder utilization, and free VRAM.
- Laravel log errors and container restarts.

GPU query:

```bash
nvidia-smi --query-gpu=timestamp,name,driver_version,utilization.gpu,utilization.encoder,memory.total,memory.used,memory.free --format=csv
```

## Exit Criteria

Staging passes when:

- GitHub Actions are green.
- Compose config and smoke scripts validate.
- CPU smoke passes.
- GPU smoke passes on the target host class if GPU production is planned.
- VIMP staging flow passes for at least one successful transcode.
- Recovery/rebuild steps are reviewed for the same staging environment.
- Load test findings do not block production acceptance.
- `docs/RELEASE_CHECKLIST.md` is completed.
