# AGENTS.md

Stand: 2026-06-23

Dieses Repository modernisiert den VIMP Transcoding Webservice fuer internen Produktionsbetrieb. Fuer die aktuelle Phase gilt: keine produktiven Codeaenderungen und keine Dependency-Upgrades ohne explizite Freigabe.

## Arbeitsregeln

- VIMP API-Kompatibilitaet hat Vorrang vor internen Refactorings.
- Die bestehenden Endpunkte unter `/api` duerfen nicht ohne Contract-Test und Freigabe geaendert werden.
- Secrets bleiben in `.env` und werden nicht committed.
- Produktions-Compose nutzt eine externe Datenbank. Eine lokale DB gehoert nur in Dev-/Staging-Overrides.
- GPU-Zugriff gehoert nur in Video-Worker-Container.
- Keine Host-NVIDIA-Treiber in Container installieren. Der Host-Treiber wird ueber NVIDIA Container Toolkit eingebunden.
- Kein `privileged: true`, ausser eine spaeter dokumentierte Notwendigkeit wird freigegeben.
- Cleanup von Transcoding-Dateien muss konfigurierbar und konservativ sein.
- Monitoring und Healthchecks sind Teil des Produktionsumfangs, nicht optionaler Nachtrag.

## Freigabepunkte

Vor Umsetzung sind mindestens diese Entscheidungen explizit freizugeben:

1. Zielversionen fuer PHP, Laravel, Node und FFmpeg.
2. Queue-Backend und Migrationspfad von DB-Queue zu Redis.
3. Produktions-Compose-Struktur und Volume-Layout.
4. VIMP Contract-Test-Scope mit Fake-VIMP-Server.
5. Security-Massnahmen, die das URL-/Token-Verhalten beruehren.

## Audit-Artefakte

- `MODERNIZATION_AUDIT.md`
- `MIGRATION_PLAN.md`
- `PR_PLAN.md`
- `RISK_REGISTER.md`
