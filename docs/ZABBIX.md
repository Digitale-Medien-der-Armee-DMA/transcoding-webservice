# Zabbix Monitoring

Stand: 2026-06-25

Der Webservice liefert JSON-Health und JSON-Metrics. Zabbix soll diese Endpunkte pollend ueber den internen Reverse Proxy oder direkt ueber `HTTP_BIND` abfragen.

## Endpunkte

- `/internal/health/live`: Prozess lebt.
- `/internal/health/ready`: Abhaengigkeiten sind bereit.
- `/internal/metrics`: Queue-, Worker-, Storage-, Transcoding- und Runtime-Werte.

Beispiele:

```bash
curl -fsS "$APP_URL/internal/health/live"
curl -fsS "$APP_URL/internal/health/ready"
curl -fsS "$APP_URL/internal/metrics"
```

## HTTP Items

Empfohlene Master-Items:

| Name | URL | Intervall | Timeout | Erwartung |
| --- | --- | --- | --- | --- |
| Transcoding live | `/internal/health/live` | 30s | 5s | HTTP 200 |
| Transcoding ready | `/internal/health/ready` | 30s | 5s | HTTP 200 |
| Transcoding metrics | `/internal/metrics` | 60s | 10s | HTTP 200 |

Die Detailwerte sollten als dependent items aus dem Metrics-JSON extrahiert werden.

## Dependent Items

JSONPath-Vorschlaege:

| Item | JSONPath | Typ |
| --- | --- | --- |
| Queue download waiting | `$.queues.download.waiting` | numeric unsigned |
| Queue download running | `$.queues.download.running` | numeric unsigned |
| Queue download oldest age | `$.queues.download.oldest_waiting_age_seconds` | numeric unsigned |
| Queue video waiting | `$.queues.video.waiting` | numeric unsigned |
| Queue video running | `$.queues.video.running` | numeric unsigned |
| Queue video oldest age | `$.queues.video.oldest_waiting_age_seconds` | numeric unsigned |
| Workers total | `$.workers.total` | numeric unsigned |
| Workers stale | `$.workers.stale` | numeric unsigned |
| Worker stale threshold | `$.workers.stale_after_seconds` | numeric unsigned |
| GPU guard enabled | `$.workers.gpu_guard.enabled` | text or numeric preprocessing |
| GPU guard min free MB | `$.workers.gpu_guard.min_free_mb` | numeric unsigned |
| Uploaded free bytes | `$.storage.uploaded.free_bytes` | numeric unsigned |
| Converted free bytes | `$.storage.converted.free_bytes` | numeric unsigned |
| Running transcodes | `$.transcoding.running_jobs` | numeric unsigned |
| Failed Laravel jobs | `$.transcoding.failed_jobs` | numeric unsigned |
| Failed videos | `$.transcoding.failed_videos` | numeric unsigned |
| Transcoding error rate | `$.transcoding.error_rate` | numeric float |
| Last successful transcode | `$.transcoding.last_successful_transcoding_at` | text |
| PHP version | `$.runtime.php_version` | text |
| FFmpeg available | `$.runtime.ffmpeg.ffmpeg.available` | text or numeric preprocessing |
| FFprobe available | `$.runtime.ffmpeg.ffprobe.available` | text or numeric preprocessing |

## Trigger-Vorschlaege

Konservative Startwerte:

| Trigger | Bedingung | Schwere |
| --- | --- | --- |
| Live endpoint down | HTTP status von live != 200 fuer 2 Checks | High |
| Ready endpoint failed | HTTP status von ready != 200 fuer 2 Checks | High |
| Download queue backlog | `queues.download.waiting > 10` fuer 10 Minuten | Warning |
| Video queue backlog | `queues.video.waiting > 5` fuer 15 Minuten | Warning |
| Old download job | `queues.download.oldest_waiting_age_seconds > 900` | Average |
| Old video job | `queues.video.oldest_waiting_age_seconds > 1800` | Average |
| Stale worker | `workers.stale > 0` fuer 2 Checks | High |
| Failed jobs increasing | `transcoding.failed_jobs` steigt innerhalb von 10 Minuten | Average |
| Error rate high | `transcoding.error_rate > 0.1` fuer 15 Minuten | Average |
| Uploaded storage low | `storage.uploaded.free_bytes < 20G` | Average |
| Converted storage low | `storage.converted.free_bytes < 50G` | Average |
| FFmpeg missing | `runtime.ffmpeg.ffmpeg.available` ist false | High |
| FFprobe missing | `runtime.ffmpeg.ffprobe.available` ist false | High |

Die Storage-Schwellen muessen an das echte Volume-Layout angepasst werden.

## GPU Monitoring

Laravel liefert keine direkten GPU-Auslastungswerte. Auf NVIDIA-Hosts soll Zabbix zusaetzlich per Agent oder externem Check erfassen:

```bash
nvidia-smi --query-gpu=timestamp,name,driver_version,utilization.gpu,utilization.encoder,memory.total,memory.used,memory.free --format=csv,noheader,nounits
```

Empfohlene GPU-Trigger:

- `memory.free < GPU_GUARD_MIN_FREE_MB` fuer 10 Minuten.
- `utilization.encoder = 0` trotz wachsender Video-Queue fuer 10 Minuten.
- `nvidia-smi` nicht ausfuehrbar.
- Treiberversion aendert sich ohne Wartungsfenster.

## Dashboard

Ein minimales Dashboard sollte zeigen:

- Live/Ready Status.
- Queue waiting/running fuer `download` und `video`.
- Worker total/stale.
- Running transcodes.
- Failed jobs und failed videos.
- Storage free fuer uploaded/converted.
- GPU free memory und encoder utilization.
- Last successful transcode timestamp.

## Sicherheit

Die `/internal` Endpunkte enthalten keine Secrets, sollen aber nur intern erreichbar sein. Zugriff ueber Reverse Proxy oder Firewall auf Monitoring-Netze begrenzen.
