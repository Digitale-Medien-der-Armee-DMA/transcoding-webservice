# Install

Stand: 2026-06-25

Diese Anleitung beschreibt die Installation des modernisierten Stacks auf einem internen Host. Sie ist fuer Staging und Produktion gedacht und setzt voraus, dass die VIMP-API-Kompatibilitaet ueber die Contract-Tests erhalten bleibt.

## Voraussetzungen

- Linux-Host, bevorzugt Ubuntu 24.04 LTS fuer den Zielbetrieb.
- Docker Engine mit Compose Plugin.
- Git.
- Zugriff auf das private Repository.
- Externe MySQL/MariaDB-Datenbank fuer Produktion.
- Redis, entweder als Compose-Service aus `compose.yaml` oder als externer Redis-Dienst.
- Fuer GPU-Transcoding: NVIDIA-Treiber auf dem Host und NVIDIA Container Toolkit.

Nicht im Container installieren:

- NVIDIA Host-Treiber.
- Produktive Datenbank als App-Container.
- Secrets im Repository.

## Repository

```bash
git clone <repo-url> transcoding-webservice
cd transcoding-webservice
git checkout master
```

Fuer Releases immer einen konkreten Commit dokumentieren:

```bash
git rev-parse HEAD
```

## Environment

```bash
cp .env.example .env
```

Pflichtwerte setzen:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=http://transcoding-webservice.internal
HTTP_BIND=127.0.0.1:8080

DB_HOST=db.internal
DB_PORT=3306
DB_DATABASE=transcoding_webservice
DB_USERNAME=transcoding_webservice
DB_PASSWORD=

QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
```

`APP_KEY` einmalig erzeugen, wenn noch keiner existiert:

```bash
docker compose --env-file .env -f compose.yaml run --rm app php artisan key:generate --show
```

Den ausgegebenen Wert in `.env` eintragen.

## Security Defaults

Diese Defaults bleiben fuer Produktion konservativ:

```env
APP_DEBUG=false
SECURITY_LOG_SCRUBBING_ENABLED=true
ADMIN_UPLOADS_ENABLED=false
HEALTH_REDIS_REQUIRED=true
```

Source-URL-Allowlist nur nach VIMP-Abstimmung aktivieren:

```env
SECURITY_SOURCE_URL_ALLOWLIST_ENABLED=false
SECURITY_SOURCE_URL_ALLOWED_HOSTS=
SECURITY_SOURCE_URL_ALLOW_USER_HOST=true
```

## Build

```bash
docker compose --env-file .env -f compose.yaml config >/tmp/transcoding-compose.yaml
docker compose --env-file .env -f compose.yaml build
```

Oder ueber Make:

```bash
make build
```

## Start

```bash
docker compose --env-file .env -f compose.yaml up -d
docker compose --env-file .env -f compose.yaml ps
```

Migrationen ausfuehren:

```bash
docker compose --env-file .env -f compose.yaml exec app php artisan migrate --force
```

## Healthcheck

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Erwartung:

- Live: HTTP 200 und `status=ok`.
- Ready: HTTP 200 und `status=ok`.
- Metrics: JSON mit `queues`, `workers`, `storage`, `transcoding` und `runtime`.

## GPU Smoke

CPU-Smoke:

```bash
docker compose --env-file .env --profile smoke -f compose.yaml run --rm ffmpeg-smoke-cpu
```

GPU-Smoke auf NVIDIA-Host:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

Ein fehlender GPU-Smoke blockiert GPU-Produktionsbetrieb.

## Dev-/Staging-Override

Nur fuer lokale oder isolierte Staging-Umgebungen:

```bash
docker compose --env-file .env -f compose.yaml -f compose.dev.yaml up -d --build
```

Der Override startet eine lokale MariaDB. Fuer Produktion bleibt die Datenbank extern.
