# Operations

Stand: 2026-06-25

Diese Seite beschreibt den laufenden Betrieb des Transcoding Webservice nach Deployment. Sie verweist fuer Installation, Deployment und Cutover auf die spezialisierten Runbooks.

## Services

Production-Compose enthaelt:

- `app`: PHP-FPM/Laravel Runtime.
- `web`: nginx Frontend.
- `worker-download`: verarbeitet Queue `download`.
- `worker-video-gpu`: verarbeitet Queue `video` und hat GPU-Zugriff.
- `scheduler`: fuehrt Laravel Scheduler im Minutenloop aus.
- `redis`: Queue/Cache-Service fuer den Blueprint.

Keine produktive Datenbank laeuft im Production-Compose. `compose.dev.yaml` ist nur fuer Dev/Staging mit lokaler MariaDB.

## Regelmaessige Checks

Alle 5 Minuten oder per Monitoring:

```bash
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Taeglich:

```bash
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail=200 app
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
```

Auf NVIDIA-Hosts:

```bash
nvidia-smi
```

## Queue-Betrieb

Normales Verhalten:

- `download.waiting` steigt kurz nach neuen VIMP-Jobs.
- `video.waiting` steigt nach erfolgreichen Downloads.
- `running_jobs` sinkt nach Abschluss wieder auf 0.
- `workers.stale` bleibt 0.

Backlog bewerten:

- Download-Backlog deutet auf VIMP-Quelle, Netzwerk, Storage oder DB/Redis hin.
- Video-Backlog deutet auf GPU/FFmpeg, Profile, Storage oder VRAM-Druck hin.
- Running ohne Fortschritt deutet auf haengende Worker oder zu lange FFmpeg-Prozesse hin.

## Scheduler

Der Scheduler ruft aus `app/Console/Kernel.php` auf:

- `clean:directories` alle 10 Minuten.
- `transcode:cleanup` alle 10 Minuten.

`transcode:cleanup` setzt abgeschlossene Downloads final und sendet den finalen VIMP-Callback. Scheduler-Ausfall kann daher fertige Jobs ohne finalen Callback hinterlassen.

## Logs

Standard:

```bash
docker compose --env-file .env -f compose.yaml logs -f --tail=200
```

Gezielt:

```bash
docker compose --env-file .env -f compose.yaml logs --tail=200 scheduler
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
```

`SECURITY_LOG_SCRUBBING_ENABLED=true` soll aktiv bleiben. Wenn Logs Tokens enthalten, ist das ein Security-Incident.

## Restart

Einzelnen Service neu starten:

```bash
docker compose --env-file .env -f compose.yaml restart worker-video-gpu
```

Gesamten Stack neu starten:

```bash
docker compose --env-file .env -f compose.yaml restart
```

Vor Worker-Restart pruefen, ob Jobs aktiv sind. Ein Neustart kann lange Transcodes abbrechen.

## Storage

Relevante Volumes:

- `uploaded-media`: heruntergeladene VIMP-Quellenkopien.
- `converted-media`: erzeugte Artefakte.
- `app-storage`: Laravel Storage.
- `app-cache`: Bootstrap Cache.
- `redis-data`: Redis Append-only Daten.

Storage-Werte kommen aus `/internal/metrics`:

- `storage.uploaded.free_bytes`
- `storage.converted.free_bytes`
- `storage.*.used_bytes`

Keine manuellen Loeschungen ohne Mediakey- und VIMP-Abgleich. Delete-Flow bevorzugt ueber `/api/delete/{mediakey}` testen.

## GPU-Betrieb

`worker-video-gpu` ist der einzige produktive Service mit GPU-Reservation.

GPU Guard Defaults:

```env
GPU_GUARD_ENABLED=false
GPU_GUARD_MIN_FREE_MB=12288
GPU_GUARD_RETRY_DELAY_SECONDS=60
GPU_GUARD_FAIL_OPEN=false
```

Aktivierung nur nach Zielhost-Test. Docker/NVIDIA erzwingt kein hartes VRAM-Limit pro Container.

## Verweise

- Installation: `docs/INSTALL.md`
- Deployment: `docs/DEPLOYMENT.md`
- Monitoring: `docs/ZABBIX.md`
- VIMP-Staging: `docs/VIMP_STAGING_TEST.md`
- Troubleshooting: `docs/TROUBLESHOOTING.md`
- Rollback: `docs/ROLLBACK_PLAN.md`
- Release Gate: `docs/RELEASE_CHECKLIST.md`
