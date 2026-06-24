# Release Checklist

Stand: 2026-06-24

Diese Checkliste dient als Go/No-Go-Gate fuer den Produktions-Cutover. Sie ersetzt keine Freigabe durch Betrieb und VIMP-Verantwortliche.

## Code und CI

- [ ] Release-Branch basiert auf aktuellem `master`.
- [ ] GitHub Actions sind gruen.
- [ ] Keine uncommitted Changes auf dem Release-Host.
- [ ] `composer.lock` und `package-lock.json` sind unveraendert gegen den freigegebenen Commit.
- [ ] Keine Secrets wurden committed.
- [ ] PR-Beschreibung nennt bekannte Restrisiken.

## Konfiguration

- [ ] `.env` liegt nur auf dem Zielhost.
- [ ] `APP_KEY` ist gesetzt.
- [ ] `APP_ENV=production`.
- [ ] `APP_DEBUG=false`.
- [ ] Externe Produktionsdatenbank ist konfiguriert.
- [ ] Redis-Ziel ist konfiguriert.
- [ ] `ADMIN_UPLOADS_ENABLED=false`, ausser Risikoakzeptanz ist dokumentiert.
- [ ] `SECURITY_LOG_SCRUBBING_ENABLED=true`.
- [ ] Source-URL-Allowlist-Entscheidung ist dokumentiert.

## Infrastruktur

- [ ] Docker Compose Version ist dokumentiert.
- [ ] Host hat ausreichend Disk fuer `uploaded-media` und `converted-media`.
- [ ] Volume-Layout ist dokumentiert.
- [ ] Reverse Proxy zeigt auf den geplanten `HTTP_BIND`.
- [ ] DB-Backup vor Cutover ist erstellt.
- [ ] Restore-Pfad wurde getestet oder Risiko akzeptiert.
- [ ] Rollback-Commit/Image-Tag ist bekannt.

## GPU und FFmpeg

- [ ] `make ffmpeg-cpu-smoke` bestanden.
- [ ] `make ffmpeg-gpu-smoke` auf Zielhost bestanden, falls GPU-Produktion geplant ist.
- [ ] NVIDIA-Treiber-Version ist dokumentiert.
- [ ] NVIDIA Container Toolkit funktioniert.
- [ ] `GPU_GUARD_ENABLED` Entscheidung ist dokumentiert.
- [ ] `GPU_GUARD_MIN_FREE_MB` ist passend zur Zielkarte gesetzt oder bewusst deaktiviert.

## Anwendung

- [ ] `make build` erfolgreich.
- [ ] `make migrate` erfolgreich oder nicht erforderlich.
- [ ] `/internal/health/live` ist `ok`.
- [ ] `/internal/health/ready` ist `ok`.
- [ ] `/internal/metrics` liefert Werte fuer DB, Queue, Worker, Storage und FFmpeg.
- [ ] Worker und Scheduler laufen.
- [ ] Queue-Namen fuer Download und Video stimmen.

## VIMP-Staging

- [ ] VIMP-Staging-User und API-Token sind gesetzt.
- [ ] Testtranscode erfolgreich.
- [ ] Callback in VIMP-Staging erfolgreich.
- [ ] Status-Endpunkt gibt erwarteten Status.
- [ ] Finished-Endpunkt markiert nur eigene Dateien.
- [ ] Delete-Flow loescht nur erwarteten Test-Mediakey.

## Lasttest

- [ ] LT-001 Einzeljob-Baseline bestanden.
- [ ] LT-002 kleine Parallelitaet bestanden.
- [ ] LT-003 GPU-Saettigung konservativ bestanden oder bewusst nicht anwendbar.
- [ ] LT-004 Fehlerpfad bestanden.
- [ ] LT-005 Delete-/Cleanup-Vorbereitung bestanden.
- [ ] Keine blockierenden Befunde aus `docs/LOAD_TEST_PLAN.md` offen.

## Cutover-Freigabe

- [ ] Wartungsfenster ist bestaetigt.
- [ ] VIMP-Verantwortliche sind erreichbar.
- [ ] Betriebsverantwortliche sind erreichbar.
- [ ] Rollback-Plan ist griffbereit.
- [ ] Monitoring ist aktiv.
- [ ] Go/No-Go wurde dokumentiert.

## Nach Cutover

- [ ] Erste produktive Transcodes beobachtet.
- [ ] Keine unerwarteten Worker-Restarts.
- [ ] Keine Token-Leaks in Logs.
- [ ] Queue-Laengen normalisieren sich.
- [ ] GPU/VRAM-Werte liegen im erwarteten Bereich.
- [ ] Abschlussnotiz mit Commit, Zeitpunkt und Befunden ist erstellt.
