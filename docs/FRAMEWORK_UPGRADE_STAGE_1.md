# Framework Upgrade Stage 1

Stand: 2026-06-24

Dieser Pfad modernisiert das Framework bewusst konservativ. Die urspruengliche Produktionsbasis lief auf Laravel 7.30.4, PHP 7.4 und Node 12. Ein direkter Sprung auf Laravel 12/13 wuerde PHP, Laravel, Admin-Paket, FFmpeg-Paket, PHPUnit und Frontend-Build gleichzeitig bewegen.

## Entscheidung

Zielkorridor:

- Stage 1: Laravel-7-Baseline einfrieren und Upgrade-Readiness in CI pruefen.
- Stage 2: Laravel 7 -> Laravel 8.x mit PHP 7.4 als Zwischenlaufzeit.
- Stage 3: PHP 8.x, Composer-Abhaengigkeiten und Laravel 9+ erst nach gruenem Laravel-8-Hop.

Kein direkter Laravel-12/13-Sprung in einem PR.

## Aktuelle Baseline

Der CI-Check `scripts/upgrade/framework_stage1_check.php` prueft nach PR9 diese Lockfile-Basis:

| Paket | Locked Version | Notiz |
| --- | --- | --- |
| `laravel/framework` | `v8.83.29` | aktuelle App-Basis |
| `laravel/ui` | `v3.4.6` | Admin/Auth UI-Abhaengigkeit |
| `encore/laravel-admin` | `v1.8.11` | zentrales Admin-Paket, separat zu modernisieren |
| `guzzlehttp/guzzle` | `7.12.3` | VIMP HTTP-Kommunikation |
| `laravel/legacy-factories` | `v1.4.2` | erhaelt Laravel-7-Factory-Semantik waehrend des Laravel-8-Hops |
| `php-ffmpeg/php-ffmpeg` | `v0.16` | blockiert PHP-8-Runtime |
| `phpunit/phpunit` | `9.6.34` | aktuelle Testbasis |

Composer-Constraints fuer PR9:

```json
"php": "^7.4",
"laravel/framework": "^8.83"
```

`composer.json` setzt zusaetzlich `config.platform.php` auf `7.4.33`, damit Lockfile-Resolves auch auf neueren lokalen PHP-Versionen zur CI-/Container-Laufzeit passen.

## Naechster Composer-Hop

Die naechsten Hops laufen als zusammengefasster Track in `docs/ITERATIVE_UPGRADE_TRACK.md`. Die Reihenfolge bleibt iterativ, aber PR14-20 werden als ein gemeinsamer Modernisierungsschritt geplant.

Naechste Dependency- und Runtime-Gates:

- PR14-16: `encore/laravel-admin` gemaess `docs/adr/0001-admin-package-strategy.md` schrittweise ersetzen und entfernen.
- PR17: `fideloper/proxy`, Mailer- und `php-ffmpeg/php-ffmpeg`-Blocker fuer PHP 8/Laravel 9 loesen.
- PR18: Freigegebene PHP-8-Runtime und Laravel-9-Hop umsetzen.
- PR19: Laravel-10/11-Bridge-Hops umsetzen, falls fuer das finale Ziel noetig.
- PR20: Final freigegebenes Laravel-Ziel und Admin-/Frontend-Build abschliessen.

## PR10 Security-Hardening

PR10 blockiert Admin-Uploads standardmaessig ueber `App\Http\Middleware\RejectAdminUploads` und entfernt die Avatar-Upload-Felder aus den Admin-Forms. Uploads koennen nur explizit mit `ADMIN_UPLOADS_ENABLED=true` wieder aktiviert werden.

Zusaetzlich entfernt PR10 `laravel/tinker`, damit `psy/psysh` nicht mehr installiert wird, und hebt die PHP-7.4-kompatiblen Test-/Parser-Pakete auf sichere Patchlinien.

Bekannte Restrisiken bleiben:

- `encore/laravel-admin <=1.8.19` hat laut GitHub Advisory keine gepatchte 1.x-Version; der Upload-Angriffsweg ist in dieser App blockiert, das Paket bleibt aber formal advisory-betroffen.
- Laravel 8 bleibt EOL und wird erst durch den spaeteren Laravel-9+-Hop aus den Framework-Advisories herausgefuehrt.

## Keine neuen Tools in diesem Hop

Rector, Pint oder ein neues PHPUnit-Setup werden in PR9 nicht eingefuehrt. Erst wenn der Laravel-8-Hop gruen ist, lohnt sich eine gezielte Toolentscheidung.

## CI-Gate

GitHub Actions fuehrt weiterhin aus:

- Compose-Validierung
- FFmpeg CPU-Smoke
- Composer Validate
- Framework-Baseline-Check
- bestehende VIMP-, Health-, Worker-, Status- und Security-Feature-Tests

Damit bleibt der VIMP Contract-Test die Upgrade-Sicherheitsleine.
