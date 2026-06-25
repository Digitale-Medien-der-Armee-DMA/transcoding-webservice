# VIMP Staging Test

Stand: 2026-06-25

Dieser Test prueft den Vertrag zwischen VIMP und Transcoding Webservice in einer echten Staging-Umgebung. Er ergaenzt die automatisierten Contract-Tests und ist vor jedem Produktionsschwenk erforderlich.

## Ziel

- VIMP kann Transcoding-Jobs starten.
- Der Webservice laedt Quellen von VIMP.
- Download- und Video-Queue arbeiten getrennt.
- Artefakte werden ausgeliefert.
- VIMP erhaelt die erwarteten Callbacks.
- Finished- und Delete-Flows funktionieren.

## Vorbereitung

In VIMP-Staging:

- Staging-User fuer den Transcoding Webservice anlegen.
- API-Token erzeugen.
- Webservice-URL konfigurieren.
- Testvideo bereitstellen.

Im Webservice:

- User mit VIMP-Staging-URL konfigurieren.
- API-Token setzen.
- Profil fuer mindestens MP4-Transcoding aktivieren.
- Optional HLS/Thumbnail/Spritemap-Profil aktivieren, wenn VIMP das im Zielbetrieb nutzt.

## Testdaten

Mindestens:

- Kleines MP4 fuer schnellen Smoke.
- Mittleres MP4 fuer realistischere Laufzeit.
- Optional ein HLS-relevanter Test, wenn HLS im Zielbetrieb aktiv ist.

Nicht nutzen:

- Produktive Kundendaten.
- Personenbezogene oder klassifizierte Inhalte.
- Tokens in Dateinamen oder Query-Parametern ausserhalb des vorgesehenen API-Vertrags.

## Ablauf

1. Ready-Health pruefen:

```bash
curl -fsS "$APP_URL/internal/health/ready"
```

2. VIMP startet Transcode ueber `/api/transcode`.
3. Webservice erzeugt Download-Datensatz und Download-Job.
4. Download-Worker laedt Quelle in `uploaded-media`.
5. Video-Worker erzeugt Artefakte in `converted-media`.
6. VIMP ruft `/api/download/{filename}` fuer Artefakte ab.
7. VIMP ruft `/api/download/{filename}/finished`.
8. Scheduler/Command `transcode:cleanup` setzt finalen Downloadstatus und sendet finalen Callback.
9. VIMP ruft optional `/api/delete/{mediakey}`.

## Beobachtung

Waehrend des Tests:

```bash
curl -fsS "$APP_URL/internal/metrics"
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-download
docker compose --env-file .env -f compose.yaml logs --tail=200 worker-video-gpu
docker compose --env-file .env -f compose.yaml logs --tail=200 scheduler
```

Pruefen:

- `queues.download.waiting` faellt wieder auf 0.
- `queues.video.waiting` faellt wieder auf 0.
- `transcoding.running_jobs` faellt wieder auf 0.
- `transcoding.failed_jobs` steigt nicht unerwartet.
- `workers.stale` bleibt 0.
- `storage.uploaded.free_bytes` und `storage.converted.free_bytes` bleiben plausibel.

## Akzeptanzkriterien

Bestanden, wenn:

- VIMP sieht den Job als erfolgreich.
- Alle erwarteten Artefakte wurden abgerufen.
- Finished-Markierung betrifft nur Dateien des authentifizierten Users.
- Delete entfernt nur den Test-Mediakey.
- Logs enthalten keine Tokens.
- Ready-Health bleibt gruen.
- Kein Worker bleibt stale.

Blockierend:

- Falsches Response-Format an `/api`.
- Fehlender oder doppelter Callback.
- Artefakt-URL fuer VIMP nicht erreichbar.
- Unerwartete Loeschung fremder Artefakte.
- GPU-Smoke nicht bestanden, wenn GPU-Profile getestet werden.
