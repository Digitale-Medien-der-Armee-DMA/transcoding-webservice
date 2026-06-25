# Transcoding Webservice

Dockerized Laravel webservice for VIMP-compatible video transcoding with separated download and video queues, FFmpeg smoke tests, health/readiness endpoints, and an internal operations baseline.

This repository is currently maintained for a **clean install**. There is no existing installation that must be upgraded or data-migrated. The documentation therefore focuses on first installation, bootstrap, staging acceptance, production operation, and recovery.

## Current Install Status

The production Compose stack can be built and started from this repository, but it is not a zero-configuration appliance.

You must provide:

- Docker Engine with the Compose plugin.
- A fresh external MySQL/MariaDB database for production.
- A `.env` file derived from `.env.example`.
- A generated `APP_KEY`.
- Production secrets outside the repository.
- NVIDIA host driver and NVIDIA Container Toolkit if `worker-video-gpu` is used.
- A reverse proxy if the service should be exposed beyond `HTTP_BIND`.

For a CPU-only bootstrap or documentation review, do not start the GPU worker. For production GPU transcoding, the target host must pass the GPU smoke test.

## Services

`compose.yaml` defines the production-oriented stack:

- `web`: nginx frontend.
- `app`: PHP-FPM/Laravel runtime.
- `worker-download`: queue worker for download jobs.
- `worker-video-gpu`: queue worker for video jobs with NVIDIA GPU reservation.
- `scheduler`: Laravel scheduler loop.
- `redis`: Redis queue/cache service.
- `ffmpeg-smoke-cpu`: optional CPU FFmpeg smoke test profile.
- `ffmpeg-smoke-gpu`: optional GPU FFmpeg smoke test profile.

The production Compose file intentionally does not run a database container. A local MariaDB service exists only in `compose.dev.yaml` for isolated development or staging.

## Quick Start: Clean Install

Clone the repository and create the environment file:

```bash
git clone <repo-url> transcoding-webservice
cd transcoding-webservice
cp .env.example .env
```

Edit `.env` at minimum:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://transcoding-webservice.internal
HTTP_BIND=127.0.0.1:8080

DB_HOST=db.internal
DB_PORT=3306
DB_DATABASE=transcoding_webservice
DB_USERNAME=transcoding_webservice
DB_PASSWORD=<set-me>

QUEUE_CONNECTION=redis
REDIS_HOST=redis
ADMIN_UPLOADS_ENABLED=false
SECURITY_LOG_SCRUBBING_ENABLED=true
```

Generate an application key and put the returned value into `.env`:

```bash
docker compose --env-file .env -f compose.yaml run --rm app php artisan key:generate --show
```

Validate and build:

```bash
docker compose --env-file .env -f compose.yaml config
docker compose --env-file .env -f compose.yaml build
```

Start the full production stack on a GPU-capable host:

```bash
docker compose --env-file .env -f compose.yaml up -d
```

For a CPU-only bootstrap, skip the GPU worker:

```bash
docker compose --env-file .env -f compose.yaml up -d app web redis scheduler worker-download
```

Run database migrations for the fresh database:

```bash
docker compose --env-file .env -f compose.yaml exec app php artisan migrate --force
```

If seeders/bootstrap commands are required by the current release, run them only after checking the release notes and with production credentials ready. Default admin credentials must not remain active in production.

## Health Checks

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Expected:

- Live health returns HTTP 200 and `status=ok`.
- Ready health returns HTTP 200 and `status=ok`.
- Metrics returns JSON for queue, worker, storage, runtime, and transcoding signals.

## FFmpeg Smoke Tests

CPU smoke:

```bash
docker compose --env-file .env --profile smoke -f compose.yaml run --rm ffmpeg-smoke-cpu
```

GPU smoke on an NVIDIA host:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

GPU production use is not accepted until the GPU smoke test passes on the target host.

## VIMP Compatibility

The existing `/api` contract is protected by feature tests. The key endpoints are:

| Endpoint | Purpose |
| --- | --- |
| `/api/transcode` | VIMP submits a transcode job. |
| `/api/download/{filename}` | VIMP downloads generated media. |
| `/api/download/{filename}/finished` | VIMP marks a download as finished. |
| `/api/status/{mediakey}` | VIMP checks job status. |
| `/api/delete/{mediakey}` | VIMP requests cleanup for a mediakey. |

Do not change API behavior without contract tests and explicit approval.

## Documentation

- [Install](docs/INSTALL.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Operations](docs/OPERATIONS.md)
- [Staging runbook](docs/STAGING_RUNBOOK.md)
- [Release checklist](docs/RELEASE_CHECKLIST.md)
- [Recovery plan](docs/ROLLBACK_PLAN.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)
- [Upgrade notes](docs/UPGRADE_NOTES.md)

## Development Validation

Useful local checks:

```bash
composer validate --no-check-publish
composer install --dry-run --no-scripts --no-interaction
docker compose --env-file .env.example -f compose.yaml config
docker compose --profile smoke --profile gpu-smoke --env-file .env.example -f compose.yaml config
vendor/bin/phpunit tests/Feature/VimpContractTest.php tests/Feature/HealthMetricsTest.php tests/Feature/WorkerGuardrailsTest.php tests/Feature/StatusSchemaTest.php tests/Feature/SecurityHardeningTest.php
```
