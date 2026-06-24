# Modernization Audit

Stand: 2026-06-23. Scope: Planungs- und Risikoaudit ohne produktive Codeaenderungen und ohne Dependency-Upgrades.

## Kurzfazit

Der Webservice ist ein Laravel-7-Projekt mit alter PHP-/Node-/Docker-Basis, aber einem ueberschaubaren VIMP-Vertrag. Die groessten Risiken fuer den Produktionsbetrieb sind nicht die reine NVENC-Anbindung, sondern API-Kompatibilitaet, Queue-Zuverlaessigkeit, fehlende Health-/Metrics-Endpunkte, EOL-Basisimages/Dependencies, unklare Statusmodellierung und SSRF-/Pfadrisiken beim Download-/File-Flow.

Empfehlung: erst Contract-Tests und Observability-Skeleton stabilisieren, dann Docker/Runtime modernisieren, danach Framework-Upgrade stufenweise durchfuehren.

## Repository- und Versionsstand

- Remotes: `origin` zeigt auf den privaten Fork, `upstream` auf `https://github.com/E-Learning-FHDO/transcoding-webservice.git`.
- Aktueller Branch: `master`.
- Letzte lokale Historie enthaelt bereits NVENC-bezogene Aenderungen, z. B. `e0dea024 fix nvenc aspect ratio`.
- PHP/Laravel: `composer.json` fordert `php ^7.2.5`, `laravel/framework ^7.5`; `composer.lock` enthaelt `laravel/framework v7.30.4`.
- Docker: `docker/Dockerfile.webservice` und `docker/Dockerfile.worker` basieren auf `debian:buster`; Webservice installiert PHP 7.3 und Node 12; Worker installiert PHP 7.3 und Distribution-`ffmpeg`.
- Frontend: `package.json` nutzt Laravel Mix 5, Webpack-Scripts und `vue-template-compiler 2.6.12`; `package-lock.json` ist Lockfile-Version 1.
- Tests: nur Laravel-Default-Beispieltests vorhanden; `phpunit.xml` nutzt SQLite in-memory und `QUEUE_CONNECTION=sync`.

## Externe Support-Fakten

Diese Punkte wurden am 2026-06-23 gegen Primaerquellen geprueft:

- PHP: PHP 8.2, 8.3, 8.4 und 8.5 sind aktuell unterstuetzt; PHP 8.5 hat Security-Support bis 2029-12-31. PHP 7.x ist EOL. Quelle: https://www.php.net/supported-versions.php
- Laravel: Laravel 13 ist fuer Q1 2026 gelistet, PHP 8.3-8.5, Security-Fixes bis Q1 2028. Laravel 12 bekommt Security-Fixes bis 2027-02-24. Laravel 7 ist EOL. Quelle: https://laravel.com/docs/12.x/releases
- Node.js: Node 24 ist Active LTS bis Maintenance ab 2026-10-20 und EOL 2028-04-30; Node 26 ist Current und wird ab 2026-10-28 Active LTS. Node 12 ist EOL seit 2022-04-30. Quelle: https://github.com/nodejs/release#release-schedule
- FFmpeg: Offizieller Download nennt FFmpeg 8.1.2 als latest stable der 8.1-Branch, released 2026-06-17. FFmpeg selbst liefert Source; Binary-Packages kommen von Distributionen oder Drittanbietern. Quelle: https://ffmpeg.org/download.html
- NVIDIA Container Toolkit: GPU-Auswahl erfolgt ueber `--gpus` bzw. `NVIDIA_VISIBLE_DEVICES`; `NVIDIA_DRIVER_CAPABILITIES=video` ist fuer Video Codec SDK relevant. Quelle: https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/docker-specialized.html
- Docker Compose GPU: Compose unterstuetzt GPU-Reservierungen via `deploy.resources.reservations.devices`, `driver: nvidia`, `count` oder `device_ids`, `capabilities: [gpu]`. Quelle: https://docs.docker.com/compose/how-tos/gpu-support/
- Zabbix: HTTP-Agent-Items unterstuetzen GET/POST/PUT/HEAD und JSON Request Bodies; Templates koennen als YAML/XML/JSON exportiert/importiert werden. Quellen: https://www.zabbix.com/documentation/current/en/manual/config/items/itemtypes/http und https://www.zabbix.com/documentation/current/en/manual/xml_export_import/templates

## VIMP API-Vertrag aus Code

Die relevanten API-Routen liegen in `routes/web.php` unter Prefix `/api` und Middleware `auth:api`, nicht in `routes/api.php`:

- `POST /api/transcode` -> `DownloadController@store`
- `GET /api/download/{filename}` -> `VideoController@getFile`
- `POST /api/download/{filename}/finished` -> `VideoController@setDownloadFinished`
- `GET /api/status/{mediakey}` -> `VideoController@getStatus`
- `DELETE /api/delete/{mediakey}` -> `VideoController@deleteAllByMediakey`
- `POST /api/testurl` -> `VideoController@testUrl`

Auth nutzt Laravel Token Guard mit `hash=false`; Tokens liegen als Klartext in `users.api_token`.

### `POST /api/transcode`

Aktueller Request-Contract in `DownloadController@store`:

- `mediakey`: required, unique in `downloads`, alnum, exakt 32 Zeichen.
- `source.url`: required URL, wird mit der beim User gespeicherten `url` konkateniert.
- `source.created_at`: required.
- `target.start`, `target.duration`: integer, fuer Preview.
- `target.hls`: boolean.
- `target.format.*.label`: required.
- `target.format.*.size`: required, Regex `WIDTHxHEIGHT`.
- `target.format.*.vbr`, `target.format.*.abr`: integer.
- `target.format.*.extension`: `mp4` oder `m4v`.
- `target.format.*.default`: boolean.

Erfolg: HTTP 200, JSON `{ "message": "File is queued for download", "status": "success" }`.
Validierungsfehler: HTTP 400, JSON `{ "message": [...], "status": "failed" }`.

Wichtig: `api_token` wird aus dem Request entfernt, bevor `downloads.payload` persistiert wird.

### Download- und Callback-Flow

1. VIMP ruft `POST /api/transcode` mit Token auf.
2. Webservice erstellt `downloads` und queued `DownloadFileJob` auf Queue `download`.
3. `DownloadFileJob` ruft `payload.source.url` per POST mit JSON `{ api_token }` ab.
4. Quelldatei wird unter Disk `uploaded` mit Pfad `mediakey` gespeichert.
5. Fuer Thumbnail, Spritemap, HLS, Preview und MP4 werden `videos` erzeugt und Jobs auf Queue `video` gelegt.
6. Video-Jobs transcodieren nach Disk `converted`.
7. Fuer Medium/Thumbnail/Spritemap wird ein Callback an `{user.url}/transcoderwebservice/callback` gesendet.
8. VIMP laedt Artefakte via `GET /api/download/{filename}`.
9. VIMP markiert Artefakte mit `POST /api/download/{filename}/finished`.
10. Wenn alle Videos verarbeitet und heruntergeladen sind, sendet der Webservice finalen Callback `{ finished: true }`.

HLS wird aktuell als `.m3u8` plus Segmente erzeugt, anschliessend als ZIP archiviert. Der Callback liefert fuer HLS `medium.hls=true`, URL zum ZIP und Checksumme des ZIP.

### Callback-Payloads

MP4/Preview Callback enthaelt:

- `api_token`
- `mediakey`
- `medium.label`
- `medium.url`
- `medium.checksum`
- `medium.default`
- `properties.source_width`
- `properties.source_height`
- `properties.duration`
- `properties.filesize`
- `properties.width`
- `properties.height`
- `properties.orientation`
- `properties.vbitrate`
- `properties.source_is360video`

HLS Callback enthaelt:

- `medium.label`
- `medium.url`
- `medium.hls=true`
- `medium.vbr`
- `medium.abr`
- `medium.size`
- `medium.extension`
- `medium.created_at`
- `medium.default`
- `medium.checksum`

Thumbnail Callback enthaelt `thumbnail.url`; Spritemap Callback enthaelt `spritemap.count` und `spritemap.url`.

Fehlercallback enthaelt `error.message`. Finalcallback enthaelt `finished=true`.

## Transcoding- und FFmpeg-Stand

- FFmpeg wird ueber `php-ffmpeg/php-ffmpeg v0.16` angesteuert.
- Eigene Klasse `App\Format\Video\H264` erlaubt `libx264`, `h264_nvenc`, `h264_vaapi`.
- Profile-Seeds definieren `libx264`, `h264_nvenc` mit Fallback auf `libx264`, und `h264_vaapi`.
- NVENC-Profil setzt initial `-hwaccel cuda`, `-hwaccel_output_format cuda`, `-vsync 0`.
- NVENC-Filter nutzt `hwupload,scale_npp=...`.
- Zusatzparameter fuer NVENC enthalten aktuell nur `-ac 2`; fuer H.264-Kompatibilitaet fehlen explizite konservative Flags wie Pixel-Format/Profil/Level/Moov-Atom, sofern nicht durch FFmpeg default abgedeckt.
- CPU-Fallback ist fachlich angelegt, aber nur ueber Job-Retry/Profil-Fallback. Das Verhalten muss getestet werden.
- FFmpeg-Version ist im Container nicht reproduzierbar dokumentiert; Debian-Buster-Paketstand ist fuer Ubuntu-24/NVIDIA-L40S-Ziel nicht geeignet.

## Queue-, Worker- und Statusmodell

- Default-Queue ist `database`; weitere Verbindungen fuer `database_download`, `database_video`, `beanstalkd_*` existieren.
- Worker-Skript nutzt `queue:work --tries=3 --queue=download,video --timeout=84600 --memory=1024`.
- `retry_after` ist in DB-Queues auf 42300 Sekunden gesetzt.
- `DownloadFileJob` dispatched download nach `download`, Video-Jobs nach `video`.
- `workers`-Tabelle wird ueber `TranscodingController::updateWorkerStatus()` je Host aktualisiert.
- Statuskonstanten definieren `0 unprocessed`, `1 processed`, `2 processing`, `3 failed`, aber Migrationsspalten `downloads.processed` und `videos.processed` sind `boolean`. Das ist fuer produktive Fehlerauswertung und Datenintegritaet kritisch.
- `failed_at` wird in mehreren Fehlerpfaden nicht gesetzt; Fehler werden teils nur in `processed=3` abgebildet.

## Storage- und Cleanup-Stand

- `uploaded` liegt unter `storage/app/public/uploaded`.
- `converted` liegt unter `storage/app/public/converted`.
- README verlangt Shared Storage, z. B. NFS, fuer Webservice und Worker.
- Scheduler fuehrt alle 10 Minuten `clean:directories` und `transcode:cleanup` aus.
- `config/laravel-directory-cleanup.php` loescht Uploads und Converted-Dateien aelter als 24 Stunden mit `DeleteEverything`.
- Das ist grundsaetzlich gewuenscht, aber fuer VIMP-Produktion zu aggressiv, wenn VIMP Downloads, Retries oder manuelle Recovery laenger brauchen.

## Docker-/Deployment-Stand

Vorhandene Docker-Dateien sind Test-/Dev-orientiert:

- `docker/docker-compose.yml` enthaelt `webservice`, `db`, `worker-1`, `worker-2`.
- Production-DB ist eingebaut, obwohl Zielbetrieb externe DB verlangt.
- Webservice oeffnet Port `8000`.
- Source wird per Bind-Mount eingebunden.
- Webservice installiert Dependencies beim Start und generiert `.env`.
- Secrets sind in Skripten als Root-DB-Passwort `root` eingebettet.
- Keine Healthchecks, kein Redis, keine getrennten Worker-Typen, kein Scheduler-Service, keine GPU-Reservationen, kein non-root-Konzept.

## Monitoring-Gap

Aktuell fehlen:

- `/health/live`
- `/health/ready`
- `/metrics` oder Zabbix-kompatible JSON-Endpunkte
- Queue-Laengen nach Queue-Typ
- Alter des aeltesten wartenden Jobs
- laufende Jobs
- fehlgeschlagene Jobs
- Worker-Heartbeat/Staleness als API
- FFmpeg/FFprobe/NVENC/GPU-Checks
- Disk-Free/Used fuer Transcoding-Storage
- letzte erfolgreiche Transcodierung
- Fehlerquote

Zabbix sollte bevorzugt per HTTP Agent gegen interne JSON-Endpunkte arbeiten. Optional kann ein Sidecar oder Worker `nvidia-smi`/NVML-Daten sammeln und als JSON expose'n.

## Security-Befund

- API Token Guard nutzt Klartext-Tokens. Fuer Kompatibilitaet vorerst nicht brechen; Rotation/Hashing nur mit Migration.
- `DownloadFileJob` laedt eine URL, die aus `user.url + source.url` gebaut wird. Das reduziert freie externe URLs, ist aber weiterhin SSRF-relevant, falls `user.url` falsch gesetzt oder `/api/testurl` missbraucht wird.
- `VideoController@getFile($filename)` und `setDownloadFinished($filename)` nehmen Pfadparameter an. Laravel-Routing faengt Slash-Segmente standardmaessig ab, aber HLS-ZIP und Dateinamen-Handling muessen gegen Traversal/Encoding-Faelle getestet werden.
- `setDownloadFinished()` filtert nicht nach `user_id`; es setzt jedes Video mit passendem Dateinamen auf `downloaded_at`.
- `deleteAllByMediaKey()` loescht anhand Mediakey und greift auf `first()->download_id` zu; leere/unerwartete Zustaende koennen Fehler erzeugen.
- Logs enthalten Mediakey, Dateinamen und teils FFmpeg-Kommandos. Tokens werden nicht offensichtlich geloggt, aber Debug-Output muss im Produktivbetrieb vorsichtig sein.
- Default-Admin-Credentials `admin@example.org/admin` sind dokumentiert und muessen beim Setup erzwungen oder prominent abgesichert werden.

## API-Contract-Testbedarf

Mindestens benoetigte Tests vor Refactoring:

- Token-Auth: akzeptiert gueltigen API-Token, lehnt fehlenden/ungueltigen Token ab.
- `POST /api/transcode`: validiert Request, persistiert Payload ohne `api_token`, queued Download-Job.
- Fake-VIMP-Quelle: nimmt POST mit `api_token` an und liefert Testvideo.
- Download-Job erzeugt Video-Jobs fuer 1080p, 720p, 380p, HLS, Thumbnail.
- MP4 Callback enthaelt alle aktuellen Feldnamen.
- HLS Callback enthaelt ZIP-URL, `hls=true`, Checksumme.
- `GET /api/download/{filename}` liefert Datei nur fuer den passenden API-User.
- `POST /api/download/{filename}/finished` markiert Download und triggert finalen Callback erst nach allen Artefakten.
- `GET /api/status/{mediakey}` liefert aktuelle prozentuale Semantik.
- `DELETE /api/delete/{mediakey}` loescht DB- und Storage-Artefakte konservativ.

## Offene Entscheidungen

- Ziel: direkt Laravel 13/PHP 8.5 oder Zwischenziel Laravel 12/PHP 8.4/8.5?
- Redis als Queue direkt im ersten produktionsnahen Stack oder erst nach Contract-Test-Baseline?
- HLS-Auslieferung weiterhin ZIP, weil aktueller Code so arbeitet, oder optional direkte HLS-Auslieferung nur nach VIMP-Staging-Test?
- FFmpeg-Image: selbst gebaut und signiert/pinned, Ubuntu/NVIDIA-CUDA-Basis mit eigenem FFmpeg, oder gepflegtes Drittanbieter-Image?
- GPU-Policy: harte VRAM-Begrenzung ist in Standard-Docker/NVIDIA-Flow nicht verlaesslich. Empfohlen sind Worker-Limits, Queue-Limits, GPU-Guardrails und Monitoring statt Scheinlimit.
