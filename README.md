# PayPal TxWatch

Web-Anwendung zum regelmäßigen Abruf von PayPal-Business-Transaktionen über die
**PayPal Transaction Search API**, lokaler Speicherung, Normalisierung, komfortabler Such-/Filterfunktion
(insbesondere: Suche nach Zeichenketten im `custom_field`, was PayPal selbst nicht anbietet) und
kundengeeignetem PDF-/CSV-/XLSX-Export.

## Architektur

| Baustein        | Wahl                                    | Begründung |
|-----------------|------------------------------------------|------------|
| Backend         | Laravel 13                                | Robustes, gut wartbares PHP-Framework mit ausgereiftem Queue-/Scheduler-System |
| Admin-UI        | Filament 3                                | Liefert Tabellen mit Filtern, Formularen, Rollen und Export-Actions, ohne diese von Hand bauen zu müssen |
| Datenbank       | PostgreSQL                                 | Wie gefordert; produktionsreif, gute JSON-/Index-Unterstützung |
| Queue/Scheduler | Redis + Laravel Queue Worker + `schedule:run` | Sync-Läufe und PDF-Erzeugung laufen als Hintergrundjobs, retryfähig |
| PDF-Erzeugung   | Spatie Browsershot (Chromium headless)     | Sauberer Mehrseiten-Umbruch über echtes CSS/`@page`, robuster als dompdf bei komplexen Tabellen |
| Rollen/Rechte   | spatie/laravel-permission                  | Admin/Manager/Kunde/Auditor |
| Audit-Log       | spatie/laravel-activitylog                 | Administrative Aktionen nachvollziehbar |
| Deployment      | Docker Compose (Bind Mounts)                | App, Nginx, Postgres, Redis, Queue-Worker, Scheduler |

Code-Struktur (Trennung wie in Aufgabenstellung gefordert):

```
app/Services/PayPal/     PayPal-API-Client (OAuth2, Transaction Search, Fehler-Mapping)
app/Services/Sync/        Sync-Orchestrierung, Zeitraum-Splitting, Normalisierung, Event-Zuordnung
app/Services/Export/      Export-Datenaufbereitung (Spalten, Gruppierung, Summen) + PDF-Renderer
app/Models/                Repository-Layer (Eloquent)
app/Filament/              UI (Resources, Actions, Widgets)
app/Jobs/, app/Console/    Hintergrundjobs, Artisan-Commands, Scheduler
```

## Installation (lokal, ohne Docker)

Voraussetzungen: PHP 8.3+, Composer, PostgreSQL, Redis, Node.js 18+ (nur für PDF-Export nötig).

```bash
composer install
cp .env.example .env
php artisan key:generate
# .env anpassen: DB_* auf eine lokale Postgres-Instanz zeigen lassen,
# oder für schnellen Start DB_CONNECTION=sqlite setzen.
php artisan migrate --seed
php artisan serve
```

Der Seeder legt Rollen (`admin`, `manager`, `customer`, `auditor`) sowie einen Admin-Benutzer
`admin@example.com` / `password` an. **Passwort nach dem ersten Login sofort ändern.**

Für den PDF-Export lokal ohne Docker wird ein Node.js mit `puppeteer` sowie ein Chromium/Chrome-Binary
benötigt; siehe `config/pdf.php` (`CHROMIUM_PATH`, `NODE_MODULE_PATH` in `.env`). Ohne Docker ist das optional –
CSV-/XLSX-Export funktionieren unabhängig davon immer.

## Deployment mit Docker Compose (Produktion)

`docker-compose.yml` zieht ein fertig gebautes Image aus der GitHub Container Registry
(`ghcr.io/brightcolor/paypal-txwatch:latest`) statt lokal zu bauen. Ein GitHub-Actions-Workflow
(`.github/workflows/ci.yml`) baut und pusht das Image bei jedem Push auf `main` bzw. `v*`-Tags automatisch
(Tests laufen davor als Gate).

Auf dem Server wird **kein vollständiger Checkout** benötigt – nur ein schlankes Deploy-Verzeichnis:

```bash
mkdir -p /opt/paypal-txwatch/docker/data/{public,postgres,redis}
mkdir -p /opt/paypal-txwatch/storage/{app/public,app/private,framework/{cache/data,sessions,views},logs}
# app/queue/scheduler laufen im Image als www-data (UID 33) - die Bind-Mounts, in die
# der Container schreibt, müssen dem gehören, sonst schlägt der Start mit "Permission
# denied" beim Asset-Export bzw. bei storage/ fehl:
chown -R 33:33 /opt/paypal-txwatch/docker/data/public /opt/paypal-txwatch/storage
cd /opt/paypal-txwatch
# docker-compose.yml und docker/nginx.conf aus dem Repo hierher kopieren
cp .env.example .env
php artisan key:generate --show   # oder: openssl rand -base64 32, mit "base64:" Prefix in APP_KEY eintragen
# .env: APP_URL, DB_PASSWORD, APP_PORT setzen. Echte PayPal-Zugangsdaten NICHT in .env -
# die werden verschlüsselt in der DB gepflegt (siehe "PayPal-App einrichten" unten).
docker compose up -d
```

Die App läuft danach unter `http://<server>:${APP_PORT}/admin` (Standard-Port `8090`, siehe `.env`).
Beim allerersten Start einen Admin-Benutzer anlegen:

```bash
docker compose exec app php artisan tinker --execute="
\$u = App\Models\User::create(['name'=>'Admin','email'=>'admin@example.com','password'=>Hash::make('CHANGE-ME'),'is_active'=>true]);
\$u->assignRole('admin');
"
```

Services:

- `app` – PHP-FPM (Laravel); `docker/entrypoint.sh` kopiert die Assets nach `docker/data/public` (für `web`),
  migriert (`--isolated`, sicher bei gleichzeitigem Start mehrerer Container), seedet Rollen/Berechtigungen
  und cached Config/Routes bei jedem Start
- `web` – Nginx, serviert `docker/data/public` und reicht `*.php` an `app:9000` weiter (mit Docker-DNS-Resolver,
  damit Watchtower-Container-Ersetzungen nicht zu einer veralteten IP führen); zeigt während Deploys eine
  automatisch neu ladende "Wird aktualisiert…"-Seite statt eines 502
- `queue` – `php artisan queue:work` (Sync-Jobs, Hintergrundverarbeitung)
- `scheduler` – führt `php artisan schedule:run` minütlich aus (steuert `paypal:schedule-sync`)
- `db` – PostgreSQL (Bind Mount `docker/data/postgres`)
- `redis` – Redis (Bind Mount `docker/data/redis`)

Persistente Daten (`storage/`, `docker/data/*`) liegen als **Bind Mounts** neben dem Compose-File, keine
benannten Docker-Volumes. Läuft bereits [Watchtower](https://github.com/nicholas-fedor/watchtower) auf dem
Host, aktualisiert es die Container automatisch, sobald CI ein neues `:latest`-Image gepusht hat.

### Backups

`docker/backup.sh` sichert die Postgres-Datenbank (pg_dump, custom format, gzip) und `storage/app`
(Exporte, Logos) nach `backups/` neben dem Compose-File und rotiert nach 14 Tagen. Einrichtung auf dem
Server (Skript liegt dort als `/opt/paypal-txwatch/backup.sh`):

```bash
cp docker/backup.sh /opt/paypal-txwatch/backup.sh && chmod +x /opt/paypal-txwatch/backup.sh
cat > /etc/cron.d/paypal-txwatch-backup <<'EOF'
30 3 * * * root /opt/paypal-txwatch/backup.sh >> /var/log/paypal-txwatch-backup.log 2>&1
EOF
```

Restore der DB: siehe Kommentar im Skript. **Wichtig:** `backups/` zusätzlich regelmäßig auf ein anderes
System kopieren (Offsite) – ein Backup auf derselben Platte schützt nicht vor Plattenausfall. Das Skript
enthält dafür einen auskommentierten `rclone copy`-Block; einmalig `rclone config` einrichten (z. B. S3
oder ein anderer Host) und die Zeile aktivieren.

**Backup-Überwachung:** `backup.sh` schreibt nach jedem Lauf einen Zeitstempel nach
`storage/app/last-backup-at` (in den Container gemountet). Der tägliche Scheduler-Befehl `backup:check`
(09:00) benachrichtigt alle Admins über die Glocke, wenn das letzte Backup fehlt oder älter als 36 Stunden
ist – so fällt ein stillschweigend gestopptes Backup auf.

### Lokale Docker-Entwicklung

Für lokale Entwicklung ohne Docker reicht `php artisan serve` (siehe oben) – produktionsnahe Container mit
Postgres/Redis/Chromium sind primär fürs Server-Deployment gedacht. Wer trotzdem lokal mit Docker bauen will,
kann `image:` in `docker-compose.yml` durch `build: {context: ., dockerfile: docker/Dockerfile}` ersetzen.

## PayPal-App einrichten

1. Im [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/) eine REST-API-App anlegen
   (Sandbox oder Live).
2. Unter "Features" die Berechtigung **"Transaction Search"** aktivieren – ohne diese liefert die API
   `PERMISSION_DENIED`/403.
3. Client ID und Secret in PayPal TxWatch unter **PayPal → PayPal-Konten → Neu** hinterlegen (werden
   verschlüsselt in der Datenbank gespeichert, `client_id`/`client_secret` sind `encrypted`-Casts).
4. Mit **"Verbindung testen"** prüfen, ob Zugangsdaten und Berechtigung passen.
5. Sync-Intervall und Rückblick-Puffer einstellen (Default: alle 15 Minuten, 4h Rückblick – PayPal kann
   Transaktionen bis zu ~3h verzögert bereitstellen).

## Scheduler/Worker starten (ohne Docker)

```bash
# Terminal 1: Queue-Worker (verarbeitet Sync-Jobs)
php artisan queue:work --tries=5

# Terminal 2: Scheduler (löst je nach Konto-Intervall Sync-Jobs aus)
php artisan schedule:work
```

## Sync ausführen

**Automatisch:** Sobald Scheduler + Worker laufen und ein Konto `sync_enabled=true` hat, wird es gemäß
seinem Intervall automatisch synchronisiert.

**Manuell / Backfill:**

```bash
# Einzelnes Konto, definierter Zeitraum (wird automatisch in 31-Tage-Blöcke gesplittet)
php artisan paypal:sync --account=1 --from=2026-01-01 --to=2026-12-31

# Alle aktiven Konten
php artisan paypal:sync --account=all --from=2026-01-01 --to=2026-01-31

# Synchron statt über die Queue (z. B. zum Debuggen)
php artisan paypal:sync --account=1 --from=2026-01-01 --to=2026-01-31 --sync
```

Oder über die UI: **PayPal-Konten → Backfill starten**.

Sync-Läufe (Start/Ende, Zeitraum, importiert/aktualisiert/übersprungen/Fehler, API-Requests, Dauer) und
Importfehler sind unter **PayPal → Sync-Läufe** einsehbar.

## CSV-Import (Fallback ohne API-Zugriff)

Falls die Transaction-Search-Berechtigung (noch) nicht verfügbar ist, kann ein PayPal
**"Activity Download"**-CSV importiert werden – unter **PayPal → CSV-Import**:

1. PayPal-Konto wählen und CSV-Datei hochladen.
2. Spaltenzuordnung prüfen: gängige englische und deutsche Spaltennamen (`Gross`/`Brutto`,
   `Custom Number`/`Benutzerdefinierte Nummer`, `Transaction ID`/`Transaktionscode`, …) werden automatisch
   erkannt und vorausgefüllt, bei Bedarf manuell anpassbar. Eine Vorschau der ersten Zeilen wird angezeigt.
3. **"Import starten"** – der Import läuft über dieselbe Normalisierungs-/Zuordnungs-/Idempotenz-Pipeline
   wie der API-Sync (gleicher Dedupe-Key-Mechanismus, gleiche Event-Zuordnungsregeln) und erzeugt einen
   regulären Sync-Lauf vom Typ "CSV-Import" mit Fehlerbericht.

Zahlen werden sowohl im deutschen (`1.234,56`) als auch im englischen Format (`1,234.56`) erkannt.

## pretix-Anbindung

Unter **pretix → pretix-Verbindungen** wird eine pretix-Instanz hinterlegt (Basis-URL, Organizer-Slug,
API-Token – verschlüsselt gespeichert) und per **"Verbindung testen"** geprüft. **"Import & Abgleich"**
läuft als Hintergrund-Job (Live-Fortschritt unter **pretix → pretix-Importe**, inkl. zeitgestempeltem
Verlauf) und macht drei Dinge:

1. **Import** aller Bestellungen aller Events als Referenzdaten (idempotent).
2. **Verbuchung der Nicht-PayPal-Zahlungen**: Bezahlte Bestellungen mit anderer Zahlungsart (Überweisung,
   Boxoffice, …) werden als eigene Transaktionen angelegt – Überweisungen mit der an der Verbindung
   konfigurierten **Gebühr (Standard 0,20 €/Transaktion)**, damit die Abrechnung den gesamten Umsatz korrekt
   abbildet. PayPal-Bestellungen werden übersprungen (kommen über den PayPal-Sync; Schalter an der
   Verbindung kann das übersteuern).
3. **Abgleich (PayPal führend)**: Jede PayPal-Transaktion mit Bestellnummer erhält einen Status –
   *abgeglichen* (pretix-Summe plausibel gleich), *Betrag weicht ab* oder *nicht in pretix* – als Spalte und
   Filter in der Transaktionsliste.

Verknüpfte **Bestellnummern sind klickbar** (↗-Symbol) und öffnen die Bestellung im pretix-Control-Panel in
einem neuen Fenster.

Weitere Automatik:

- **Auto-Import**: Verbindungen mit "Automatischer Import aktiv" werden **alle 30 Minuten** importiert und
  abgeglichen (Scheduler; Überlappungsschutz pro Verbindung).
- **Webhook (nahezu Echtzeit, optional)**: Jede Verbindung zeigt in der Bearbeiten-Ansicht eine eigene
  **Webhook-URL** (`/webhooks/pretix/<geheim>`). In pretix unter **Organizer → Webhooks** eintragen (Events
  z. B. „Order placed/paid/changed"); ein eingehender Webhook stößt einen inkrementellen Import an (um eine
  Minute verzögert, damit ein Schwung Bestellungen in einem Lauf zusammengefasst wird). Autorisierung über
  das Geheimnis in der URL; unbekannte/inaktive Geheimnisse werden ignoriert. Der 30-Minuten-Auto-Import
  bleibt als Sicherheitsnetz aktiv.
- **Erstattungen**: Abgeschlossene pretix-Erstattungen von Nicht-PayPal-Bestellungen werden als negative
  Transaktionen verbucht (ohne Gebühr), sodass die Abrechnung erstattetes Geld korrekt abzieht.
  PayPal-Erstattungen kommen weiterhin über den PayPal-Sync.
- **Echte MwSt**: Beim Import wird die tatsächliche MwSt der Bestellung übernommen
  (Positions-/Gebühren-Steuerwerte, auch gemischte Sätze). Exporte nutzen sie für alle verknüpften
  Transaktionen; der im Export-Dialog wählbare Satz ist nur noch der **Fallback** für unverknüpfte.

## Vereinsabrechnung pro Event

In der Event-Liste erzeugt **"Abrechnung erstellen"** ein PDF mit allen Zahlungsquellen des Events:
PayPal-Zahlungen und -Erstattungen, Überweisungen/weitere Zahlarten aus pretix (inkl. der
Überweisungsgebühr) und pretix-Erstattungen – je Block Anzahl/Betrag/Gebühren/Nach Gebühren, dazu der
**Auszahlungsbetrag** als eine Zahl und ein Umsatzsteuer-Ausweis (echte MwSt aus pretix, wo verknüpft).
Interne PayPal-Kontobewegungen und als "nicht relevant" markierte Transaktionen sind ausgeschlossen.

## Suche & Filter

Unter **Transaktionen** steht ein Filter "Bestellnummer / Volltextsuche" zur Verfügung: Feld wählbar
(Bestellnummer, Invoice ID, Transaktions-ID, Name, E-Mail, Betreff/Notiz oder alle zusammen), Suchart
(enthält/beginnt mit/endet mit/exakt/Regex), Groß-/Kleinschreibung optional. Alle Filter sind kombinierbar,
über **"Filter speichern"** persistierbar und über einen generierten Link teilbar (`/f/{token}`).

Der Wert des Felds folgt dem pretix-Schema `Order <Event>-<Bestellnummer>` (z. B.
`Order GAG-WISMAR-2026-SC3HR`). Tabelle und Export zeigen das aufgeteilt an: **Bestellnummer** = reine
pretix-Bestellnummer (`SC3HR`), **Event** = Eventkurzform/Verwendungszweck (`GAG-WISMAR-2026`). Der Rohwert
bleibt auf der Detailseite als "Verwendungszweck (roh)" sichtbar.

## Transaktionen als "nicht relevant" markieren

Einzelne (oder per Massenaktion mehrere) Transaktionen lassen sich als **"nicht relevant"** markieren –
z. B. Testbuchungen, interne Umbuchungen oder Ausreißer, die die Auswertung verfälschen. Dazu ist ein
**Grund verpflichtend**.

- Markierte Transaktionen werden aus **Dashboard-Kennzahlen, Berichten und Exporten** ausgeschlossen,
  bleiben aber vollständig erhalten und lassen sich jederzeit wieder als relevant markieren.
- Jede (De-)Markierung wird **revisionssicher** protokolliert: **wer**, **wann**, **warum**. Einsehbar unter
  **System → Audit-Log** (Berechtigung `view-audit-log`).
- Das Markieren erfordert die Berechtigung `manage-transactions`.

> **Transaktionen und Audit-Log-Einträge können niemals gelöscht werden** – weder über die Oberfläche noch
> programmatisch. Das ist bewusst so und darf nicht aufgeweicht werden. "Nicht relevant" markieren ist der
> einzige unterstützte Weg, eine Transaktion aus den Zahlen zu nehmen.

## PDF-Export nutzen

1. Auf der Transaktionsseite die gewünschten Filter setzen.
2. **"Exportieren"** klicken, Format wählen (PDF/CSV/XLSX).
3. Entweder eine gespeicherte **Export-Vorlage** wählen (siehe **Exporte → Export-Vorlagen**) oder ad-hoc
   Spalten (per Drag & Drop sortierbar), Modus (Kunde/Intern), Gruppierung, PII-Maskierung sowie
   Titel/Untertitel/Beschreibung festlegen.
4. **MwSt-Satz** festlegen (Default **19 %**) – die MwSt wird pro Position (Spalte "MwSt") und in den
   Summen ausgewiesen. Der Bruttobetrag gilt als MwSt-inklusive (MwSt = Brutto × Satz/(100 + Satz)); die
   Spalte **"Netto (o. MwSt)"** steht optional zur Verfügung. Der im Dialog gewählte Satz überschreibt einen
   in der Vorlage hinterlegten Standardsatz.
5. Der Export enthält **exakt** das aktuell gefilterte Ergebnis, inkl. Gruppensummen, Gesamtsumme und
   MwSt-Ausweis (Netto o. MwSt / MwSt / Brutto).

Export-Vorlagen können Logo, Event-Infoblock, Fußzeilen-Hinweis und rechtliche Hinweise pro Event
berücksichtigen (siehe **Events & Kunden → Events**). Jeder Export wird unter **Exporte → Exporthistorie**
protokolliert (Ersteller, Format, Zeilenzahl, Ablaufdatum); nach Ablauf ist der Download-Link nicht mehr
verfügbar.

## Berichte & Sync-Gesundheit

Unter **Berichte** (mit optionalem Zeitraumfilter):

- Gebührenanalyse nach Event, nach Monat und im Konten-Vergleich (Umsatz/Gebühr/Nach Gebühren/Gebührenquote)
- Event-Kürzel-Analyse aus der Bestellnummer (z. B. fasst `Order SOMMERFEST-2026-A1B2` … `-C3D4` automatisch zu `SOMMERFEST-2026`
  zusammen, um wiederkehrende Muster sichtbar zu machen)
- Event-Zuordnungsquote und Rückzahlungs-/Reversal-Summe

Auf dem **Dashboard** zeigt der Block "Sync-Gesundheit" jedes aktive Konto mit Status **OK**/**Warnung**
(Warnung, wenn seit mehr als `PAYPAL_SYNC_WARNING_THRESHOLD_HOURS` Stunden – Standard 2h, skaliert mit dem
eigenen Sync-Intervall – kein erfolgreicher Sync lief) sowie einem direkten "Verbindung testen"-Button je Konto.

Ebenfalls auf dem Dashboard erscheint der Block **"Zu prüfen"**, sobald es abzugleichende Transaktionen gibt
(Betrag weicht von pretix ab oder keine pretix-Bestellung gefunden) – eine kurze Inbox mit Direktsprung in
die jeweilige Transaktion. Ist nichts offen, wird der Block ausgeblendet.

## Fehler-Log (500er nachvollziehen)

Jeder Server-Fehler (HTTP 5xx / unbehandelte Exception) wird strukturiert in der Tabelle
`error_log_entries` festgehalten und ist unter **System → Fehler-Log** (nur Admin) einsehbar – mit
Exception-Typ, Nachricht, Datei:Zeile, Route/URL/Methode, User, App-Version, **bereinigtem** Request-Input
(Passwörter/Secrets/Tokens werden vor dem Speichern redigiert), Stacktrace und Vorkommens-Zähler. Gleiche
Fehler werden per Fingerprint gruppiert (ein Eintrag, hochzählender Zähler), bei einem neuen Fehler kommt
eine Glocken-Benachrichtigung. Einträge lassen sich als „erledigt" markieren und löschen; erledigte älter als
30 Tage verschwinden wöchentlich automatisch.

Per CLI (auf dem Server, im `app`-Container):

```bash
php artisan errors:recent            # letzte 20 offene Fehler, gruppiert
php artisan errors:recent --all      # inkl. erledigter
php artisan errors:recent --trace 42 # voller Stacktrace + Kontext für Eintrag #42
```

Der Sammler ist bewusst defensiv (läuft im Laravel-Exception-Handler): Er wirft nie selbst und schreibt
DB-Fehler nicht zurück in die DB, damit das Logging nie den Request killt oder in eine Schleife läuft.

## Kundenportal, Finanzabschluss, E-Mail & mehr

- **Kundenportal (Rolle *Kunde*)**: Ein Kundenbenutzer (mit `customer_id`) sieht **ausschließlich** Daten des
  eigenen Kunden – Transaktionen, Berichte, Monatsabschluss, Dashboard-Zahlen und die eigenen Abrechnungen
  (read-only, PDF-Download). Serverseitig über `App\Support\CustomerScope` erzwungen; ein Kunde ohne
  `customer_id` sieht nichts.
- **Finanzabschluss** (Berichte → Finanzabschluss): **Auszahlungs-Abgleich** (Bilanzbrücke PayPal→Bank:
  Eingang netto, ausgezahlte Beträge, rechnerischer Saldo) und **Monatsabschluss** für den Steuerberater
  (Umsatz/Gebühren/Erstattungen/MwSt pro Monat, CSV-Download).
- **Ticket-Statistik** (pretix → Ticket-Statistik): Kapazität vs. verkauft/verfügbar je Event, live aus den
  pretix-Kontingenten (gecacht, aktualisierbar).
- **Käuferkonflikte** (PayPal → Käuferkonflikte): offene PayPal-Disputes aller Konten mit Antwortfrist;
  der Scheduler (`disputes:check`, alle 6 h) meldet neue Disputes an Admins – Frühwarnung vor Rückbuchungen.
- **E-Mail-Versand** (Einstellungen → E-Mail-Versand): SMTP im Panel konfigurierbar (Passwort verschlüsselt,
  Testmail). Ist er aktiv, kommen System-Warnungen zusätzlich per Mail, und **Abrechnungen lassen sich direkt
  als PDF an den Kunden mailen** (Status „Versendet"). Ohne SMTP bleibt die Glocke der Kanal.
- **Login-Historie** (System → Login-Historie): erfolgreiche/fehlgeschlagene Anmeldungen mit IP und Gerät.
- **Bank-Kontoabgleich** (Bank → Kontoumsätze): Sparkassen-Kontoauszug als **CAMT.053 (XML)** oder **MT940**
  hochladen. TxWatch importiert die Umsätze (dedupliziert) und gleicht Eingänge **automatisch** ab: gegen
  **PayPal-Auszahlungen** (kam die Auszahlung aufs Konto an?) und gegen **pretix-Überweisungen** (Bestellcode
  im Verwendungszweck). Offene Eingänge sind als Badge sichtbar; manuelles Ignorieren/Zurücksetzen und ein
  „Erneut abgleichen" sind möglich. Ein automatischer Bankabruf (GoCardless/FinTS) lässt sich später
  nachrüsten.
- **Export-Vorschau**: Auf der Transaktionsliste zeigt „Vorschau" die ersten Zeilen samt Summe, bevor
  exportiert wird. **Dashboard**: Umsatz-Vergleiche (Vormonat/Vorjahr) und Top-Events.

## Troubleshooting

| Problem | Ursache / Lösung |
|---|---|
| Verbindungstest schlägt mit "Authentifizierung fehlgeschlagen" fehl | Client ID/Secret falsch oder Sandbox/Live vertauscht |
| Verbindungstest meldet fehlende Berechtigung | "Transaction Search" Feature im PayPal-Dashboard für die App aktivieren |
| Sync-Lauf mit Fehler `RESULTSET_TOO_LARGE` in den Sync-Logs | Wird automatisch behandelt (Zeitraum wird intern weiter verkleinert); erscheint nur, wenn selbst die kleinste konfigurierte Stufe (1h) noch zu groß ist – in dem Fall Zeitraum manuell weiter eingrenzen |
| PDF-Export schlägt fehl ("Node.js/Chromium…") | Läuft zuverlässig mit Docker Compose (Chromium/Puppeteer im Image enthalten); lokal ohne Docker `CHROMIUM_PATH`/`NODE_MODULE_PATH` in `.env` auf eine funktionierende Node/Chromium-Installation zeigen lassen. CSV/XLSX funktionieren immer, auch ohne Chromium |
| Transaktionen tauchen doppelt mit leicht unterschiedlichen Daten auf | Kein Bug: PayPal kann dieselbe `transaction_id` mit späteren Aktualisierungen (Status, Updated Date) erneut liefern. PayPal TxWatch legt dafür bewusst eine neue Revision an (Änderungsverlauf), statt sie zu überschreiben – sichtbar über die geteilte `transaction_id` |
| Automatischer Sync läuft nicht | Prüfen, ob `queue:work` und `schedule:work` (bzw. die Docker-Services `queue`/`scheduler`) laufen und das Konto `sync_enabled=true` hat |
| Seite 500t / weißer Schirm | **System → Fehler-Log** ansehen (oder `php artisan errors:recent`) – der genaue Fehler samt Trace, Route und Request steht dort |
| Nach Update zeigt die Seite dauerhaft "Wird aktualisiert…" | `app`/`queue`-Container hängen (z. B. Status `Created` nach unvollständigem Watchtower-Update). Auf dem Server `docker ps -a` prüfen und mit `docker compose up -d` bzw. `docker start` hochziehen |

## Bekannte Grenzen der PayPal API

- Maximal 31 Tage pro Suchzeitraum, maximal `page_size=500`, maximal ca. 10.000 Datensätze pro
  Zeitraum/Request (`RESULTSET_TOO_LARGE` danach) – wird von PayPal TxWatch automatisch durch
  Zeitraum-Splitting behandelt.
- Transaktionen können bis zu ~3 Stunden verzögert erscheinen – daher der konfigurierbare
  Rückblick-Puffer bei jedem automatischen Sync.
- Die "balance-affecting only"-Filterung ist ein API-seitiger Suchparameter zum Abrufzeitpunkt, kein
  gespeichertes Feld; in der aktuellen Version daher nur näherungsweise über Rückzahlungs-/Reversal-Codes
  abbildbar (kein Vollständigkeitsanspruch für exotische Event-Codes).
- `fields=all` liefert nicht für jeden Transaktionstyp alle theoretisch möglichen Unterfelder (z. B.
  Auktions-/Store-Infos) – nicht vorhandene Felder werden als `null` normalisiert.

## Tests

```bash
php artisan test
```

Deckt ab: Zeitraum-Splitting (31-Tage-Grenze, `RESULTSET_TOO_LARGE`-Verkleinerung), PayPal-Datennormalisierung,
idempotenten Upsert/Änderungsverlauf, Event-Zuordnungsregeln, Sync-Fehlerbehandlung sowie Export-Konfiguration
(Spaltenauswahl, Gruppierung/Summen, PII-Maskierung, Kunde-/Intern-Modus).

## Sicherheit

- PayPal-Zugangsdaten werden mit Laravels `encrypted`-Cast (AES-256, App-Key) in der Datenbank gespeichert,
  niemals im Klartext geloggt.
- `.env.example` enthält keine echten Zugangsdaten.
- Rollenmodell: Admin (alles), Manager (Transaktionen/Events/Exporte), Kunde (nur eigene Events/Reports,
  serverseitig über `customer_id` gescoped), Auditor (nur lesend, inkl. Sync-Logs).
- Exporte besitzen ein Ablaufdatum (`export_history.expires_at`, Standard 7 Tage).
- Optionale Zwei-Faktor-Authentifizierung (TOTP, kompatibel mit Google Authenticator/Authy) unter
  **Einstellungen → Zwei-Faktor-Authentifizierung** – jeder Nutzer aktiviert sie für sich selbst. Nach
  Login wird bei aktiviertem 2FA jede Panel-Anfrage bis zur bestätigten Challenge umgeleitet
  (`EnsureTwoFactorChallengeIsPassed`); zusätzlich 10 einmalige Wiederherstellungscodes als Fallback.
  Der Verify-Endpunkt ist auf 6 Versuche/Minute rate-limitiert.
- **2FA-Pflicht für Admins**: Standardmäßig (`TWO_FACTOR_REQUIRED_FOR_ADMINS=true`) wird jeder Admin ohne
  aktiviertes 2FA nach dem Login auf die 2FA-Einrichtungsseite umgeleitet, bis er es aktiviert – ein
  Admin-Konto kann nicht ungeschützt bleiben. Über die Env-Variable abschaltbar.
