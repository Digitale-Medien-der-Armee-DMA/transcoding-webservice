# Operations

Status: 2026-06-25

This page describes day-to-day operation after a clean-install deployment. For first installation and release acceptance, use `docs/INSTALL.md`, `docs/DEPLOYMENT.md`, `docs/STAGING_RUNBOOK.md`, and `docs/RELEASE_CHECKLIST.md`.

## Services

Production Compose contains:

- `app`: PHP-FPM/Laravel runtime.
- `web`: nginx frontend.
- `worker-download`: processes the `download` queue.
- `worker-video-gpu`: processes the `video` queue and has GPU access.
- `scheduler`: runs Laravel scheduler every minute.
- `redis`: Redis queue/cache service.

No production database runs in `compose.yaml`. `compose.dev.yaml` is only for local or isolated staging environments.

## Regular Checks

Every few minutes, preferably through monitoring:

```bash
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Daily or after changes:

```bash
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail=200 app
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
```

On NVIDIA hosts:

```bash
nvidia-smi
```

## Queue Operation

Expected behavior:

- `download.waiting` rises briefly after new VIMP jobs.
- `video.waiting` rises after successful downloads.
- `running_jobs` returns to 0 after completion.
- `workers.stale` stays at 0.

Backlog interpretation:

- Download backlog usually points to VIMP source access, network, storage, DB, or Redis.
- Video backlog usually points to GPU/FFmpeg, profiles, storage, or VRAM pressure.
- Running jobs without progress usually point to stuck workers or long FFmpeg processes.

## Scheduler

The scheduler runs commands from `app/Console/Kernel.php`, including cleanup and finalization tasks. Scheduler failure can leave finished jobs without their final VIMP callback.

Check scheduler logs:

```bash
docker compose --env-file .env -f compose.yaml logs --tail=200 scheduler
```

## Logs

All services:

```bash
docker compose --env-file .env -f compose.yaml logs -f --tail=200
```

Specific services:

```bash
docker compose --env-file .env -f compose.yaml logs --tail=200 scheduler
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
```

Keep:

```env
SECURITY_LOG_SCRUBBING_ENABLED=true
```

If logs contain API tokens or authorization values, treat it as a security incident.

## Restart

Restart one service:

```bash
docker compose --env-file .env -f compose.yaml restart worker-video-gpu
```

Restart the full stack:

```bash
docker compose --env-file .env -f compose.yaml restart
```

Before restarting workers, check whether jobs are active. A worker restart can interrupt long transcodes.

## Storage

Relevant volumes:

- `uploaded-media`: downloaded copies of VIMP sources.
- `converted-media`: generated artifacts.
- `app-storage`: Laravel storage.
- `app-cache`: bootstrap cache.
- `redis-data`: Redis append-only data.

Storage metrics come from `/internal/metrics`:

- `storage.uploaded.free_bytes`
- `storage.converted.free_bytes`
- `storage.*.used_bytes`

Do not delete files manually without mediakey and VIMP coordination. Prefer testing cleanup through `/api/delete/{mediakey}`.

## GPU Operation

`worker-video-gpu` is the only production service with GPU reservation.

GPU guard defaults:

```env
GPU_GUARD_ENABLED=false
GPU_GUARD_MIN_FREE_MB=12288
GPU_GUARD_RETRY_DELAY_SECONDS=60
GPU_GUARD_FAIL_OPEN=false
```

Enable GPU guard only after target-host testing. Docker/NVIDIA does not enforce a hard VRAM limit per container.

## References

- Installation: `docs/INSTALL.md`
- Deployment: `docs/DEPLOYMENT.md`
- Monitoring: `docs/ZABBIX.md`
- VIMP staging: `docs/VIMP_STAGING_TEST.md`
- Troubleshooting: `docs/TROUBLESHOOTING.md`
- Recovery: `docs/ROLLBACK_PLAN.md`
- Release gate: `docs/RELEASE_CHECKLIST.md`
