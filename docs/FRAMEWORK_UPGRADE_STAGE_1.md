# Framework Upgrade Stage 1

Stand: 2026-06-24

Dieser PR startet den Framework-Upgradepfad ohne sofortigen Composer-Resolve. Das ist bewusst konservativ: Die aktuelle Produktionsbasis laeuft auf Laravel 7.30.4, PHP 7.4 und Node 12. Ein direkter Sprung auf Laravel 12/13 wuerde PHP, Laravel, Admin-Paket, FFmpeg-Paket, PHPUnit und Frontend-Build gleichzeitig bewegen.

## Entscheidung

Erster Zielkorridor:

- Stage 1: aktuelle Laravel-7-Baseline einfrieren und Upgrade-Readiness in CI pruefen.
- Stage 2: Laravel 7 -> Laravel 8.x mit PHP 7.4 als Zwischenlaufzeit.
- Stage 3: PHP 8.x, Composer-Abhaengigkeiten und Laravel 9+ erst nach gruenem Laravel-8-Hop.

Kein direkter Laravel-12/13-Sprung in einem PR.

## Aktuelle Baseline

Der CI-Check `scripts/upgrade/framework_stage1_check.php` prueft diese Lockfile-Basis:

| Paket | Locked Version | Notiz |
| --- | --- | --- |
| `laravel/framework` | `v7.30.4` | aktuelle App-Basis |
| `laravel/ui` | `v2.5.0` | Admin/Auth UI-Abhaengigkeit |
| `encore/laravel-admin` | `v1.8.11` | zentrales Admin-Paket |
| `guzzlehttp/guzzle` | `6.5.5` | VIMP HTTP-Kommunikation |
| `php-ffmpeg/php-ffmpeg` | `v0.16` | blockiert PHP-8-Runtime |
| `phpunit/phpunit` | `9.5.4` | aktuelle Testbasis |

Composer-Constraints bleiben in Stage 1 unveraendert:

```json
"php": "^7.2.5",
"laravel/framework": "^7.5"
```

Der Grund: Ohne lokalen Composer/PHP und ohne Lockfile-Resolve soll dieser PR keine halb aktualisierten Constraints erzeugen. Der naechste PR kann die Constraints samt `composer.lock` gezielt aktualisieren und in GitHub Actions pruefen.

## Naechster Composer-Hop

Vorgeschlagener erster echter Dependency-PR:

- PHP Runtime in CI weiterhin `7.4`.
- `laravel/framework` auf Laravel 8.x heben.
- `laravel/ui` auf eine Laravel-8-kompatible Linie heben.
- `nunomaduro/collision` passend zur Laravel-8-Testbasis heben.
- `fideloper/proxy` entfernen oder ersetzen, wenn der Laravel-8-Upgradepfad das verlangt.
- `guzzlehttp/guzzle` und Admin-Erweiterungen nur dann heben, wenn Composer sie fuer Laravel 8 verlangt.
- `php-ffmpeg/php-ffmpeg` erst fuer PHP 8.x separat anheben.

## Keine neuen Tools in Stage 1

Rector, Pint oder ein neues PHPUnit-Setup werden in Stage 1 nicht eingefuehrt. Erst wenn der Laravel-8-Hop gruen ist, lohnt sich eine gezielte Toolentscheidung.

## CI-Gate

GitHub Actions fuehrt weiterhin aus:

- Compose-Validierung
- FFmpeg CPU-Smoke
- Composer Validate
- Framework-Stage-1-Readiness-Check
- bestehende VIMP-, Health-, Worker-, Status- und Security-Feature-Tests

Damit bleibt der VIMP Contract-Test die Upgrade-Sicherheitsleine.
