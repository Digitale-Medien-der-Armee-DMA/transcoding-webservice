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
- `docker/production/app-entrypoint.sh`: Runtime-Verzeichnis-Setup und optionale DB-/Redis-Wartephase.
- `docker/production/nginx.conf`: Laravel nginx vhost.
- `Makefile`: Standardbefehle fuer Build, Start, Logs und Migrationen.

## Services

- `app`: PHP-FPM/Laravel Runtime.
- `web`: nginx, standardmaessig an `127.0.0.1:8080` gebunden.
- `worker-download`: Queue `download`, kein GPU-Zugriff.
- `worker-video-gpu`: Queue `video`, einziger Service mit NVIDIA Device Reservation.
- `scheduler`: fuehrt `php artisan schedule:run` im Minutenloop aus.
- `redis`: Queue/Cache-Backend fuer den Blueprint.
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

## GPU-Hinweis

`worker-video-gpu` reserviert ein NVIDIA-GPU-Device und setzt:

- `NVIDIA_VISIBLE_DEVICES`
- `NVIDIA_DRIVER_CAPABILITIES=compute,utility,video`
- `GPU_DEVICE_COUNT`

Dieser PR liefert bewusst noch keinen finalen CUDA-/NVENC-FFmpeg-Build. Das App-Image installiert Distribution-`ffmpeg` als lauffaehige Baseline. Der reproduzierbare FFmpeg/NVIDIA-Runtime-Pfad, NVENC/NVDEC-Smoke-Tests und GPU-Guardrails gehoeren zu PR 4.

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

## Offene Punkte fuer Folge-PRs

- PR 4: reproduzierbarer FFmpeg/NVIDIA/CUDA Runtime-Build und Smoke-Tests.
- PR 5: Redis-/Worker-Guardrails, Retry-/Backoff-Strategie und GPU-Queue-Limits.
- PR 6: Statusspalten-Migration von boolean auf integer.
- Spaeter: non-root UID/GID gegen echte Host-/NFS-Mounts validieren.
