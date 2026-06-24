# Migration Plan

Stand: 2026-06-23. Dieser Plan beschreibt den empfohlenen Weg. Umsetzung erst nach expliziter Freigabe.

## Zielbild

Interner, dockerisierter VIMP Transcoding Webservice fuer Ubuntu 24 LTS mit NVIDIA L40S:

- VIMP-kompatible bestehende API.
- Weboberflaeche bleibt erhalten.
- Externe Produktionsdatenbank, kein DB-Container im Production-Compose.
- Redis/Queue-Worker sauber containerisiert.
- Getrennte Services fuer Web, PHP Runtime, Download-Worker, GPU-Video-Worker, Scheduler und Redis.
- GPU-Zugriff nur im Video-Worker.
- Health-/Readiness-/Metrics-Endpunkte fuer Zabbix.
- CPU-Fallback und manuelle GPU-Smoke-Tests.
- Konservativer Start mit begrenzter Worker-Parallelitaet, nicht 20 GPU-Jobs parallel.

## Phase 0: Baseline und Contracts

Ziel: Aenderungen messbar machen, bevor Runtime und Framework ersetzt werden.

Arbeiten:

- Test-Fixtures fuer kleinen synthetischen MP4-Input und Fake-VIMP-Server anlegen.
- Contract-Tests fuer alle VIMP-Endpunkte und Callback-Payloads schreiben.
- Statusmodell dokumentieren und Ist-Semantik konservieren.
- Smoke-Kommandos fuer lokale CPU-Tests definieren.
- CI-Baseline fuer Composer install, PHPUnit und npm build vorbereiten, ohne GPU-Annahme.

Ergebnis:

- Refactorings koennen gegen VIMP-Vertrag abgesichert werden.
- Breaking Changes werden sichtbar, bevor sie gemerged werden.

## Phase 1: Observability ohne Verhaltensaenderung

Ziel: Produktionsrelevante Sichtbarkeit schaffen.

Arbeiten:

- Live-Endpoint: Prozess/App erreichbar.
- Readiness-Endpoint: DB erreichbar, Redis falls aktiviert erreichbar, Storage beschreibbar, ffmpeg/ffprobe vorhanden.
- Metrics-Endpoint als JSON fuer Zabbix HTTP Agent.
- Queue-Metriken nach Queue-Typ, aeltester wartender Job, laufende Jobs, failed jobs.
- Worker-Heartbeat/Staleness aus `workers`-Tabelle oder neuem Heartbeat-Modell.
- Disk-Metriken fuer Upload-/Converted-/Temp-Storage.
- FFmpeg/NVENC/GPU-Sichtbarkeit, soweit im Container verfuegbar.
- Letzte erfolgreiche Transcodierung und Fehlerquote.

Zabbix-Vorschlag:

- Primaer HTTP-Agent-Checks gegen interne Endpunkte.
- Template als YAML exportierbar fuer Zabbix 7.4 oder Dokumentation fuer 7.0 LTS, falls Ziel-Zabbix aelter ist.
- Kritische Trigger:
  - Webservice nicht erreichbar > 2 Minuten.
  - Readiness failed > 5 Minuten.
  - Kein frischer Worker-Heartbeat > 10 Minuten.
  - Aeltester wartender Job > 15 Minuten Warnung, > 25 Minuten High.
  - GPU nicht sichtbar bei aktiviertem GPU-Worker > 2 Minuten.
  - NVENC nicht verfuegbar > 2 Minuten.
  - Transcoding-Storage free < 20% Warnung, < 10% High.
  - Keine erfolgreiche Transcodierung laenger als erwartetes Betriebsfenster bei Queue-Aktivitaet.

## Phase 2: Docker-/Compose-Zielstruktur

Ziel: reproduzierbare Container ohne Host-Paketdrift.

Production-Services:

- `web`: nginx, interner Port, kein unnoetiges Public Binding.
- `app`: PHP-FPM/Laravel runtime.
- `worker-download`: Queue `download`, kein GPU-Zugriff.
- `worker-video-gpu`: Queue `video`, GPU-Zugriff, konservative Parallelitaet.
- `scheduler`: `php artisan schedule:run` oder cron-supervised loop.
- `redis`: Queue/Cache nur wenn keine externe Redis-Instanz genutzt wird.
- Optional `metrics-sidecar`: GPU/NVML/ffmpeg-smoke Metriken, falls Laravel-Container diese nicht sauber liefern soll.
- Optional `dev-db` nur in `compose.dev.yml`.

Basisimage-Entscheidung:

- Der Zielhost ist Ubuntu 24 LTS; der GPU-Worker soll bevorzugt auf einer Ubuntu-/CUDA-kompatiblen Basis entstehen, damit NVIDIA Runtime, `nvidia-smi`, FFmpeg, NVENC/NVDEC und Smoke-Tests eng am Produktionsziel bleiben.
- App/PHP-FPM/Web muessen nicht zwingend Ubuntu-only sein. Hier zaehlen Wartbarkeit, reproduzierbare PHP-Extensions und Security-Updates staerker als ein einheitliches OS-Label.
- Ein kompletter Ubuntu-only-Stack ist moeglich, wird aber nur gewaehlt, wenn er nicht mehr Pflegeaufwand erzeugt als offizielle, gut gepflegte Runtime-Images.

Volume-Layout:

- Gemeinsamer App-/Storage-Bereich fuer Web/App/Worker.
- Separates temp/transcoding volume auf Server-SSD.
- Optional NFS fuer permanente/gemeinsame Daten, dokumentiert mit UID/GID- und Locking-Hinweisen.

GPU-Policy:

- `worker-video-gpu` mit NVIDIA Compose device reservation.
- `NVIDIA_DRIVER_CAPABILITIES=compute,utility,video`.
- `NVIDIA_VISIBLE_DEVICES` oder `device_ids` konfigurierbar.
- Kein Verlass auf hartes 12-GB-VRAM-Limit pro Container.
- Stattdessen:
  - Start mit 1 GPU-Worker-Prozess.
  - Max laufende GPU-Jobs per Worker-Skalierung/Queue-Konfig.
  - Guardrail vor Jobstart: freie VRAM-Schwelle pruefen.
  - Warn-/Stop-Policy bei hoher VRAM-Nutzung.
  - CPU-Fallback konfigurierbar.

## Phase 3: FFmpeg/NVIDIA Reproduzierbarkeit

Ziel: nachweisbare NVENC/NVDEC-Unterstuetzung mit H.264/VIMP-Kompatibilitaet.

Optionen:

1. Eigenes FFmpeg-Image auf Ubuntu-24/CUDA-kompatibler Basis, FFmpeg aus Source oder gepflegtem Build pinned und signiert geprueft.
2. Ubuntu-24 Distribution-FFmpeg, falls NVENC/NVDEC im Zielpaket ausreichend und reproduzierbar ist.
3. Gepflegtes Third-Party-Image nur nach Security-/Maintenance-Pruefung.

Empfehlung fuer Produktion: reproduzierbarer eigener Build oder klar gepinntes gepflegtes Image, inklusive Build-Argumenten, Version-Ausgabe und Smoke-Tests.

Pflicht-Smoke:

- `nvidia-smi`
- `ffmpeg -hide_banner -hwaccels`
- `ffmpeg -hide_banner -encoders | grep h264_nvenc`
- `ffmpeg -hide_banner -decoders | grep -E "h264|cuvid|nvdec"`
- kurzer synthetischer Test-Transcode nach MP4.
- optional HLS-Test mit Segment- und Playlist-Pruefung.

Profilstrategie:

- CPU/libx264 als Referenzpfad behalten.
- NVENC zunaechst Encoding-only mit CPU-Decoding erlauben, wenn NVDEC instabil ist.
- NVDEC separat aktivierbar und per Smoke/E2E validiert.
- MP4 und HLS getrennt konfigurierbar halten.

## Phase 4: Queue-Modernisierung

Ziel: stabile, beobachtbare Queue statt DB-Queue als Engpass.

Arbeiten:

- Redis-Queue einfuehren oder aktivieren.
- Queue-Namen explizit: `download`, `video`.
- Separate Worker-Services mit eigenen Timeouts, Memory-Limits und Restart-Policies.
- Retry-/Backoff-Strategie definieren.
- Failed-Jobs-Handling verstaendlicher machen.
- Statusspalten von boolean auf integer migrieren oder neue status-Spalten einfuehren.
- Worker-Heartbeat robust machen.

Migrationshinweis:

- Statusmigration ist datenrelevant. Sie braucht Backup, Staging-Probelauf und Rollback-Plan.

## Phase 5: Dependency- und Framework-Upgrade

Ziel: supportbare Basis ohne Big-Bang-Risiko.

Empfohlene Stufen:

1. Composer-Baseline stabilisieren und Security-Audit ausfuehren.
2. Laravel 7 auf letzte kompatible PHP-8-Testbasis bringen, soweit moeglich.
3. Stufenweise Laravel 8 -> 9 -> 10 -> 11 -> 12/13 pruefen, mit Rector/Laravel-Upgrade-Guides und Contract-Tests nach jeder Stufe.
4. Admin-Oberflaeche pruefen: `encore/laravel-admin` ist alt und kann Ziel-Laravel blockieren.
5. Frontend von Laravel Mix 5/Webpack auf Vite oder minimal gepflegten Build migrieren, sofern Admin-Assets nicht dagegen sprechen.
6. Node-Ziel: fuer sofortige LTS-Stabilitaet Node 24; Node 26 erst nach LTS-Start und Build-Kompatibilitaetscheck.
7. Testframework auf moderne PHPUnit/Pest-Option evaluieren, aber nur wenn Laravel-Zielversion feststeht.

Zielversion-Optionen:

- Konservativ: PHP 8.4 oder 8.5, Laravel 12, Node 24 LTS.
- Ambitioniert: PHP 8.5, Laravel 13, Node 24 LTS zunaechst, spaeter Node 26 LTS.

Entscheidungskriterium:

- Laravel 13 bietet laengeres Supportfenster, kann aber mehr Upgrade-Arbeit verursachen.
- Laravel 12 ist als Zwischenziel plausibel, wenn `laravel-admin` oder andere Pakete blockieren.

## Phase 6: Security-Hardening

Ziel: LAN-only und VIMP-kompatibel, aber ohne vermeidbare Angriffsoberflaeche.

Arbeiten:

- Source-URL-Allowlist optional und konfigurierbar.
- Download- und Delete-Pfade gegen Traversal/unerwartete Dateinamen absichern.
- `setDownloadFinished()` auf User/Mediakey-Kontext einschraenken, sofern VIMP-Vertrag dies erlaubt.
- Token-Rotation dokumentieren.
- Token-Hashing nur mit Migration und VIMP-Abstimmung.
- FFmpeg-Prozess-Timeouts und Ressourcenlimits pruefen.
- Logs fuer Tokens/Secrets scrubben.
- Default-Admin-Credentials beim Setup prominent erzwingen.

## Phase 7: Staging mit VIMP 6.2.8

Ziel: reale Kompatibilitaet vor Produktionsschwenk.

Testablauf:

1. Staging-VIMP registriert Webservice-URL und API-Token.
2. Webservice laedt Quelle von VIMP.
3. Transcoding fuer 1080p, 720p, 380p, Thumbnail und HLS.
4. VIMP erhaelt Callbacks und laedt Artefakte herunter.
5. VIMP markiert Downloads finished.
6. Webservice sendet finalen Callback.
7. Delete-Flow pruefen.
8. Monitoring in Zabbix pruefen.
9. Lasttest mit 1, 2, 4, spaeter 6 parallelen Jobs; 20 nur nach Messwerten.

## Phase 8: Produktionsmigration

Ziel: Downtime unter 30 Minuten.

Vorgehen:

- Vorab DB-Backup und Storage-Snapshot.
- Images vorbauen.
- `.env` vorbereitet, Secrets gesetzt, APP_URL intern korrekt.
- Compose pull/build vor Wartungsfenster.
- Migrationsdryrun in Staging.
- Wartungsfenster:
  - VIMP-Encoding pausieren oder Queue drainen.
  - Deploy `git pull`, `docker compose build`, `docker compose up -d`.
  - Health/readiness pruefen.
  - kleiner VIMP-Testjob.
  - Zabbix Trigger gruen.
- Rollback:
  - vorheriges Image/Compose-State bereit halten.
  - DB-Migrationen nur vorwaerts, wenn rollbackfaehig oder per Backup abgesichert.
