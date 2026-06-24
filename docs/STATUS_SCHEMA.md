# Status Schema

Stand: 2026-06-24

Die Spalte `processed` bleibt aus Kompatibilitaetsgruenden erhalten. Sie ist aber kein Boolean, sondern ein Integer-Statusfeld.

## Werte

| Wert | Konstante | Bedeutung |
| --- | --- | --- |
| `0` | `UNPROCESSED` | Job wurde angelegt, aber noch nicht verarbeitet |
| `1` | `PROCESSED` | Job wurde erfolgreich verarbeitet |
| `2` | `PROCESSING` | Job laeuft gerade |
| `3` | `FAILED` | Job ist fehlgeschlagen |

Die Konstanten sind in `App\Models\Download` und `App\Models\Video` identisch.

## Migration

Die Migration `2026_06_24_133000_change_processed_columns_to_status_integers.php` aendert:

- `downloads.processed`
- `videos.processed`

auf Integer-kompatible Statusspalten. Bestehende gueltige Werte `0..3` bleiben erhalten. `NULL` oder ungueltige Werte werden vor der Typanpassung auf `0` normalisiert.

## Rollback

Ein Rollback auf Boolean ist datenreduzierend. `PROCESSING` und `FAILED` werden dabei auf `PROCESSED` abgebildet, weil Boolean nur `0/1` darstellen kann. Vor Rollback in Staging/Produktion ist ein Datenbank-Backup Pflicht.

## Admin und API

Admin-Listen nutzen weiterhin die Spalte `processed`, zeigen aber alle vier Statuswerte an. Die VIMP-kompatiblen API-Antworten bleiben unveraendert.
