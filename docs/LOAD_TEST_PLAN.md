# Load Test Plan

Stand: 2026-06-24

Dieser Plan definiert einen konservativen Lasttest fuer Staging. Er soll Queue-, Storage-, GPU- und Callback-Verhalten sichtbar machen, ohne produktive VIMP-Daten oder echte Nutzerlast zu simulieren.

## Ziele

- Download-Queue und Video-Queue unter parallelen Jobs beobachten.
- Sicherstellen, dass Worker nicht stale werden.
- GPU/VRAM-Verhalten bei mehreren synthetischen Transcodes messen.
- Callback-Stabilitaet gegen VIMP-Staging pruefen.
- Retention- und Storage-Wachstum waehrend des Tests erfassen.

## Nicht-Ziele

- Kein Benchmark fuer maximale Produktionskapazitaet.
- Kein Test mit Produktiv-Secrets.
- Kein Test gegen Produktiv-VIMP.
- Kein hartes VRAM-Limit erzwingen; Docker/NVIDIA bietet dafuer keine belastbare Garantie.

## Testdaten

Erlaubt:

- Synthetische MP4-Dateien.
- Freigegebene interne Testvideos.
- Kleine, mittlere und grosse Dateien mit dokumentierter Dauer und Aufloesung.

Nicht erlaubt:

- Personenbezogene oder klassifizierte Inhalte.
- Produktive VIMP-Quellen.
- Secrets in Dateinamen, URLs oder Logs.

## Szenarien

### LT-001 Einzeljob-Baseline

- 1 Downloadjob.
- 1 Videojob.
- Erwartung: kompletter VIMP-Callback erfolgreich.

### LT-002 Kleine Parallelitaet

- 3 bis 5 Transcodes parallel.
- Erwartung: Queues wachsen kurz, Worker bleiben sichtbar, keine doppelten Callbacks.

### LT-003 GPU-Saettigung konservativ

- Anzahl Jobs so waehlen, dass `GPU_GUARD_MIN_FREE_MB` nicht dauerhaft unterschritten wird.
- Erwartung: Jobs laufen nacheinander oder werden kontrolliert verzoegert, nicht aggressiv fehlerhaft retried.

### LT-004 Fehlerpfad

- Eine Quelle absichtlich nicht erreichbar machen.
- Erwartung: Download wird `FAILED`, kein Videojob wird erzeugt, Logs enthalten keine Tokens.

### LT-005 Delete- und Cleanup-Vorbereitung

- Nach erfolgreichen Jobs Delete-Flow fuer Test-Mediakeys ausfuehren.
- Erwartung: Nur erwartete Artefakte werden geloescht.

## Beobachtungswerte

Vor, waehrend und nach jedem Szenario erfassen:

```bash
curl -fsS "$APP_URL/internal/metrics"
docker compose --env-file .env -f compose.yaml ps
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
nvidia-smi --query-gpu=timestamp,utilization.gpu,utilization.encoder,memory.used,memory.free --format=csv
```

Zusaetzlich dokumentieren:

- Start-/Endzeit je Mediakey.
- Dateigroesse.
- Zielprofil.
- Queue-Wartezeit.
- Transcode-Dauer.
- Callback-Ergebnis.
- Container-Restarts.

## Akzeptanzkriterien

Der Lasttest ist bestanden, wenn:

- Alle erfolgreichen Testjobs VIMP-kompatible Callbacks erzeugen.
- Fehlerjobs sauber als Fehler sichtbar sind.
- Keine Worker unerwartet stale bleiben.
- Keine Tokens oder Authorization-Werte in Logs auftauchen.
- GPU-Smoke und Lastszenarien keine NVENC-/CUDA-Grundsatzfehler zeigen.
- Storage-Wachstum erklaert und durch Retention/Delete-Flow beherrschbar ist.

Blockierend fuer Cutover:

- Haengende Jobs ohne sichtbaren Fehlerstatus.
- Doppelte oder falsche VIMP-Callbacks.
- Reproduzierbarer GPU-Worker-Crash.
- Ready-Health bleibt rot.
- Unerwartete Loeschung von nicht beteiligten Artefakten.
