# Staging Runbook

Stand: 2026-06-24

Dieses Runbook beschreibt den Staging-Probelauf vor einem Produktions-Cutover. Ziel ist ein reproduzierbarer Nachweis, dass VIMP-Vertrag, Queue-Verhalten, FFmpeg/GPU-Pfad, Healthchecks und Betriebsmetriken zusammen funktionieren, bevor ein Produktionsfenster freigegeben wird.

## Voraussetzungen

- Branch basiert auf dem aktuellen `master`.
- GitHub Actions fuer den geplanten Release-Branch sind gruen.
- Staging nutzt eine Kopie oder leere Instanz der Produktionsstruktur mit externer Datenbank oder dokumentiertem Dev-Override.
- `.env` ist aus `.env.example` erstellt und enthaelt keine Produktiv-Secrets im Repository.
- `APP_KEY`, DB-Zugang, Redis-Ziel, `APP_URL`, `HTTP_BIND` und VIMP-Test-User sind gesetzt.
- `ADMIN_UPLOADS_ENABLED=false` bleibt Standard.
- Zielhost hat Docker Compose, NVIDIA-Treiber und NVIDIA Container Toolkit, falls GPU-Staging Teil des Laufs ist.

## Preflight

```bash
git status --short
docker compose --env-file .env -f compose.yaml config >/tmp/transcoding-compose-production.yaml
docker compose --profile smoke --profile gpu-smoke --env-file .env -f compose.yaml config >/tmp/transcoding-compose-smoke.yaml
```

Wenn Staging eine lokale Datenbank nutzt:

```bash
docker compose --env-file .env -f compose.yaml -f compose.dev.yaml config >/tmp/transcoding-compose-staging.yaml
```

Vor dem Start dokumentieren:

- Git-Commit-SHA.
- Image-Tags oder Build-Zeitpunkt.
- DB-Host und Datenbankname, keine Passwoerter.
- Redis-Host.
- VIMP-Staging-URL.
- GPU-Modell, Treiberversion und `nvidia-smi` Ausgabe.

## Build und Start

```bash
make build
make up
make migrate
```

Nach dem Start pruefen:

```bash
docker compose --env-file .env -f compose.yaml ps
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

Erwartung:

- `web`, `app`, `redis`, `worker-download`, `worker-video-gpu` und `scheduler` laufen.
- Live-Health ist `ok`.
- Ready-Health ist `ok` oder zeigt nur bewusst akzeptierte Staging-Abweichungen.
- Metrics enthalten DB-, Queue-, Storage-, Worker- und FFmpeg-Signale.

## FFmpeg Smoke

CPU-Smoke:

```bash
make ffmpeg-cpu-smoke
```

GPU-Smoke auf NVIDIA-Staging-Host:

```bash
make ffmpeg-gpu-smoke
```

Der GPU-Smoke ist nur bestanden, wenn `nvidia-smi`, NVENC-Encoding und CUDA-Decode erfolgreich sind. Ein fehlender GPU-Smoke blockiert den Produktions-Cutover fuer GPU-Transcoding, auch wenn die PHP-Tests gruen sind.

## VIMP-Staging-Flow

1. VIMP-Staging-User mit eigenem API-Token anlegen.
2. Webservice-User mit VIMP-Staging-URL und Token konfigurieren.
3. Kleines MP4 ueber `/api/transcode` starten.
4. Queue-Fortschritt in `/internal/metrics` beobachten.
5. Callback in VIMP-Staging pruefen.
6. `/api/status/{mediakey}` gegen erwarteten Status pruefen.
7. Download-Finished-Endpoint mit erzeugtem Dateinamen pruefen.
8. Delete-Flow gegen den Test-Mediakey pruefen.

Akzeptanz:

- Response-Formate bleiben VIMP-kompatibel.
- Download- und Videojobs laufen auf getrennten Queues.
- Fertige Artefakte liegen auf dem erwarteten Storage-Volume.
- VIMP erhaelt genau die erwarteten Callback-Felder.
- Kein API-Token oder Authorization-Header erscheint in Logs.

## Beobachtung waehrend Staging

Mindestens diese Werte protokollieren:

- Anzahl wartender Jobs pro Queue.
- Worker-Staleness.
- Transcode-Dauer je Profil.
- Download-Dauer und Dateigroesse.
- CPU-, RAM- und Disk-Auslastung.
- GPU Utilization, Encoder Utilization und VRAM frei.
- Fehlerzaehler aus Laravel-Logs und Container-Restarts.

GPU-Abfrage:

```bash
nvidia-smi --query-gpu=timestamp,name,driver_version,utilization.gpu,utilization.encoder,memory.total,memory.used,memory.free --format=csv
```

## Exit-Kriterien

Staging ist bestanden, wenn:

- GitHub Actions gruen sind.
- Compose-Config und Smoke-Scripts validiert sind.
- CPU-Smoke bestanden ist.
- GPU-Smoke auf Zielklasse bestanden ist, wenn GPU-Produktion geplant ist.
- VIMP-Staging-Flow fuer mindestens einen erfolgreichen Transcode bestanden ist.
- Rollback-Plan gegen dieselbe Staging-Umgebung geprueft oder trocken durchgespielt ist.
- Lasttest-Plan hat keine Cutover-blockierenden Befunde.
- Release-Checkliste ist vollstaendig ausgefuellt.
