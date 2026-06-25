# PR Plan

Stand: 2026-06-23. Alle PRs sind Vorschlaege und brauchen Freigabe vor Implementierung.

Hinweis zum Abarbeitungsstand: Nach PR9 wurde ein Admin-Security-Hardening als PR10 eingeschoben. Die urspruenglich geplante Staging-/Inbetriebnahme-Vorbereitung wurde als PR11 umgesetzt. Die urspruenglich geplante Operations-/Zabbix-Dokumentation wird deshalb als PR12 umgesetzt.

Naechster Admin-Blocker: ADR 0001 dokumentiert den schrittweisen Ersatz von `encore/laravel-admin`; neue Admin-Funktionen sollen nicht mehr auf `laravel-admin` gebaut werden.

## Aktueller Track: PR14-20 Clean Install, Admin- und Laravel-Hops

Status: zusammengefasst in `docs/ITERATIVE_UPGRADE_TRACK.md`.

Ziel:

- Die naechsten sieben PRs als einen groesseren, iterativen Clean-Install-Track abarbeiten.
- Zuerst `laravel-admin` funktional ersetzen und entfernen.
- Danach PHP-8-Readiness, Runtime-Hop und Laravel-Zwischenhops bis zum final freigegebenen Ziel ausfuehren.
- Installations- und Betriebsanleitungen auf Neuinstallation, Bootstrap und Abnahme ausrichten, nicht auf Aktualisierung einer bestehenden Installation.

Checkpoints:

| PR | Checkpoint | Ergebnis |
| --- | --- | --- |
| PR14 | Internal Admin Operations Shell | Read-only Dashboard, Queue-, Worker- und Health-Ansichten ohne `laravel-admin` |
| PR15 | Internal Profile and Queue Management | Profile, FFmpeg-Optionen und Queue-Betriebsflows ohne `laravel-admin` Resource Controller |
| PR16 | Internal Admin Users, Auth, and Package Removal | User/API-Token-Management, Auth/Rollen und Entfernung von `laravel-admin-ext/*` und `encore/laravel-admin` |
| PR17 | PHP 8 Readiness and Dependency Unblock | `php-ffmpeg`, Proxy/Mailer und Composer-Blocker fuer PHP 8/Laravel 9 geloest |
| PR18 | PHP Runtime Hop and Laravel 9 Hop | Freigegebene PHP-8-Runtime und Laravel-9-Zwischenstufe gruen |
| PR19 | Laravel 10/11 Bridge Hop | Noetige Framework-Bridge-Hops und Legacy-Aufraeumarbeiten gruen |
| PR20 | Final Laravel Target Hop and Frontend/Admin Build Finish | Final freigegebenes Laravel-Ziel, Node-24-Build und Upgrade-Doku abgeschlossen |

Guardrails:

- Jeder Checkpoint bleibt ein eigener mergebarer PR gegen `master`.
- VIMP Contract-Tests bleiben vor und nach jedem Checkpoint Pflicht.
- Keine Admin-Uploads, keine ungeplanten `/api`-Aenderungen, keine Token-/URL-Verhaltensaenderung ohne Freigabe.
- Zielversionen fuer PHP und finales Laravel-Ziel muessen vor PR18/PR20 explizit freigegeben sein.
- Keine Daten- oder Runtime-Migration einer bestehenden Installation einplanen, ausser ein spaeterer Auftrag verlangt das explizit.

## PR 0: Audit-Artefakte

Status: erstellt in dieser Aufgabe.

Umfang:

- `AGENTS.md`
- `MODERNIZATION_AUDIT.md`
- `MIGRATION_PLAN.md`
- `PR_PLAN.md`
- `RISK_REGISTER.md`

Keine produktiven Codeaenderungen, keine Dependency-Upgrades.

## PR 1: VIMP Contract-Test Baseline

Ziel:

- Bestehendes API-Verhalten konservieren.

Inhalt:

- Feature-Tests fuer `/api/transcode`, `/api/download/{filename}`, `/api/download/{filename}/finished`, `/api/status/{mediakey}`, `/api/delete/{mediakey}`.
- Fake-VIMP-Server/Test-Controller fuer Source und Callback.
- Test-Fixture fuer kleinen CPU-transcodierbaren MP4.
- Assertions fuer aktuelle Response-Formate und Callback-Felder.

Risiko:

- Tests koennen vorhandene Bugs sichtbar machen. Verhalten erst dokumentieren, dann separat fixen.

## PR 2: Minimal Health/Readiness/Metrics Skeleton

Ziel:

- Monitoringfaehigkeit schaffen, ohne Transcoding-Verhalten zu aendern.

Inhalt:

- Interne Health-Endpunkte.
- JSON-Metrics fuer DB, Storage, Queue, Worker, FFmpeg/ffprobe.
- Zabbix-Dokumentation oder erstes Template.
- Keine Security- oder API-Breaking-Changes.

## PR 3: Docker Production Blueprint

Ziel:

- Production-Compose-Struktur einfuehren, ohne altes Dev-Docker hart zu entfernen.

Inhalt:

- `compose.yaml` oder `docker-compose.yml` fuer Production mit externer DB.
- `compose.dev.yml` mit optionaler lokaler DB.
- Dockerfiles fuer app/web/worker.
- Redis-Service oder externer Redis-Parameter.
- Healthchecks.
- `.env.example` vollstaendig.
- Non-root- und Permission-Konzept.

Freigabeentscheidung:

- Ziel-Basisimage und PHP-Version.

## PR 4: FFmpeg/NVIDIA Runtime and Smoke Tests

Ziel:

- Reproduzierbarer FFmpeg/NVENC/NVDEC-Pfad.

Inhalt:

- Pinned FFmpeg-Runtime.
- GPU-Smoke-Script.
- CPU-E2E-Test ohne GPU.
- NVENC-Test mit synthetischem Input fuer NVIDIA-Host.
- Dokumentation der FFmpeg-Version und Build-/Image-Quelle.

Freigabeentscheidung:

- FFmpeg-Image-Strategie.

## PR 5: Queue Separation and Worker Guardrails

Ziel:

- Download- und Videoarbeit betriebssicher trennen.

Inhalt:

- Separate Worker-Konfiguration.
- Redis-Queue, falls freigegeben.
- Worker-Heartbeat/Staleness.
- Konservative Parallelitaetsdefaults.
- GPU-Free-VRAM-Guardrail vor Jobstart, falls technisch belastbar.
- Saubere Retry-/Backoff-/Failure-Dokumentation.

## PR 6: Status Schema Baseline

Ziel:

- Boolean-Statusspalten mit vier Statuswerten korrigieren.

Inhalt:

- Schema auf Integer-Status oder neue Statusspalte ausrichten.
- Clean-Install-Seed/Bootstrap fuer neue Statuswerte pruefen.
- Backward-kompatible Model-Konstanten.
- Tests fuer Status, Failed-Jobs und Admin-Anzeige.

Risiko:

- Keine Bestandsdatenmigration erforderlich; Risiko liegt in Schema-/Code-Kompatibilitaet und Tests.

## PR 7: Security Hardening Without Breaking VIMP

Ziel:

- SSRF-/Path-/Token-Risiken reduzieren.

Inhalt:

- Optionale Source-URL-Allowlist.
- Download/Delete-Pfadvalidierung.
- Finished-Markierung enger scopen.
- Log-Scrubbing.
- Timeout- und Size-Limits fuer Downloads/FFmpeg.
- Setup-Dokumentation fuer Admin-Credentials und Token-Rotation.

Freigabeentscheidung:

- Jede Aenderung am Token- oder URL-Verhalten.

## PR 8: Framework Upgrade Stage 1

Ziel:

- Upgradepfad starten, ohne Endziel zu erzwingen.

Inhalt:

- Composer-Constraints gemaess gewaehlt erster Stufe.
- Rector/Pint/PHPUnit nur nach begruendeter Auswahl.
- Laravel Upgrade Guide Aenderungen.
- Contract-Tests muessen gruene Baseline bleiben.

Freigabeentscheidung:

- Direkter Zielkorridor Laravel 12 vs. Laravel 13.

## PR 9: Frontend/Admin Build Modernization

Ziel:

- Admin-UI erhalten und Build supportbar machen.

Inhalt:

- Laravel Mix zu Vite oder gepflegtem minimalen Build.
- Node-Zielversion.
- Asset-Build in CI.
- Keine UI-Neugestaltung ohne separaten Auftrag.

## PR 10: Operations Documentation and Zabbix Finalization

Ziel:

- Deployable und betreibbar.

Inhalt:

- `docs/INSTALL.md`
- `docs/DEPLOYMENT.md`
- `docs/VIMP_STAGING_TEST.md`
- `docs/ZABBIX.md`
- `docs/OPERATIONS.md`
- `docs/TROUBLESHOOTING.md`
- `docs/UPGRADE_NOTES.md`
- finales Zabbix-Template oder genaue Item-/Trigger-Dokumentation.

## PR 11: Staging Hardening and Production Clean-Install Prep

Ziel:

- Erstinstallation, Abnahme und Recovery realistisch absichern.

Inhalt:

- Staging-Runbook.
- Recovery-/Rebuild-Plan.
- Lasttest-Dokumentation.
- GPU/VRAM-Beobachtung.
- Release-Checkliste.
