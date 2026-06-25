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

- PR14-20 laufen als ein gemeinsamer iterativer Upgrade-Track, dokumentiert in `docs/ITERATIVE_UPGRADE_TRACK.md`.
- Jeder Track-Checkpoint bleibt ein eigener mergebarer PR mit klarem Exit-Kriterium.
- Vor jedem Dependency-Hop Contract-Tests gruen.
- Nach jedem Dependency-Hop VIMP-Staging-Test wiederholen.
- Keine ungeplanten Full-Updates auf Composer 2.10, solange Advisory-Blocks nicht bewusst behandelt sind.
- Keine produktiven `/api`-Aenderungen ohne Contract-Test und Freigabe.
- Keine Token-/URL-Verhaltensaenderung ohne Security-Freigabe.

## Empfohlene Folge-Reihenfolge

1. PR14: Internal Admin Operations Shell.
2. PR15: Internal Profile and Queue Management.
3. PR16: Internal Admin Users, Auth, and Package Removal.
4. PR17: PHP 8 Readiness and Dependency Unblock.
5. PR18: PHP Runtime Hop and Laravel 9 Hop.
6. PR19: Laravel 10/11 Bridge Hop.
7. PR20: Final Laravel Target Hop and Frontend/Admin Build Finish.

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
