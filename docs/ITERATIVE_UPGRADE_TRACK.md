# Iterative Clean-Install Track PR14-20

Stand: 2026-06-25

Dieser Track fasst die naechsten einzelnen Modernisierungs-PRs zu einem groesseren, iterativen Schritt zusammen. Das Projekt wird als Clean Install fuer den neuen Produktionsbetrieb behandelt. Ziel ist, den Admin-Paket-Blocker zu entfernen und danach die PHP-/Laravel-Hops kontrolliert durchzufuehren, ohne den VIMP-Vertrag oder die Neuinstallation zu destabilisieren.

## Ausfuehrungsmodell

- PR14-20 gehoeren zu einem gemeinsamen Clean-Install-Track.
- Jeder PR bleibt ein eigener mergebarer Checkpoint gegen `master`.
- Nach jedem Checkpoint muessen lokale Baseline, GitHub Actions und VIMP-Contract-Tests gruen sein.
- Ein fehlgeschlagener Checkpoint wird korrigiert, bevor der naechste Checkpoint begonnen wird.
- Kein Checkpoint darf `/api` ohne Contract-Test und explizite Freigabe aendern.
- Kein Checkpoint darf Admin-Uploads reaktivieren.
- Composer Full-Updates sind erst nach Entfernung von `encore/laravel-admin` erlaubt.
- Zielversionen fuer PHP und finales Laravel-Ziel bleiben ein Freigabepunkt vor dem Runtime-/Framework-Hop.
- Anleitungen beschreiben Bootstrap, Erstinstallation und Betriebsabnahme, nicht Aktualisierung einer bestehenden Installation.

## Track-Zielbild

Nach PR20 soll gelten:

- `laravel-admin-ext/*` und `encore/laravel-admin` sind entfernt.
- Admin-Kernflows laufen ueber interne Laravel-Routen, Controller und Blade-Views.
- Composer Audit ist ohne den `laravel-admin`-Upload-Blocker dokumentiert.
- PHP-8-Runtime-Hop ist vorbereitet oder umgesetzt, je nach freigegebener Zielversion.
- Laravel ist mindestens durch die notwendigen Zwischenhops auf dem Weg zum finalen Ziel.
- VIMP Contract-Tests, Health-/Metrics-Tests, Worker-Guardrail-Tests, Status-Tests und Security-Hardening-Tests bleiben die Baseline.
- Installations-, Staging- und Betriebsabnahme-Dokumentation ist nach jedem Dependency- oder Runtime-Hop aktualisiert.

## PR14: Internal Admin Operations Shell

Ziel:

- Einen internen Admin-Shell-Pfad ohne `laravel-admin` einfuehren.

Umfang:

- Neuer interner Admin-Prefix fuer Betriebsansichten.
- Read-only Dashboard fuer Health, Queue, Worker und Runtime-Status.
- Zugriffsschutz ueber den aktuellen Auth-/Admin-Pfad, ohne Token-/URL-Verhalten zu aendern.
- Keine Delete-, Edit- oder Upload-Aktionen.
- Tests fuer Zugriffsschutz, Statusdarstellung und leere Datenstaende.

Exit:

- Die aktuelle `laravel-admin`-Oberflaeche bleibt parallel nutzbar, bis der neue interne Admin-Pfad vollstaendig ist.
- Operations-Team kann Read-only-Status ohne `laravel-admin` pruefen.

## PR15: Internal Profile and Queue Management

Ziel:

- Produktionsrelevante Admin-Flows aus `Encore\Admin\Grid`, `Form` und `Show` herausloesen.

Umfang:

- Profile list/show/edit inklusive FFmpeg-Optionen.
- Fallback-Profil setzen.
- Queue-Detailansichten fuer Download- und Transcoding-Jobs.
- Bestehende Queue-Delete-Logik nur ueber eng getestete interne Controller wiederverwenden.
- Validierung fuer Profilfelder, FFmpeg-Optionen und Statusanzeigen.
- Tests fuer Persistenz, Fallback-Profil, User-Scoping und Delete-Guardrails.

Exit:

- Profile und Queue-Betrieb brauchen keine `laravel-admin` Resource Controller mehr.
- VIMP API und Statusschema bleiben unveraendert.

## PR16: Internal Admin Users, Auth, and Package Removal

Ziel:

- Den `laravel-admin`-Blocker funktional entfernen.

Umfang:

- VIMP-User/API-Token-Management ohne `laravel-admin`.
- Admin-Login, Logout und Settings ohne `Encore\Admin\Facades\Admin`.
- Bootstrap fuer interne Admin-Rollen oder bewusst reduziertes internes Rollenmodell.
- Entfernung von `laravel-admin-ext/*`.
- Entfernung von `encore/laravel-admin`, `config/admin.php`, nicht mehr genutzten Admin-Assets und alten Admin-Providern.
- Composer Audit Ergebnis dokumentieren.

Exit:

- Composer Lockfile enthaelt kein `encore/laravel-admin` mehr.
- Admin-Uploads bleiben deaktiviert oder sind nicht mehr als Package-Oberflaeche vorhanden.
- Security-Hardening-Doku und Troubleshooting verweisen auf die neue Admin-Basis.

## PR17: PHP 8 Readiness and Dependency Unblock

Ziel:

- Den PHP-8-Hop vorbereiten, nachdem der Admin-Blocker weg ist.

Umfang:

- `php-ffmpeg/php-ffmpeg` anheben oder ersetzen.
- `fideloper/proxy` entfernen oder durch Framework-Middleware ersetzen, falls fuer den naechsten Hop erforderlich.
- Mailer-Abloesung im passenden Laravel-Hop vorbereiten.
- PHP-8-Kompatibilitaetsfixes fuer App-Code und Tests.
- CI-Matrix so erweitern, dass der freigegebene PHP-8-Zielpfad sichtbar wird.
- Composer Audit dokumentieren.

Exit:

- Kein bekannter Composer-Blocker verhindert den ersten PHP-8-/Laravel-9-Hop.
- Laravel-8-Baseline bleibt gruen, bis der Runtime-Hop gemerged wird.

## PR18: PHP Runtime Hop and Laravel 9 Hop

Ziel:

- Erste unterstuetzte PHP-8-Laufzeit und Laravel-9-Zwischenstufe erreichen.

Umfang:

- Composer-Constraints fuer die freigegebene PHP-8-Version und Laravel 9.
- Anpassungen aus dem Laravel-9-Upgrade-Guide.
- Mailer-Umstellung, falls der Hop sie erzwingt.
- PHPUnit-/Test-Setup nur soweit noetig anpassen.
- Production Dockerfile, Compose-Doku und CI auf die neue Runtime heben.
- VIMP-Staging-Test fuer API, Callback, Download, Finished und Delete im Clean-Install-Setup dokumentieren.

Exit:

- App laeuft in CI und Container auf der freigegebenen PHP-8-Basis.
- Laravel 9 ist gruen mit den Contract- und Feature-Tests.

## PR19: Laravel 10/11 Bridge Hop

Ziel:

- Framework-Kompatibilitaet weiter entkoppeln, bevor das finale Ziel erreicht wird.

Umfang:

- Laravel 10 und bei Bedarf Laravel 11 als Bridge-Hops.
- Entfernen von Legacy-Factories oder anderen Zwischenpaketen, wenn nicht mehr noetig.
- Anpassungen an Config, Middleware, Exception Handling und Tests gemaess Upgrade-Guides.
- Composer Audit und Staging-Notizen aktualisieren.

Exit:

- Keine bekannte App-Abhaengigkeit blockiert den finalen Laravel-Zielhop.
- Contract-Tests und Operations-Tests bleiben gruen.

## PR20: Final Laravel Target Hop and Frontend/Admin Build Finish

Ziel:

- Final freigegebenes Laravel-Ziel erreichen und den Modernisierungstrack abschliessen.

Umfang:

- Laravel 12 oder Laravel 13 nach expliziter Zielentscheidung.
- PHP-Zielversion gemaess Freigabe.
- Node-24-basierter Build bleibt Standard, sofern kein neuer Zielentscheid vorliegt.
- Admin-/Frontend-Build auf gepflegte minimale Build-Strecke oder Vite finalisieren.
- Installations-, Deployment-, Recovery- und Operations-Dokumentation final aktualisieren.
- Full Composer Audit und finale Restrisiko-Liste.

Exit:

- `docs/UPGRADE_NOTES.md` beschreibt die neue Runtime-/Framework-Baseline.
- `docs/RELEASE_CHECKLIST.md` ist fuer Staging- und Produktionsabnahme der Neuinstallation aktuell.
- GitHub Actions, lokale Baseline und VIMP-Staging-Test sind dokumentiert gruen.

## Gemeinsame Tests pro Checkpoint

Vor Merge jedes Checkpoints:

```bash
composer validate --no-check-publish
composer install --dry-run --no-scripts --no-interaction
docker compose --env-file .env.example -f compose.yaml config
docker compose --profile smoke --profile gpu-smoke --env-file .env.example -f compose.yaml config
docker compose --env-file .env.example -f compose.yaml -f compose.dev.yaml config
sh -n docker/production/ffmpeg-smoke.sh
sh -n scripts/smoke/ffmpeg-cpu-smoke.sh
sh -n scripts/smoke/ffmpeg-gpu-smoke.sh
vendor/bin/phpunit tests/Feature/VimpContractTest.php tests/Feature/HealthMetricsTest.php tests/Feature/WorkerGuardrailsTest.php tests/Feature/StatusSchemaTest.php tests/Feature/SecurityHardeningTest.php
```

Zusaetzlich bei Dependency-, Runtime- oder Framework-Hops:

- `composer audit` Ergebnis dokumentieren.
- GitHub Actions auf dem PR abwarten.
- VIMP-Staging-Test nach `docs/VIMP_STAGING_TEST.md` oder `docs/STAGING_RUNBOOK.md` dokumentieren.
- Recovery-/Rebuild-Auswirkung in `docs/ROLLBACK_PLAN.md` oder einer spaeteren Clean-Install-Recovery-Doku pruefen.

## Abbruchkriterien

Der Track wird angehalten, wenn einer dieser Punkte eintritt:

- VIMP Contract-Test zeigt eine unbeabsichtigte API-Aenderung.
- Composer Resolve erzwingt nicht freigegebene Zielversionen.
- Admin-Token-/URL-Verhalten muesste ohne Security-Freigabe geaendert werden.
- Staging zeigt inkompatibles HLS-, Download-, Finished- oder Delete-Verhalten.
- Runtime-Hop braucht Produktions-Compose- oder Volume-Aenderungen ausserhalb des freigegebenen Clean-Install-Layouts.
