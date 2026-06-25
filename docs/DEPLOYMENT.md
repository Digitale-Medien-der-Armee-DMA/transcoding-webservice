# Deployment

Stand: 2026-06-25

Diese Anleitung beschreibt ein konservatives Deployment in den internen Produktionsbetrieb. Der Ablauf setzt voraus, dass `docs/STAGING_RUNBOOK.md`, `docs/ROLLBACK_PLAN.md` und `docs/RELEASE_CHECKLIST.md` vor dem Cutover durchgearbeitet wurden.

## Deployment-Prinzip

- Kleine PRs, jeweils von aktuellem `master`.
- CI muss vor Merge gruen sein.
- Nach Merge: `master` auf dem Zielhost per Fast-Forward aktualisieren.
- Keine Secrets committen.
- Keine produktive Datenbank im Production-Compose.
- GPU-Zugriff nur im `worker-video-gpu` Service.

## Vorbereitung

Vor dem Wartungsfenster:

```bash
git fetch origin master
git log --oneline -5 origin/master
docker compose --env-file .env -f compose.yaml config >/tmp/transcoding-compose-production.yaml
docker compose --env-file .env -f compose.yaml build
docker compose --env-file .env --profile smoke -f compose.yaml build ffmpeg-smoke-cpu
```

Dokumentieren:

- Ziel-Commit.
- Image-Tags.
- Datenbank-Backup-Zeitpunkt.
- Redis-Ziel.
- Reverse-Proxy-Ziel.
- Rollback-Commit oder vorheriger Image-Tag.

## Wartungsfenster

1. VIMP-Encoding pausieren oder neue Transcoding-Jobs blockieren.
2. Aktive Queues beobachten:

```bash
curl -fsS "$APP_URL/internal/metrics"
docker compose --env-file .env -f compose.yaml ps
```

3. Optional Worker drainen:

```bash
docker compose --env-file .env -f compose.yaml stop scheduler
docker compose --env-file .env -f compose.yaml stop worker-download worker-video-gpu
```

4. Code aktualisieren:

```bash
git checkout master
git pull --ff-only
```

5. Stack bauen und starten:

```bash
docker compose --env-file .env -f compose.yaml build
docker compose --env-file .env -f compose.yaml up -d
```

6. Migrationen:

```bash
docker compose --env-file .env -f compose.yaml exec app php artisan migrate --force
```

7. Health und Readiness:

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

8. Worker/Scheduler aktivieren, falls vorher gestoppt:

```bash
docker compose --env-file .env -f compose.yaml up -d worker-download worker-video-gpu scheduler
```

## Reverse Proxy

`web` bindet standardmaessig an:

```env
HTTP_BIND=127.0.0.1:8080
```

Der externe Reverse Proxy soll nur diesen Host-Port erreichen. TLS, LAN-Freigabe und Zugriffsschutz liegen ausserhalb dieses Compose-Stacks.

## Post-Deployment

Direkt nach Deployment:

```bash
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail=200 app
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
```

Pruefen:

- Ready-Health bleibt gruen.
- Queue-Laengen normalisieren sich.
- Worker sind nicht stale.
- VIMP-Testjob erzeugt Callback.
- Keine Tokens oder Authorization-Werte in Logs.
- GPU/VRAM-Werte liegen im erwarteten Bereich.

## Rollback

Bei blockierenden Fehlern nicht im Wartungsfenster improvisieren. `docs/ROLLBACK_PLAN.md` ausfuehren und danach die Ursache in einem Folge-PR isolieren.
