# Deployment

Status: 2026-06-25

This guide describes first deployment of a clean installation. It assumes there is no existing production installation to update or data-migrate.

Before production acceptance, complete:

- `docs/INSTALL.md`
- `docs/STAGING_RUNBOOK.md`
- `docs/RELEASE_CHECKLIST.md`
- `docs/ROLLBACK_PLAN.md`

## Deployment Principles

- Deploy from a known commit on `master`.
- GitHub Actions must be green for the commit.
- Secrets stay in `.env` on the target host and are not committed.
- Production uses an external database.
- The bundled Redis service may be used unless an external Redis endpoint is provided.
- GPU access is limited to `worker-video-gpu`.
- Admin uploads remain disabled.

## Preflight

On the target host:

```bash
git fetch origin master
git checkout master
git pull --ff-only
git rev-parse HEAD
```

Validate configuration:

```bash
docker compose --env-file .env -f compose.yaml config >/tmp/transcoding-compose-production.yaml
docker compose --profile smoke --profile gpu-smoke --env-file .env -f compose.yaml config >/tmp/transcoding-compose-smoke.yaml
```

Record:

- Git commit SHA.
- Image tag or build timestamp.
- Database host and database name, without passwords.
- Redis target.
- Reverse proxy target.
- `APP_URL` and `HTTP_BIND`.
- GPU model and driver version, if GPU production is planned.

## Build

```bash
docker compose --env-file .env -f compose.yaml build
docker compose --env-file .env --profile smoke -f compose.yaml build ffmpeg-smoke-cpu
```

On a GPU target host:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml build ffmpeg-smoke-gpu
```

## Start

For the full GPU-capable production stack:

```bash
docker compose --env-file .env -f compose.yaml up -d
docker compose --env-file .env -f compose.yaml ps
```

For CPU-only bootstrap:

```bash
docker compose --env-file .env -f compose.yaml up -d app web redis scheduler worker-download
docker compose --env-file .env -f compose.yaml ps
```

## Fresh Database Setup

Run migrations against the fresh database:

```bash
docker compose --env-file .env -f compose.yaml exec app php artisan migrate --force
```

Run release-specific seed/bootstrap commands only when documented for the current release. Do not leave default admin credentials in production.

## Reverse Proxy

`web` binds to:

```env
HTTP_BIND=127.0.0.1:8080
```

The external reverse proxy should route to that host port. TLS, LAN exposure, authentication layers, and firewall policy are outside the Compose stack and must be handled by the platform.

## Acceptance Checks

Health:

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Containers:

```bash
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail=200 app
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
```

CPU smoke:

```bash
docker compose --env-file .env --profile smoke -f compose.yaml run --rm ffmpeg-smoke-cpu
```

GPU smoke:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

Production is accepted only when:

- Ready health is green.
- Metrics expose DB, queue, worker, storage, and FFmpeg signals.
- Workers and scheduler are running.
- CPU smoke passes.
- GPU smoke passes if GPU production is planned.
- VIMP staging flow passes.
- No API tokens or authorization values appear in logs.

## Recovery

For a clean install, recovery normally means rebuilding the stack from the known commit and recreating the fresh database or volumes if acceptance fails before production use.

Do not improvise on the target host. Follow `docs/ROLLBACK_PLAN.md`, document the failure, and fix the cause in a follow-up PR.
