# Changelog

Alle nennenswerten Änderungen an PayPal TxWatch werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

## [0.3.0] - 2026-07-10

### Hinzugefügt

- Neue **Berichte**-Seite mit Zeitraumfilter: Gebührenanalyse nach Event/Monat/PayPal-Konto,
  Custom-Field-Präfix-Analyse (fasst z. B. `SOMMERFEST-001`/`-002`/… zu `SOMMERFEST` zusammen),
  Event-Zuordnungsquote, Rückzahlungs-/Reversal-Summe.
- Dashboard-Widget **Sync-Gesundheit**: pro Konto Status OK/Warnung (konfigurierbare Schwelle,
  `PAYPAL_SYNC_WARNING_THRESHOLD_HOURS`, skaliert mit dem Sync-Intervall) plus direktem
  "Verbindung testen"-Button.
- **Exporthistorie**-Ressource: Liste aller Exporte mit sicherem, ablaufenden Download-Link.
- Weitere Transaktionsfilter: "Ohne Custom Field", Land, Zahlungsart; Währung/Land/Zahlungsart jetzt
  als Mehrfachauswahl.

### Getestet

- Neue Tests für Gebühren-/Präfix-/Zuordnungs-Reports und Sync-Overdue-Erkennung.

## [0.2.0] - 2026-07-10

### Hinzugefügt

- CSV-Import von PayPal "Activity Download"-Dateien als Fallback, falls die Transaction-Search-Berechtigung
  fehlt (**PayPal → CSV-Import**): Spaltenzuordnung mit automatischer Erkennung gängiger englischer und
  deutscher Spaltennamen (inkl. Custom Number/Custom Field), Zeilenvorschau, deutsches und englisches
  Zahlenformat. Importiert über dieselbe Normalisierungs-/Event-Zuordnungs-/Idempotenz-Pipeline wie der
  API-Sync (`TransactionUpserter`, jetzt aus `SyncService` extrahiert und zwischen beiden Wegen geteilt),
  erzeugt einen regulären Sync-Lauf mit Fehlerbericht.

### Getestet

- Zusätzliche Tests für Spalten-Erkennung, CSV-Normalisierung (deutsches/englisches Zahlenformat) und
  Import-Idempotenz.

## [0.1.1] - 2026-07-10

### Behoben

- Dashboard-Widgets (Kennzahlen, Umsatz-Chart, letzte Sync-Läufe) blieben leer: `discoverWidgets()` erzeugte
  auf Windows Livewire-Komponentenschlüssel aus dem vollständigen Dateipfad (inkl. Backslashes/Laufwerksbuchstabe),
  wodurch die Hydration im Browser fehlschlug. Widgets werden jetzt explizit registriert statt per Discovery.
- Widgets rendern nicht mehr lazy (`$isLazy = false`), damit die Inhalte sofort mit der Seite kommen statt
  auf einen Nachlade-Request zu warten.

## [0.1.0] - 2026-07-10

### Hinzugefügt

- Erste lauffähige Version.
- PayPal-Konten-Verwaltung (Sandbox/Live, verschlüsselte Zugangsdaten, Verbindungstest).
- PayPal Transaction Search API Client (OAuth2 Client-Credentials, Token-Cache, `fields=all`, Pagination).
- Sync-Service: automatisches Splitting in 31-Tage-Fenster, rekursives Verkleinern bei `RESULTSET_TOO_LARGE`,
  idempotenter Upsert über einen robusten Dedupe-Key (Konto, Transaction ID, Event Code, Initiation/Updated Date,
  Reference ID, Betrag, Rohdaten-Hash) statt blinder Deduplizierung über die Transaction ID allein.
- Geplanter Sync pro Konto (konfigurierbares Intervall, Rückblick-Puffer gegen verzögerte PayPal-Daten) sowie
  manueller Sync/Backfill per Artisan-Command und Filament-Action.
- Sync-Läufe und Importfehler vollständig protokolliert und in der UI einsehbar.
- Event-/Kundenverwaltung mit regelbasierter automatischer Zuordnung (Custom Field, Invoice ID, Regex, Betrag,
  Zeitraum, PayPal-Konto) sowie manueller Zuordnung.
- Transaktionsübersicht mit umfangreicher Filterung: Custom-Field-Suche (enthält/beginnt/endet/exakt/Regex,
  case-insensitive), Volltextsuche, Datums-/Betragsbereich, Status, T-Code, Konto, Event, Gebühren, Vorzeichen,
  Rückzahlungen/Reversals, fehlende Zuordnung, Mehrfachtreffer; Filter kombinierbar, speicherbar und teilbar.
- PDF-Export (Browsershot/Chromium) des aktuell gefilterten Ergebnisses mit wählbaren/sortierbaren Spalten,
  Gruppierung, Summenzeilen, Eventinformationen, Kunde-/Intern-Modus und PII-Maskierung; zusätzlich CSV/XLSX-Export.
  Export-Vorlagen speicherbar, Exporthistorie mit Ablaufdatum.
- Dashboard mit Kennzahlen (Umsatz, Gebühren, Netto, Rückzahlungen, unzugeordnete Transaktionen), Umsatzverlauf
  und letzten Sync-Läufen.
- Rollenmodell (Admin, Manager, Kunde, Auditor) inkl. Mandanten-Scoping für Kunden-Nutzer.
- Docker Compose Setup (App, Nginx, Postgres, Redis, Queue-Worker, Scheduler) mit Bind Mounts.
- Tests für Zeitraum-Splitting, PayPal-Normalisierung/Idempotenz, Sync-Fehlerbehandlung und Export-Konfiguration.
