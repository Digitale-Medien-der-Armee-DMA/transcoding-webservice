# Docker Production Blueprint

Stand: 2026-06-24

Dieser Blueprint fuehrt die neue Compose-Struktur fuer den internen Produktionsbetrieb ein, ohne PHP/Laravel/Composer-Abhaengigkeiten zu aktualisieren. Er ersetzt die alte Testumgebung unter `docker/` nicht; diese bleibt fuer Rueckgriff und Vergleich vorerst erhalten.

Wichtig: Die neuen Dockerfiles verwenden fuer diese Uebergangsstufe weiterhin PHP 7.4 und Node 12, weil das aktuelle Laravel-7-/Composer-Lockfile noch nicht modernisiert ist. Das ist kein finales Support-Ziel. Die Runtime wird in den spaeteren Upgrade-PRs auf eine unterstuetzte PHP-/Node-Basis gehoben.

## Zielbild

Root-Dateien:

- `compose.yaml`: Production-orientierter Stack ohne produktive Datenbank.
- `compose.dev.yaml`: Dev-/Staging-Override mit lokaler MariaDB.
- `docker/production/Dockerfile.app`: PHP-FPM/Laravel Runtime und Worker-Image.
- `docker/production/Dockerfile.nginx`: nginx Web-Frontend.
- `docker/production/Dockerfile.ffmpeg-smoke`: gepinnte CUDA/Ubuntu-24.04-FFmpeg-Smoke-Runtime.
- `docker/production/app-entrypoint.sh`: Runtime-Verzeichnis-Setup und optionale DB-/Redis-Wartephase.
- `docker/production/nginx.conf`: Laravel nginx vhost.
- `scripts/smoke/`: CPU- und NVIDIA-Smoke-Tests fuer FFmpeg.
- `Makefile`: Standardbefehle fuer Build, Start, Logs und Migrationen.
- `docs/WORKER_GUARDRAILS.md`: Queue-/Worker-Defaults, Heartbeat und GPU-Guardrail.

## Services

- `app`: PHP-FPM/Laravel Runtime.
- `web`: nginx, standardmaessig an `127.0.0.1:8080` gebunden.
- `worker-download`: Queue `download`, kein GPU-Zugriff.
- `worker-video-gpu`: Queue `video`, einziger Service mit NVIDIA Device Reservation.
- `scheduler`: fuehrt `php artisan schedule:run` im Minutenloop aus.
- `redis`: Queue/Cache-Backend fuer den Blueprint.
- `ffmpeg-smoke-cpu`: Profil `smoke`, synthetischer CPU-Encode/Probe-Test.
- `ffmpeg-smoke-gpu`: Profil `gpu-smoke`, synthetischer NVENC- und CUDA-Decode-Test.
- `db`: nur in `compose.dev.yaml`, nicht im Production-Stack.

## Production Start

1. `.env` aus `.env.example` erstellen.
2. Externe DB-Werte setzen: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
3. `APP_KEY` setzen oder einmalig generieren.
4. Stack bauen und starten:

```bash
make build
make up
make migrate
```

Der Webservice ist danach unter `HTTP_BIND` erreichbar, standardmaessig `127.0.0.1:8080`. Fuer nginx Reverse Proxy im LAN sollte der externe Proxy auf diesen lokalen Port zeigen.

## Dev-/Staging-Start

Der Dev-Override startet eine lokale MariaDB:

```bash
cp .env.example .env
make dev-up
make migrate
```

## GPU- und FFmpeg-Hinweis

`worker-video-gpu` reserviert ein NVIDIA-GPU-Device und setzt:

- `NVIDIA_VISIBLE_DEVICES`
- `NVIDIA_DRIVER_CAPABILITIES=compute,utility,video`
- `GPU_DEVICE_COUNT`

Der FFmpeg-Smoke-Pfad ist in `docs/FFMPEG_NVIDIA.md` dokumentiert. Die Smoke-Runtime nutzt ein gepinntes offizielles NVIDIA-CUDA-Ubuntu-24.04-Image und prueft CPU-Encoding, NVENC-Encoding sowie CUDA-Decode auf dem Zielhost. Das Laravel-App-Image bleibt in dieser Uebergangsstufe auf der PHP-7.4-Basis aus PR 3.

## Volumes

- `app-storage`: Laravel Storage.
- `app-cache`: `bootstrap/cache`.
- `uploaded-media`: heruntergeladene VIMP-Quellenkopien.
- `converted-media`: erzeugte Artefakte.
- `redis-data`: Redis Append-only Daten.
- `dev-db-data`: nur Dev-/Staging-MariaDB.

Fuer Produktion koennen `uploaded-media` und `converted-media` spaeter auf Host-Pfade oder NFS-Mounts umgestellt werden. UID/GID und Retention muessen vor Produktivbetrieb validiert werden.

## Healthchecks

- `web` prueft `/internal/health/live`.
- `app` prueft PHP-FPM Port `9000`.
- Worker und Scheduler pruefen `php artisan --version`.
- `redis` prueft `redis-cli ping`.

Readiness und Zabbix-Metriken kommen ueber:

- `/internal/health/ready`
- `/internal/metrics`

Worker-Guardrails sind in `docs/WORKER_GUARDRAILS.md` dokumentiert. Wichtig fuer Produktion: `QUEUE_RETRY_AFTER` muss groesser als die effektive Laufzeit langer Transcodes bleiben, und der GPU-Free-Memory-Guard ist erst nach Zielhost-Validierung zu aktivieren.

Statuswerte fuer `downloads.processed` und `videos.processed` sind in `docs/STATUS_SCHEMA.md` dokumentiert. `processed` ist aus Kompatibilitaetsgruenden weiterhin der Spaltenname, aber fachlich ein Integer-Statusfeld.

## Offene Punkte fuer Folge-PRs

- Spaeter: non-root UID/GID gegen echte Host-/NFS-Mounts validieren.
