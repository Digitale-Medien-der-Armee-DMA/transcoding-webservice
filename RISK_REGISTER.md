# Risk Register

Stand: 2026-06-23

| ID | Risiko | Schwere | Wahrscheinlichkeit | Befund | Massnahme |
| --- | --- | --- | --- | --- | --- |
| R-001 | VIMP API wird durch Modernisierung gebrochen | Kritisch | Mittel | API liegt in `routes/web.php`; Callback-Payloads sind implizit im Code | Vor Refactoring Contract-Tests und Fake-VIMP-Server |
| R-002 | Laravel 7/PHP 7.x/Node 12 sind EOL | Kritisch | Hoch | Composer/Docker nutzen alte Versionen | Stufenweiser Upgradepfad, keine Big-Bang-Aenderung |
| R-003 | `laravel-admin` blockiert moderne Laravel-Versionen | Hoch | Hoch | `encore/laravel-admin v1.8.11`, Releasezeit 2020 | Kompatibilitaet pruefen, Ersatz-/Fork-Option dokumentieren |
| R-004 | Boolean-Statusspalten speichern vier Statuswerte | Hoch | Hoch | `processed` ist boolean, Code nutzt 0/1/2/3 | Eigene Statusmigration mit Tests und Datencheck |
| R-005 | DB-Queue skaliert und beobachtet schlecht | Hoch | Mittel | Default `database`, lange `retry_after`, keine Queue-Metriken | Redis-Queue, getrennte Worker, Metrics |
| R-006 | GPU VRAM kann nicht hart pro Container auf 12 GB limitiert werden | Hoch | Mittel | Docker/NVIDIA-Standard reserviert Devices, kein belastbares VRAM-Limit | Queue-/Worker-Limits, Guardrails, Zabbix-Alarmierung |
| R-007 | NVDEC-Filterpfad instabil mit realen Inputs | Mittel | Mittel | NVENC-Profil nutzt `-hwaccel cuda` und `scale_npp` | CPU-Decoding/NVENC-Encoding als Fallback, Smoke/E2E getrennt |
| R-008 | FFmpeg-Version nicht reproduzierbar | Hoch | Hoch | Distribution-`ffmpeg` in Debian Buster Worker | Gepinntes FFmpeg-Image oder reproduzierbarer Build |
| R-009 | HLS-ZIP-Verhalten passt evtl. nicht zu erwarteter VIMP-HLS-Auslieferung | Hoch | Mittel | Code archiviert HLS-Verzeichnis als ZIP und callbackt ZIP-URL | VIMP-Staging-Test als Quelle der Wahrheit |
| R-010 | SSRF ueber Source-/Test-URL | Hoch | Mittel | Webservice laedt URLs von VIMP/User-Konfiguration | Allowlist optional, LAN-Scopes, URL-Validation, Tests |
| R-011 | Path Traversal oder falsches File-Finished-Scoping | Hoch | Mittel | Dateiname aus Route; `setDownloadFinished` filtert nicht nach User | Dateiname normalisieren, User/Mediakey scopen, Contract pruefen |
| R-012 | Cleanup loescht Artefakte zu frueh | Hoch | Mittel | Upload/Converted nach 24h `DeleteEverything` | Konfigurierbare Retention, nur completed/expired Artefakte loeschen |
| R-013 | Default Admin-Credentials bleiben produktiv | Hoch | Mittel | Seeder/README nutzen `admin@example.org/admin` | Setup-Check, Doku, First-login Pflicht oder Warnung |
| R-014 | Logs enthalten sensible Daten oder zu viele FFmpeg-Details | Mittel | Mittel | Debug kann FFmpeg-Kommandos loggen | Prod-Logging restriktiv, Token-Scrubbing |
| R-015 | Docker-Compose enthaelt produktive DB und offene Ports | Hoch | Hoch | Aktuelles Compose ist Teststack mit MariaDB und Port 8000 | Neue Production-Compose-Struktur, DB extern |
| R-016 | Dependency-Install beim Containerstart ist unreproduzierbar | Hoch | Hoch | `webservice.sh` fuehrt Composer/npm install beim Start aus | Dependencies im Image bauen, Lockfiles pinnen |
| R-017 | Worker-Timeouts blockieren lange oder haengende Jobs | Mittel | Mittel | `--timeout=84600`, `retry_after=42300` | Job-spezifische Timeouts, Heartbeat, Kill/Retry-Strategie |
| R-018 | Fehlerpfade setzen `failed_at` nicht konsistent | Mittel | Hoch | Code setzt oft nur `processed=FAILED` | Failure-Modell konsolidieren |
| R-019 | VIMP-Quellen sollen behalten werden, Webservice-Cleanup loescht aber eigene Upload-Kopie | Mittel | Mittel | VIMP-Quelle bleibt extern, Webservice-Kopie wird geloescht | Flow dokumentieren, Retention konfigurierbar |
| R-020 | Ausfallzeit > 30 Minuten beim Cutover | Kritisch | Mittel | Mehrere Migrationsachsen: Runtime, DB, Queue, GPU | Staging-Dryrun, Vorbuild, Rollback, kleine PRs |
| R-021 | Composer 2.10 blockiert `encore/laravel-admin` wegen Security-Advisories | Hoch | Hoch | Laravel-8-Hop kann nur als gezielter Partial-Resolve erfolgen; Full-Update blockiert gegen `encore/laravel-admin <=1.8.19` | Separater Admin-/Security-PR: Paket ersetzen, forken oder Advisory-Risiko formal akzeptieren |

## Blockierende Fragen vor Implementierung

1. Ziel-Laravel: 12 als konservatives Ziel oder 13 als laenger supportetes Ziel?
2. Ziel-PHP: 8.4 oder 8.5?
3. Zabbix-Version im Zielbetrieb: 7.4 current, 7.0 LTS oder aelter?
4. Soll HLS fuer VIMP weiter als ZIP geliefert werden, sofern Staging dies bestaetigt?
5. Ist Redis im Produktions-LAN als Container akzeptiert oder existiert ein externer Redis-Dienst?
6. Welche internen VIMP-Hostnames/IP-Ranges duerfen Source-URLs liefern?
