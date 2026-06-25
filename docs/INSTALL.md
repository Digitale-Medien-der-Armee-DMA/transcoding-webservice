# Install

Status: 2026-06-25

This guide describes a clean installation of the Dockerized Transcoding Webservice. It does not describe an upgrade of an existing installation.

## What Works Out Of The Box

The repository contains a production-oriented Compose stack that can be validated, built, and started once the required environment values are supplied.

It is not zero configuration. A clean install still requires:

- Docker Engine and the Docker Compose plugin.
- A fresh external MySQL/MariaDB database for production.
- A `.env` file derived from `.env.example`.
- A generated `APP_KEY`.
- Redis, either the bundled `redis` service or an external Redis endpoint.
- NVIDIA host driver and NVIDIA Container Toolkit if the GPU worker is started.
- A reverse proxy if the service should be exposed beyond the local `HTTP_BIND`.

The production Compose file does not start a database container. `compose.dev.yaml` is only for local or isolated staging use.

## Host Requirements

Recommended production target:

- Ubuntu 24.04 LTS.
- Docker Engine with Compose plugin.
- Git.
- Access to this repository.
- External MySQL/MariaDB.
- For GPU transcoding: NVIDIA driver on the host and NVIDIA Container Toolkit.

Do not install inside the containers:

- NVIDIA host drivers.
- Production database packages.
- Secrets or local-only credentials.

## Repository

```bash
git clone <repo-url> transcoding-webservice
cd transcoding-webservice
git checkout master
git rev-parse HEAD
```

Record the commit SHA for the installation notes.

## Environment

Create the environment file:

```bash
cp .env.example .env
```

Set at least:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=http://transcoding-webservice.internal
HTTP_BIND=127.0.0.1:8080

DB_CONNECTION=mysql
DB_HOST=db.internal
DB_PORT=3306
DB_DATABASE=transcoding_webservice
DB_USERNAME=transcoding_webservice
DB_PASSWORD=<set-me>

QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

ADMIN_UPLOADS_ENABLED=false
SECURITY_LOG_SCRUBBING_ENABLED=true
HEALTH_REDIS_REQUIRED=true
```

Generate the application key:

```bash
docker compose --env-file .env -f compose.yaml run --rm app php artisan key:generate --show
```

Copy the returned value into `APP_KEY` in `.env`.

## Security Defaults

Keep these defaults for production unless a later approved change says otherwise:

```env
APP_DEBUG=false
ADMIN_UPLOADS_ENABLED=false
SECURITY_LOG_SCRUBBING_ENABLED=true
HEALTH_REDIS_REQUIRED=true
```

Source URL allowlisting is optional and must be coordinated with VIMP hostnames or IP ranges:

```env
SECURITY_SOURCE_URL_ALLOWLIST_ENABLED=false
SECURITY_SOURCE_URL_ALLOWED_HOSTS=
SECURITY_SOURCE_URL_ALLOW_USER_HOST=true
```

## Validate Compose

```bash
docker compose --env-file .env -f compose.yaml config >/tmp/transcoding-compose.yaml
docker compose --profile smoke --profile gpu-smoke --env-file .env -f compose.yaml config >/tmp/transcoding-compose-smoke.yaml
```

For isolated development or staging with a local database:

```bash
docker compose --env-file .env -f compose.yaml -f compose.dev.yaml config >/tmp/transcoding-compose-dev.yaml
```

## Build

```bash
docker compose --env-file .env -f compose.yaml build
```

Or:

```bash
make build
```

## Start

On the intended GPU-capable production host:

```bash
docker compose --env-file .env -f compose.yaml up -d
docker compose --env-file .env -f compose.yaml ps
```

For a CPU-only bootstrap or documentation validation, start only non-GPU services:

```bash
docker compose --env-file .env -f compose.yaml up -d app web redis scheduler worker-download
docker compose --env-file .env -f compose.yaml ps
```

## Fresh Database Bootstrap

Run migrations against the fresh database:

```bash
docker compose --env-file .env -f compose.yaml exec app php artisan migrate --force
```

If the release requires seeders or bootstrap commands, run them only after reviewing the release notes and confirming that production credentials are ready. Default admin credentials must be rotated or replaced before production use.

## Health Check

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Expected:

- Live: HTTP 200 with `status=ok`.
- Ready: HTTP 200 with `status=ok`.
- Metrics: JSON with queue, worker, storage, runtime, and transcoding sections.

## FFmpeg Smoke

CPU smoke:

```bash
docker compose --env-file .env --profile smoke -f compose.yaml run --rm ffmpeg-smoke-cpu
```

GPU smoke on the NVIDIA target host:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

GPU production use is blocked until the GPU smoke test passes on the target host.

## Next Steps

After installation:

1. Fill out `docs/RELEASE_CHECKLIST.md`.
2. Run `docs/STAGING_RUNBOOK.md` against VIMP staging.
3. Configure monitoring from `docs/ZABBIX.md`.
4. Keep `docs/ROLLBACK_PLAN.md` available as the clean-install recovery plan.
