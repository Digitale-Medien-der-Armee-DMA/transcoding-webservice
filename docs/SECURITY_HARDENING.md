# Security Hardening

Stand: 2026-06-24

Diese Sicherheitsmassnahmen sind so eingefuehrt, dass bestehende VIMP-Flows standardmaessig kompatibel bleiben.

## Source-URL-Allowlist

Standard:

```env
SECURITY_SOURCE_URL_ALLOWLIST_ENABLED=false
SECURITY_SOURCE_URL_ALLOWED_HOSTS=
SECURITY_SOURCE_URL_ALLOW_USER_HOST=true
```

Wenn die Allowlist aktiv ist, sind nur `http` und `https` erlaubt. Hosts koennen explizit in `SECURITY_SOURCE_URL_ALLOWED_HOSTS` eingetragen werden. Mit `SECURITY_SOURCE_URL_ALLOW_USER_HOST=true` bleibt der beim API-User konfigurierte VIMP-Host erlaubt.

## Download-Limits

```env
SECURITY_DOWNLOAD_TIMEOUT_SECONDS=300
SECURITY_DOWNLOAD_CONNECT_TIMEOUT_SECONDS=10
SECURITY_DOWNLOAD_MAX_BYTES=0
```

`SECURITY_DOWNLOAD_MAX_BYTES=0` bedeutet kein hartes Groessenlimit. Wenn ein Limit gesetzt ist und die Quelle groesser ist, wird der Download abgebrochen, der Download-Status auf `FAILED` gesetzt und es werden keine Videojobs erzeugt.

## Pfadvalidierung

Storage-Pfade fuer Download, Finished-Markierung und Delete muessen relative Pfade ohne leere Segmente, absolute Prefixe oder `..`-Segmente sein. Mediakeys fuer Delete muessen weiterhin 32 alphanumerische Zeichen haben.

## Log-Scrubbing

```env
SECURITY_LOG_SCRUBBING_ENABLED=true
```

Log-Scrubbing maskiert sensible Context-Keys wie `api_token`, `authorization`, `password` und `secret` sowie entsprechende Query-/Header-Fragmente in Log-Strings.

## Admin-Uploads

```env
ADMIN_UPLOADS_ENABLED=false
```

Admin-Uploads sind standardmaessig deaktiviert, weil `encore/laravel-admin <=1.8.19` eine nicht gepatchte Arbitrary-File-Upload-Advisory hat. Die App nutzt keine Admin-Upload-Felder mehr; `App\Http\Middleware\RejectAdminUploads` blockiert Multipart-Dateien im Admin-Routing. Eine Aktivierung darf nur nach dokumentierter Risikoakzeptanz oder nach Ersatz/Fork des Admin-Pakets erfolgen.

## Admin-Credentials und Token-Rotation

- Initiale Admin-Credentials nur ueber kontrollierte Setup-Prozedur erzeugen und danach rotieren.
- API-Tokens pro VIMP-User getrennt halten.
- Token-Rotation zuerst in VIMP und Transcoding-Webservice parallel vorbereiten, dann alten Token entfernen.
- Tokens nie in Logs, PRs, Screenshots oder Compose-Dateien committen.
