# Rollback Plan

Stand: 2026-06-24

Dieser Plan beschreibt den Rueckweg fuer den Produktions-Cutover. Er ist konservativ: lieber frueh zur letzten bekannten Version zurueck als waehrend eines instabilen Transcoding-Fensters weiter zu debuggen.

## Rollback-Ausloeser

Rollback wird gestartet, wenn einer dieser Punkte eintritt:

- VIMP kann keine neuen Transcodes starten.
- Mehrere Jobs bleiben ohne Fortschritt in `download` oder `video` haengen.
- Ready-Health bleibt laenger als 5 Minuten rot, ohne bekannte Staging-Abweichung.
- GPU-Worker startet Jobs, aber FFmpeg/NVENC faellt reproduzierbar aus.
- Callbacks an VIMP fehlen oder enthalten falsche Artefakt-URLs.
- Fehlerquote im Staging-/Produktionsfenster uebersteigt die freigegebene Schwelle.
- DB-Migration kann nicht vollstaendig oder nicht verifiziert abgeschlossen werden.

## Vorbereitete Rollback-Artefakte

Vor Cutover muessen vorhanden sein:

- Letzter produktiver Git-Commit oder Image-Tag.
- Datenbank-Backup unmittelbar vor Migration/Cutover.
- Kopie der produktiven `.env` ausserhalb des Repositories.
- Aktuelle Reverse-Proxy-Konfiguration.
- Liste aktiver Worker-Container und Queue-Namen.
- Pfade oder Volume-Namen fuer `uploaded-media`, `converted-media`, `app-storage` und Redis.

## Worker Drain

Vor einem kontrollierten Rollback keine neuen Videojobs starten:

```bash
docker compose --env-file .env -f compose.yaml stop scheduler
docker compose --env-file .env -f compose.yaml stop worker-download worker-video-gpu
```

Wenn Jobs aktiv sind, Entscheidung dokumentieren:

- Fertig laufen lassen, wenn VIMP-Callbacks stabil sind und Zeitfenster reicht.
- Abbrechen, wenn Outputs fehlerhaft sind oder GPU/Storage instabil ist.

## App-Rollback

Zur letzten freigegebenen Version wechseln:

```bash
git fetch origin master
git checkout <last-known-good-sha>
docker compose --env-file .env -f compose.yaml build app web
docker compose --env-file .env -f compose.yaml up -d app web
```

Danach:

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
```

Erst wenn Web und App gesund sind, Worker wieder starten:

```bash
docker compose --env-file .env -f compose.yaml up -d worker-download worker-video-gpu scheduler
```

## Datenbank-Rollback

Datenbank-Rollback ist nur erlaubt, wenn:

- Vorheriges Backup erfolgreich wiederherstellbar getestet wurde.
- Keine neuen produktiven Jobs akzeptiert werden.
- VIMP ueber das Wartungsfenster informiert ist.

Ablauf:

1. Scheduler und Worker stoppen.
2. Webzugriff am Reverse Proxy sperren oder auf Wartungsseite zeigen.
3. DB aus dem Pre-Cutover-Backup wiederherstellen.
4. App auf letzten bekannten Commit/Image-Tag zuruecksetzen.
5. Migrationsstatus pruefen.
6. Healthchecks ausfuehren.
7. Einen VIMP-Staging- oder internen Smoke-Flow ausfuehren.

## Reverse-Proxy-Rollback

Wenn nur der neue Stack fehlschlaegt, aber die alte App lauffaehig ist:

1. Neue Worker stoppen.
2. Reverse Proxy zur alten Upstream-Adresse zuruecksetzen.
3. Healthcheck der alten Route pruefen.
4. VIMP-Testtranscode gegen die alte Route starten.

## Nach Rollback

Innerhalb von 30 Minuten dokumentieren:

- Zeitpunkt des Rollbacks.
- Ausloeser.
- Betroffene Jobs und Mediakeys.
- DB-Backup/Restore-Status.
- Welche Container/Images aktiv sind.
- Ob VIMP erneut Transcodes senden darf.

Nacharbeit:

- Fehlerursache als Issue oder Folge-PR dokumentieren.
- Keine erneute Produktivfreigabe ohne bestandenen Staging-Run.
