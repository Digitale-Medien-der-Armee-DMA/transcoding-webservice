# Admin Package Inventory

Stand: 2026-06-25

Dieses Inventar dokumentiert die aktuelle Abhaengigkeit von `encore/laravel-admin` und den Erweiterungen. Es ist die Grundlage fuer die Admin-Paket-Entscheidung in `docs/adr/0001-admin-package-strategy.md`.

## Composer-Pakete

| Paket | Gelockte Version | Release-Alter laut Composer | Rolle |
| --- | --- | --- | --- |
| `encore/laravel-admin` | `v1.8.11` | 2020-11-15 | Admin-Framework, Auth, Menu, Grid, Form, Show, Layout |
| `laravel-admin-ext/helpers` | `v1.2` | 2019-12-11 | Admin-Erweiterung |
| `laravel-admin-ext/log-viewer` | `v1.0.3` | 2018-11-13 | Admin-Loganzeige |
| `laravel-admin-ext/scheduling` | `v1.1` | 2019-12-11 | Scheduler-Admin-Erweiterung |

`composer audit` meldet weiterhin CVE-2023-24249 fuer `encore/laravel-admin <=1.8.19`. PR10 blockiert Admin-Uploads appseitig standardmaessig ueber `App\Http\Middleware\RejectAdminUploads`, entfernt die Avatar-Uploadfelder und setzt `ADMIN_UPLOADS_ENABLED=false`. Das schliesst den konkreten Upload-Angriffsweg in dieser App, entfernt aber nicht die Package-Advisory.

## Admin-Routen

Quelle: `app/Admin/routes.php`

| Route | Controller | Zweck | Ersatz-Relevanz |
| --- | --- | --- | --- |
| `/admin` | `HomeController@index` | Dashboard | Niedrig, kann durch einfache Blade-Seite ersetzt werden |
| `/admin/profiles` | `ProfileController` | Profile und FFmpeg-Optionen konfigurieren | Hoch, produktionsrelevant |
| `/admin/downloadqueue` | `DownloadQueueController` | Download-Jobs anzeigen/loeschen | Hoch, betriebsrelevant |
| `/admin/transcodingqueue` | `TranscodingQueueController` | Videojobs anzeigen/loeschen | Hoch, betriebsrelevant |
| `/admin/workers` | `WorkerController` | Worker-Heartbeat anzeigen | Mittel, durch Metrics ersetzbar |
| `/admin/auth/login` | `AuthController` | Admin-Login | Hoch, Auth-Ersatz erforderlich |
| `/admin/auth/setting` | `AuthController` | Eigene Admin-/VIMP-User-Settings | Hoch, enthaelt URL/API-Token-Flow |
| `/admin/users` | `UserController` | VIMP-/Admin-User verwalten | Hoch, enthaelt API-Token, URL und Rollen |

## Code-Nutzung nach Baustein

| Baustein | Dateien | Nutzung |
| --- | --- | --- |
| `Encore\Admin\Grid` | `ProfileController`, `DownloadQueueController`, `TranscodingQueueController`, `WorkerController`, `UserController` | Tabellen, Filter, Statuslabels, Actions |
| `Encore\Admin\Form` | `ProfileController`, `DownloadQueueController`, `TranscodingQueueController`, `WorkerController`, `UserController`, `AuthController` | CRUD-Forms, Nested Forms, Password-Hashing, Test-Connection-Snippet |
| `Encore\Admin\Show` | Queue-/Profile-/Worker-/User-Controller | Detailansichten |
| `Encore\Admin\Layout\Content` | Resource Controller, `HomeController` | Layout Wrapper |
| `Encore\Admin\Facades\Admin` | Auth, User scoping, Dashboard, Navbar | Guard, current user, scripts, version |
| `Encore\Admin\Controllers\HasResourceActions` | Profile/Queue/Worker Controller | Resource-Verhalten |
| `Encore\Admin\Actions\Action` | `ClearCache`, `Feedback` | Navbar-Actions |
| `Encore\Admin\Widgets\Navbar` | `app/Admin/bootstrap.php` | Navbar-Erweiterung |

## Funktionale Gruppen

### VIMP-User und Admin-Auth

Dateien:

- `app/Admin/Controllers/AuthController.php`
- `app/Admin/Controllers/UserController.php`
- `config/admin.php`

Funktionen:

- Admin-Login ueber `admin` Guard.
- User-Verwaltung auf `users`/`Administrator`.
- API-Token, VIMP-URL, Profilzuordnung.
- Test-Connection-AJAX gegen `/api/testurl`.
- Rollen/Permissions aus laravel-admin-Tabellen.

Risiko:

- API-Tokens sind sichtbar/editierbar.
- Token-/URL-Verhalten darf nicht ohne Freigabe geaendert werden.
- Ersatz braucht klare Rollen-/Permission-Migration.

### Profile und FFmpeg-Konfiguration

Datei:

- `app/Admin/Controllers/ProfileController.php`

Funktionen:

- Profile bearbeiten.
- Fallback-Profil setzen.
- Nested Forms fuer Optionen und Additional Parameters.

Risiko:

- Fehlerhafte Profile koennen Transcoding brechen.
- Ersatz braucht Validierung und Testdaten.

### Queue- und Worker-Betrieb

Dateien:

- `app/Admin/Controllers/DownloadQueueController.php`
- `app/Admin/Controllers/TranscodingQueueController.php`
- `app/Admin/Controllers/WorkerController.php`

Funktionen:

- Download-/Videojob-Listen.
- Statuslabels fuer `processed`.
- User-Scoping fuer Nicht-Administratoren.
- Delete-Actions rufen bestehende Controller-Loeschlogik.
- Worker-Heartbeat-Anzeige.

Risiko:

- Delete-Flows duerfen nicht breiter werden.
- Statusmodell muss mit `docs/STATUS_SCHEMA.md` kompatibel bleiben.

### Dashboard und Navigation

Dateien:

- `app/Admin/Controllers/HomeController.php`
- `app/Admin/Controllers/TranscodingDashboard.php`
- `app/Admin/bootstrap.php`
- `app/Admin/Extensions/Nav/AutoRefresh.php`
- `app/Admin/Extensions/Nav/Link.php`
- `app/Admin/Actions/ClearCache.php`
- `app/Admin/Actions/Feedback.php`

Funktionen:

- Runtime-/Dependency-Dashboard.
- Auto-Refresh.
- Navbar-Actions.

Risiko:

- Niedriger als CRUD/Auth.
- Kann frueh durch interne Blade/JSON-Health-Seiten ersetzt werden.

## Ersatz-Schnitt

Empfohlene Reihenfolge:

1. Admin-Dashboard und Worker/Queue-Read-Only-Views durch interne Laravel-Routen ersetzen.
2. VIMP-User- und Profile-CRUD ohne Upload-Felder ersetzen.
3. Auth/Rollenmodell explizit modellieren und laravel-admin-Tabellen abloesen oder migrieren.
4. `laravel-admin-ext/*` entfernen.
5. `encore/laravel-admin` entfernen.

Nicht in einem PR ersetzen:

- Admin-Auth.
- VIMP-User/API-Token-Verhalten.
- Profile und Queue-Delete-Flows.
- Laravel-Major-Hop.
