# Worker Guardrails

Stand: 2026-06-24

Dieser Stand trennt Download- und Videoarbeit betrieblich weiter, ohne VIMP-Endpunkte oder Payloads zu veraendern.

## Queue-Layout

- `worker-download` verarbeitet nur `WORKER_DOWNLOAD_QUEUE`, standardmaessig `download`.
- `worker-video-gpu` verarbeitet nur `WORKER_VIDEO_QUEUE`, standardmaessig `video`.
- `worker-video-gpu` ist der einzige produktive Worker mit NVIDIA Device Reservation.
- Der Scheduler und die Web-/PHP-FPM-Container haben keinen GPU-Zugriff.

## Redis Retry Safety

`QUEUE_RETRY_AFTER` muss groesser sein als der laengste Worker-Timeout. Die Produktionsvorgabe bleibt deshalb:

```env
QUEUE_RETRY_AFTER=90000
WORKER_DOWNLOAD_TIMEOUT=84600
WORKER_VIDEO_TIMEOUT=84600
```

Laravel reserviert Jobs fuer `retry_after` Sekunden. Ist dieser Wert zu klein, kann ein langer Transcode erneut sichtbar werden, waehrend der erste Worker noch laeuft. Der Wert ist absichtlich konservativ und muss vor einer echten Timeout-Verkuerzung gemeinsam mit den Worker-Timeouts angepasst werden.

## Worker Defaults

Die Worker haben getrennte Defaults:

```env
WORKER_DOWNLOAD_SLEEP=3
WORKER_DOWNLOAD_TRIES=3
WORKER_DOWNLOAD_MEMORY=768

WORKER_VIDEO_SLEEP=5
WORKER_VIDEO_TRIES=2
WORKER_VIDEO_MEMORY=1024
```

Videojobs starten konservativer, weil ein erneuter Versuch GPU-Zeit blockiert und VIMP-Callbacks ausloesen kann. Mehr Parallelitaet soll zunaechst ueber explizite Worker-Skalierung im Betrieb erfolgen, nicht ueber mehrere Queue-Prozesse im selben Container.

## Heartbeat

Queue-Events aktualisieren die Tabelle `workers` vor und nach Jobs sowie bei fehlgeschlagenen Jobs:

- `host`
- `description` mit IP, Queue und Job-Klasse
- `last_seen_at`

Die bestehenden Health-/Metrics-Endpunkte nutzen `HEALTH_WORKER_STALE_AFTER_SECONDS`, um stale Worker zu zaehlen.

## GPU Free Memory Guard

Der GPU-Guard ist standardmaessig aus:

```env
GPU_GUARD_ENABLED=false
GPU_GUARD_MIN_FREE_MB=12288
GPU_GUARD_RETRY_DELAY_SECONDS=60
GPU_GUARD_FAIL_OPEN=false
```

Wenn `GPU_GUARD_ENABLED=true` ist, pruefen Videojobs vor dem FFmpeg-Aufruf:

```bash
nvidia-smi --query-gpu=memory.free --format=csv,noheader,nounits
```

Ist auf keiner sichtbaren GPU mindestens `GPU_GUARD_MIN_FREE_MB` frei, wird der Job nicht als Fehler markiert. Er wird mit `GPU_GUARD_RETRY_DELAY_SECONDS` Sekunden Verzogerung wieder freigegeben. Das verhindert aggressive Retry-Loops, ersetzt aber kein hartes VRAM-Limit. Docker/NVIDIA erzwingt kein belastbares VRAM-Limit pro Container.

`GPU_GUARD_FAIL_OPEN=false` ist fuer Produktion konservativ: Wenn der Guard aktiv ist und `nvidia-smi` nicht funktioniert, wird der Job ebenfalls verzogert statt ungeprueft gestartet.
