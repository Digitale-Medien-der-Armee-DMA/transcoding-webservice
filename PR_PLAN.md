# PR Plan

Stand: 2026-06-23. Alle PRs sind Vorschlaege und brauchen Freigabe vor Implementierung.

Hinweis zum Abarbeitungsstand: Nach PR9 wurde ein Admin-Security-Hardening als PR10 eingeschoben. Die urspruenglich geplante Staging-/Cutover-Vorbereitung wurde als PR11 umgesetzt. Die urspruenglich geplante Operations-/Zabbix-Dokumentation wird deshalb als PR12 umgesetzt.

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

## PR 6: Status Schema Migration

Ziel:

- Boolean-Statusspalten mit vier Statuswerten korrigieren.

Inhalt:

- Migration auf Integer-Status oder neue Statusspalte.
- Datenmigration fuer bestehende Werte.
- Backward-kompatible Model-Konstanten.
- Tests fuer Status, Failed-Jobs und Admin-Anzeige.

Risiko:

- Datenmigration. Nur nach Staging-Backup und Probelauf.

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

## PR 11: Staging Hardening and Production Cutover Prep

Ziel:

- Downtime unter 30 Minuten realistisch absichern.

Inhalt:

- Staging-Runbook.
- Rollback-Plan.
- Lasttest-Dokumentation.
- GPU/VRAM-Beobachtung.
- Release-Checkliste.
