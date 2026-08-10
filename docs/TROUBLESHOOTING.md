# Troubleshooting

Stand: 2026-06-25

Diese Seite listet typische Fehlerbilder und erste Diagnosebefehle. Bei produktionskritischen Fehlern zuerst `docs/ROLLBACK_PLAN.md` beachten.

## Ready-Health ist rot

Diagnose:

```bash
curl -i "$APP_URL/internal/health/ready"
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail=200 app
```

Moegliche Ursachen:

- Datenbank nicht erreichbar.
- Redis nicht erreichbar und `HEALTH_REDIS_REQUIRED=true`.
- Storage-Volumes nicht beschreibbar.
- `ffmpeg` oder `ffprobe` fehlt im App-Image.

Naechste Schritte:

- DB-/Redis-Host und Ports aus `.env` pruefen.
- Volume-Rechte pruefen.
- `docker compose ... exec app php artisan --version` ausfuehren.

## VIMP kann keinen Job starten

Diagnose:

```bash
docker compose --env-file .env -f compose.yaml logs --tail=200 web
docker compose --env-file .env -f compose.yaml logs --tail=200 app
curl -fsS "$APP_URL/internal/health/live"
```

Pruefen:

- Reverse Proxy zeigt auf `HTTP_BIND`.
- VIMP nutzt den korrekten API-Token.
- User im Webservice hat korrekte VIMP-URL.
- `/api` Response-Format nicht veraendert.

## Download-Queue waechst

Diagnose:

```bash
curl -fsS "$APP_URL/internal/metrics"
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
```

Moegliche Ursachen:

- VIMP-Quelle nicht erreichbar.
- Source-URL-Allowlist blockiert Quelle.
- Download-Limit erreicht.
- Storage `uploaded-media` voll oder nicht beschreibbar.
- Redis/DB langsam.

Pruefen:

- `SECURITY_SOURCE_URL_ALLOWLIST_ENABLED`
- `SECURITY_DOWNLOAD_TIMEOUT_SECONDS`
- `SECURITY_DOWNLOAD_MAX_BYTES`
- `storage.uploaded.free_bytes`

## Video-Queue waechst

Diagnose:

```bash
curl -fsS "$APP_URL/internal/metrics"
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
nvidia-smi
```

Moegliche Ursachen:

- GPU nicht im Container sichtbar.
- NVENC/FFmpeg fehlerhaft.
- VRAM knapp.
- FFmpeg-Profil fehlerhaft.
- Storage `converted-media` voll.

Naechste Schritte:

```bash
docker compose --env-file .env --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

Wenn GPU-Smoke fehlschlaegt, zuerst Host-Treiber und NVIDIA Container Toolkit pruefen.

## GPU worker exits during startup

The dedicated worker intentionally exits when its preflight fails. Inspect the exact reason:

```bash
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
docker compose --env-file .env -f compose.yaml run --rm --no-deps \
  --entrypoint gpu-worker-preflight worker-video-gpu
```

Expected success reports `encoder=h264_nvenc` and `filter=scale_cuda`. A missing `nvidia-smi` or invisible device points to NVIDIA Container Toolkit or Compose device reservation. A missing encoder/filter means the wrong or stale worker image is running; rebuild `worker-video-gpu` from the current commit.

## GPU worker encodes on CPU

Inspect the live process:

```bash
docker compose --env-file .env -f compose.yaml exec worker-video-gpu \
  sh -lc 'ps -eo pid,etime,%cpu,%mem,args | grep "[f]fmpeg"'
```

- `-vcodec libx264` means the VIMP webservice user is assigned the CPU profile or the second-attempt fallback is active.
- `-vcodec h264_nvenc` confirms the GPU encoder selection.
- Encoder utilization remaining at zero with `h264_nvenc` requires the production GPU smoke and worker logs to be checked before further jobs are accepted.

The previous CPU-only production image exposed FFmpeg 4.3 without CUDA/NVENC. `video_backoff=null` or a `DownloadJob::create` stack trace also identifies a stale pre-PR17 image rather than the current runtime.

## Worker sind stale

Diagnose:

```bash
curl -fsS "$APP_URL/internal/metrics"
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
```

Moegliche Ursachen:

- Worker-Container gestoppt.
- DB-Verbindung verloren.
- Lange Jobs ohne Heartbeat-Update.
- Worker nach Deployment nicht wieder gestartet.

## Finaler VIMP-Callback fehlt

Diagnose:

```bash
docker compose --env-file .env -f compose.yaml logs --tail=200 scheduler
docker compose --env-file .env -f compose.yaml exec app php artisan schedule:run --verbose --no-interaction
```

Moegliche Ursachen:

- Scheduler laeuft nicht.
- `transcode:cleanup` kann VIMP nicht erreichen.
- Finished-Endpunkte wurden nicht fuer alle Artefakte aufgerufen.
- Downloadstatus bleibt `PROCESSING`.

## Admin-Uploads werden abgelehnt

Das ist Standardverhalten:

```env
ADMIN_UPLOADS_ENABLED=false
```

Hintergrund: `encore/laravel-admin <=1.8.19` hat eine nicht gepatchte Upload-Advisory. Aktivierung nur mit dokumentierter Risikoakzeptanz oder nach Ersatz/Fork des Admin-Pakets.

## Logs enthalten Tokens

Das ist ein Security-Incident.

Sofort:

- Betroffene Logs sichern und Zugriff begrenzen.
- Token in VIMP und Webservice rotieren.
- `SECURITY_LOG_SCRUBBING_ENABLED=true` pruefen.
- Ursache als separaten Security-Fix behandeln.

## Composer Audit zeigt Advisories

Bekannt nach PR10:

- `encore/laravel-admin` bleibt formal advisory-betroffen, Uploads sind appseitig blockiert.
- Laravel 8 bleibt EOL und braucht spaeteren Laravel-9+-Hop.
- `swiftmailer/swiftmailer` ist abandoned und wird spaeter durch Symfony Mailer abgeloest.

Nicht per ungeplanten Full-Update beheben. Dependency-Hops bleiben eigene PRs.
