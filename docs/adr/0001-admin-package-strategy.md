# ADR 0001: Admin Package Strategy

Stand: 2026-06-25

## Status

Accepted.

## Kontext

Der Webservice nutzt `encore/laravel-admin v1.8.11` als zentrales Admin-Framework. Composer Audit meldet CVE-2023-24249 fuer `encore/laravel-admin <=1.8.19`. Fuer die 1.x-Linie ist keine saubere gepatchte Zielversion verfuegbar, die das Package-Risiko vollstaendig aus dem Audit entfernt.

PR10 hat den konkreten Upload-Angriffsweg in dieser App mitigiert:

- Admin-Uploads sind standardmaessig deaktiviert.
- `App\Http\Middleware\RejectAdminUploads` blockiert Multipart-Dateien im Admin-Routing.
- Avatar-Uploadfelder wurden entfernt.
- `ADMIN_UPLOADS_ENABLED=false` ist Default.

Trotzdem bleibt das Paket formal advisory-betroffen. Ausserdem blockiert `laravel-admin` mit seinen Erweiterungen den spaeteren Laravel-/PHP-Upgradepfad.

Das Admin-Inventar liegt in `docs/ADMIN_PACKAGE_INVENTORY.md`.

## Entscheidung

`encore/laravel-admin` wird nicht als langfristige Admin-Basis akzeptiert.

Fuer die naechsten Modernisierungs-PRs gilt:

1. Keine neuen Features auf `laravel-admin` bauen.
2. Keine Admin-Uploads reaktivieren.
3. Kein Full-Composer-Update erzwingen, solange `laravel-admin` die Advisory blockiert.
4. Admin-Funktionalitaet schrittweise durch interne Laravel-Routen, Controller und Blade-Views ersetzen.
5. Erst nach funktionalem Ersatz `laravel-admin-ext/*` und danach `encore/laravel-admin` entfernen.
6. Ein Fork ist nur als kurzfristiger Hotfix erlaubt, wenn ein akuter Produktionsblocker entsteht; er ist nicht die Zielarchitektur.
7. Eine formale dauerhafte Risikoakzeptanz fuer das Paket wird nicht gewaehlt.

Kurzfristig wird das Restrisiko zeitlich begrenzt akzeptiert, weil der App-spezifische Upload-Pfad blockiert ist und ein Big-Bang-Ersatz der Admin-Oberflaeche mehr Risiko fuer VIMP-Betrieb und Produktions-Cutover erzeugen wuerde.

## Begruendung

### Warum kein dauerhafter Weiterbetrieb?

- Das Paket ist alt und advisory-betroffen.
- Admin-Erweiterungen sind ebenfalls alt.
- Composer 2.10 blockiert saubere Full-Updates.
- Spaetere Laravel-Hops bleiben schwer planbar.

### Warum kein sofortiger Big-Bang-Ersatz?

- Admin-Auth, VIMP-User/API-Token, Profile, Queue-Delete-Flows und Statusanzeigen haengen funktional zusammen.
- Ein einzelner grosser Rewrite wuerde den VIMP-Cutover-Pfad destabilisieren.
- Viele Admin-Flows betreffen produktionsrelevante Konfiguration.

### Warum kein Fork als Ziel?

- Ein Fork kann die akute Advisory formal beruhigen, uebernimmt aber Wartung eines alten Admin-Frameworks.
- Ein Fork loest nicht automatisch die Laravel-Major-Hop-Kompatibilitaet.
- Ein Fork waere nur dann pragmatisch, wenn der interne Ersatz laenger dauert als der Produktionszeitplan erlaubt.

## Ersatzplan

### PR A: Read-only Operations Views

Ziel:

- Dashboard, Worker und Queue-Listen ohne `laravel-admin` bereitstellen.

Inhalt:

- Interne Laravel-Routen unter einem neuen internen Prefix.
- Blade-Views oder JSON-nahe HTML-Views fuer Queue/Worker/Health.
- Keine Delete-/Edit-Aktionen.
- Tests fuer Zugriffsschutz und Statusdarstellung.

### PR B: Profile CRUD

Ziel:

- Profile und FFmpeg-Optionen ohne `laravel-admin` bearbeiten.

Inhalt:

- Profile list/show/edit.
- Nested Options und Additional Parameters.
- Validierung fuer FFmpeg-Optionen.
- Tests fuer Persistenz und Fallback-Profil.

### PR C: User/API-Token Management

Ziel:

- VIMP-User, API-Token, VIMP-URL und Profilzuordnung ohne `laravel-admin` verwalten.

Inhalt:

- Bestehendes Token-/URL-Verhalten beibehalten, ausser Security-Freigabe entscheidet anders.
- Test-Connection-Flow erhalten oder bewusst entfernen.
- Keine Token-Hashing-Migration in diesem PR.
- Tests fuer Auth, Validierung und User-Scoping.

### PR D: Admin Auth and Roles

Ziel:

- `admin` Guard/Rollenmodell aus `laravel-admin` abloesen.

Inhalt:

- Explizites internes Rollenmodell oder reduzierte Admin-Rolle.
- Migration/Mapping von bestehenden Admin-Rollen.
- Login/Logout/Settings ohne `laravel-admin`.

### PR E: Package Removal

Ziel:

- `laravel-admin-ext/*` und `encore/laravel-admin` aus Composer entfernen.

Inhalt:

- Config, Routes, Migrations/Tabellen und Assets bereinigen.
- Composer Audit erneut dokumentieren.
- Framework-Upgrade-Plan aktualisieren.

## Konsequenzen

Positiv:

- Entfernt den Composer-Audit-Blocker.
- Macht Laravel 9+ Hops planbarer.
- Reduziert versteckte Admin-Upload- und Extension-Risiken.
- Trennt Betriebs-UI klar vom VIMP-API-Vertrag.

Negativ:

- Mehrere Folge-PRs erforderlich.
- Kurzfristig bleibt die Package-Advisory trotz Mitigation sichtbar.
- Admin-UI muss gezielt nachgebaut und getestet werden.

## Guardrails

- `/api` bleibt unveraendert.
- Kein Upload-Reenable ohne dokumentierte Freigabe.
- Keine produktiven Codepfade im selben PR wie Package-Removal, ausser zwingend erforderlich.
- Jeder Ersatz-PR braucht mindestens Feature-Tests fuer Zugriffsschutz und betroffenen Admin-Flow.
- Composer Audit Ergebnis in jedem Dependency-PR dokumentieren.
