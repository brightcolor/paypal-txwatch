# Changelog

Alle nennenswerten Änderungen an PayPal TxWatch werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

## [0.5.4] - 2026-07-10

### Behoben

- "Verbindung testen" nutzte einen zwischengespeicherten OAuth2-Token (bis zu 9h gültig) und meldete
  weiterhin "Berechtigung Transaction Search fehlt", selbst nachdem die Berechtigung in der PayPal Developer
  Console freigeschaltet wurde – PayPal scheint Berechtigungen an den Ausstellungszeitpunkt des Tokens zu
  binden, nicht live bei jeder Anfrage zu prüfen. `PayPalClient::getAccessToken()` akzeptiert jetzt einen
  `forceFresh`-Parameter; der Verbindungstest nutzt ihn immer, damit das Ergebnis stets den aktuellen
  PayPal-seitigen Stand widerspiegelt.

## [0.5.3] - 2026-07-10

### Behoben

- Aufruf der Wurzel-URL (`/`) zeigte die generische Laravel-Skelett-Seite statt zur Anwendung zu führen –
  leitet jetzt direkt auf `/admin` weiter. Ungenutzte `resources/views/welcome.blade.php` entfernt.

## [0.5.2] - 2026-07-10

### Behoben

- Migrationsreihenfolge: `create_export_history_table` und `create_export_templates_table` hatten denselben
  Zeitstempel und liefen alphabetisch (`export_history` vor `export_templates`), obwohl `export_history` einen
  Fremdschlüssel auf `export_templates` hat. Auf SQLite (lokale Tests) unauffällig, da dort Fremdschlüssel
  standardmäßig nicht erzwungen werden – auf PostgreSQL (Produktion) schlug die Migration fehl. Zeitstempel
  von `export_templates` korrigiert.
- `docker/entrypoint.sh` scheiterte beim ersten Produktions-Deploy an `Permission denied` beim Kopieren der
  Assets in den mit `nginx` geteilten Bind-Mount (`docker/data/public` gehörte auf dem Host `root`, der
  Container schreibt als `www-data`). README ergänzt: Bind-Mount-Verzeichnisse müssen vor dem ersten Start
  `chown 33:33` (bzw. die im Image verwendete `www-data`-UID) erhalten.

## [0.5.1] - 2026-07-10

### Behoben

- Docker-Image-Build schlug fehl: `docker/Dockerfile` installierte nicht alle von den Composer-Abhängigkeiten
  benötigten PHP-Extensions (`intl` für Filament, `gd` für phpoffice/phpspreadsheet, außerdem `curl`, `gmp`,
  `mbstring`, `bcmath`, `pcntl` fürs Queue-Signal-Handling).

## [0.5.0] - 2026-07-10

### Hinzugefügt

- Produktions-Deployment über GHCR + Watchtower: `docker-compose.yml` zieht `ghcr.io/brightcolor/paypal-txwatch:latest`
  statt lokal zu bauen; `.github/workflows/ci.yml` baut/pusht das Image bei jedem Push auf `main`/`v*` (Tests
  als Gate davor). `docker/Dockerfile` kopiert Code+Assets jetzt ins Image (statt Bind-Mount-Code für lokale
  Entwicklung); `docker/entrypoint.sh` exportiert Assets für den Nginx-Container, migriert idempotent,
  seedet Rollen/Berechtigungen, cached Config/Routes. `docker/nginx.conf` mit Docker-DNS-Resolver (Watchtower-
  sicher) und "Wird aktualisiert…"-Fallback-Seite während Deploys.
- `PdfRenderer` nutzt jetzt `->noSandbox()` (Chromium-Sandbox braucht Container-Rechte, die nicht vorhanden sind).

## [0.4.0] - 2026-07-10

### Hinzugefügt

- Optionale Zwei-Faktor-Authentifizierung (TOTP, RFC 6238, kompatibel mit Google Authenticator/Authy):
  Selbstverwaltung unter **Einstellungen → Zwei-Faktor-Authentifizierung** (QR-Code, manueller Schlüssel,
  10 einmalige Wiederherstellungscodes). Panel-Zugriff wird nach Login bis zur bestätigten Challenge
  gesperrt (`EnsureTwoFactorChallengeIsPassed`); Verify-Endpunkt rate-limitiert (6/Minute).
  Basiert auf den framework-agnostischen Bibliotheken `pragmarx/google2fa` + `bacon/bacon-qr-code`
  statt eines Filament-Plugins, da verfügbare Filament-Auth-Plugins noch nicht mit Laravel 13 kompatibel sind.

### Behoben

- `UserFactory` setzte `is_active` nicht explizit, wodurch frisch per Factory erzeugte (nicht aus der DB
  neu geladene) User-Instanzen `is_active = null` statt `true` hatten und `canAccessPanel()` mit einem
  `TypeError` abbrach – betraf u. a. Tests mit `actingAs()`.

### Getestet

- Neue Tests für TOTP-Verifikation, Recovery-Code-Verbrauch und den kompletten Challenge-Redirect-Flow.

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
