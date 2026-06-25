# Upgrade Notes

Stand: 2026-06-25

Diese Notizen fassen den aktuellen Modernisierungsstand zusammen und definieren Leitplanken fuer Folge-Upgrades.

## Aktueller Stand

- Laravel-Basis: Laravel 8 auf PHP-7.4-Kompatibilitaetslinie.
- CI: GitHub Actions auf Ubuntu 24.04 mit PHP 7.4 fuer die Laravel-8-Baseline.
- Queue: Redis-orientierter Blueprint mit getrennten Queues `download` und `video`.
- GPU: nur `worker-video-gpu` hat NVIDIA Device Reservation.
- FFmpeg-Smoke: CPU-Smoke in CI, GPU-Smoke auf Zielhost.
- Admin-Uploads: standardmaessig deaktiviert.

## Bekannte Restrisiken

- Laravel 8 ist EOL und muss weiter auf Laravel 9+ gehoben werden.
- `encore/laravel-admin <=1.8.19` hat keine gepatchte 1.x-Version fuer die Upload-Advisory; die Strategie ist in `docs/adr/0001-admin-package-strategy.md` festgelegt.
- `swiftmailer/swiftmailer` ist abandoned.
- `php-ffmpeg/php-ffmpeg` blockiert den PHP-8-Hop und muss separat bewertet werden.
- Frontend/Admin-Build ist noch nicht final modernisiert.

## Upgrade-Regeln

- Ein Upgrade-Thema pro PR.
- Vor jedem Dependency-Hop Contract-Tests gruen.
- Nach jedem Dependency-Hop VIMP-Staging-Test wiederholen.
- Keine ungeplanten Full-Updates auf Composer 2.10, solange Advisory-Blocks nicht bewusst behandelt sind.
- Keine produktiven `/api`-Aenderungen ohne Contract-Test und Freigabe.
- Keine Token-/URL-Verhaltensaenderung ohne Security-Freigabe.

## Empfohlene Folge-Reihenfolge

1. Read-only Admin-Ersatz fuer Dashboard, Worker und Queue-Listen.
2. Profile-CRUD ohne `laravel-admin`.
3. VIMP-User/API-Token-Management ohne `laravel-admin`.
4. Admin-Auth/Rollenmodell abloesen.
5. `laravel-admin-ext/*` und `encore/laravel-admin` entfernen.
6. Mailer-Abloesung: SwiftMailer zu Symfony Mailer im passenden Laravel-Hop.
7. `php-ffmpeg/php-ffmpeg` anheben oder ersetzen.
8. PHP-8-Runtime-Hop vorbereiten.
9. Laravel 9/10 Zwischenhop mit Contract-Tests.
10. Laravel 12 oder 13 Zielhop nach finaler Zielentscheidung.
11. Frontend/Admin-Build modernisieren.

## Vor jedem Upgrade-PR

```bash
git checkout master
git pull --ff-only
composer validate --no-check-publish
composer install --dry-run --no-scripts --no-interaction
vendor/bin/phpunit tests/Feature/VimpContractTest.php tests/Feature/HealthMetricsTest.php tests/Feature/WorkerGuardrailsTest.php tests/Feature/StatusSchemaTest.php tests/Feature/SecurityHardeningTest.php
```

## Nach jedem Upgrade-PR

- GitHub Actions abwarten.
- `docs/FRAMEWORK_UPGRADE_STAGE_1.md` aktualisieren, wenn Framework-/Runtime-Baseline betroffen ist.
- `docs/RELEASE_CHECKLIST.md` fuer Staging ausfuellen.
- VIMP-Staging-Test dokumentieren.
- Composer Audit Ergebnis dokumentieren, inklusive bewusst akzeptierter Restrisiken.
