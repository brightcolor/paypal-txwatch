# Changelog

Alle nennenswerten Änderungen an PayPal TxWatch werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

## [0.25.5] - 2026-07-11

### Geändert

- **Gebühren überall rot wie in den Berichten**: Die Gebühren-Spalte in der Transaktionsliste und das
  Gebühren-Feld in der Transaktions-Detailansicht zeigen belastete (negative) Gebühren jetzt rot statt grau
  – konsistent zur Berichte-Seite.

## [0.25.4] - 2026-07-11

### Behoben

- **500 in der Transaktionsliste beim Aktivieren von Filtern** (z. B. „Nur Rückzahlungen"): Filament
  injiziert Closure-Argumente **per Parametername** – die Filter-Closures nannten den Query-Parameter `$q`
  statt `$query` und bekamen dadurch einen Builder ohne Model ⇒ `Call to undefined method Builder::refunds()`.
  Alle Filter-Closures korrigiert; neuer Regressions-Test aktiviert jeden Filter einmal (die reine
  Seiten-Smoke konnte das nicht sehen). Gefunden über das Fehler-Log.

### Geändert

- **Alle Tabellen im Panel sehen jetzt aus wie die Berichte-Tabellen**: Zellen und Spaltenköpfe brechen nicht
  mehr mitten im Wert um („1.436,46 €" bleibt eine Zeile, „Nach Gebühren" bleibt einzeilig); auf schmalen
  Bildschirmen scrollt die Tabelle horizontal statt die Spalten zu quetschen. Spalten mit bewusstem Umbruch
  (`->wrap()`) sind ausgenommen.

## [0.25.3] - 2026-07-11

### Behoben

- **Berichte-Tabellen auf dem Handy aufgeräumt.** Geldwerte brachen um (Zahl und „€" auf getrennten Zeilen)
  und Überschriften wie „Nach Gebühren" überlappten, weil die Tabellen auf Handybreite zusammengequetscht
  wurden. Jetzt: eigene, umbruchfreie Spalten mit gleicher Ziffernbreite (tabular), Zebra-Zeilen, negative
  Gebühren rot, und die Tabelle scrollt bei Bedarf horizontal statt zu quetschen. Styling als echtes CSS im
  Theme (die App hat keinen Tailwind-Build, daher keine Utility-Klassen).

## [0.25.2] - 2026-07-11

### Behoben

- **Mobile Navigation: letzter Menüpunkt nicht erreichbar.** Die ausklappbare Sidebar nutzte `100vh` und
  reichte damit hinter die Browser-/Systemleiste des Handys – die untersten Einträge (System-Gruppe, u. a.
  „Fehler-Log") lagen unter der Leiste und waren nicht scrollbar. Sidebar auf `100dvh` begrenzt und den
  Nav-Bereich scrollbar gemacht (mit etwas Innenabstand unten).

## [0.25.1] - 2026-07-11

### Behoben

- **500 auf dem Dashboard**, sobald das „Zu prüfen"-Widget etwas anzuzeigen hatte: Die Badge-/Format-Closures
  benannten den Spaltenwert-Parameter `$s` statt `$state`, den Filament per Namen injiziert → `ViewException:
  closure for [TextColumn], but [$s] was unresolvable`. Die Tests fingen es nicht, weil das Widget bei leerer
  Liste ausgeblendet ist (Test-DB ohne Abweichungen); jetzt mit Regressions-Test, der eine Abweichung seedet
  und das Widget rendert. Zuerst vom neuen Fehler-Log sichtbar gemacht.

## [0.25.0] - 2026-07-11

### Behoben

- **500-Fehler beim Wegklicken einer Benachrichtigung** (Postgres): Die Spalte `notifications.data` war als
  `text` angelegt, Filament fragt sie aber mit dem JSON-Operator `->>` ab → `SQLSTATE 42883: operator does
  not exist: text ->> unknown`. Spalte auf `jsonb` umgestellt (Migration repariert bestehende DBs, frische
  Installs bekommen direkt `json`).

### Neu

- **Fehler-Log** (`System → Fehler-Log`, nur Admin): Jeder Server-Fehler (HTTP 5xx / unbehandelte Exception)
  wird strukturiert in `error_log_entries` festgehalten – mit Exception-Typ, Nachricht, Datei:Zeile, Route,
  URL, Methode, User, App-Version, **bereinigtem** Request-Input (Passwörter/Secrets/Tokens redigiert),
  Stacktrace und Vorkommens-Zähler. Gleicher Fehler wird per Fingerprint gruppiert (zählt hoch statt zu
  fluten). Bei einem **neuen** Fehler werden Admins über die Glocke benachrichtigt. Einträge sind als
  „erledigt" markierbar und löschbar (reine Diagnose, kein Audit); erledigte >30 Tage werden wöchentlich
  automatisch entfernt. Der Fehler-Sammler ist defensiv: er wirft nie selbst und schreibt DB-Fehler nicht in
  die DB (kein Rekursions-/Ausfallrisiko).
- **`php artisan errors:recent`**: Fehler-Log per CLI ansehen (`--all`, `--limit`, `--trace <#>` für Trace +
  Kontext eines Eintrags).

## [0.24.0] - 2026-07-11

### Neu

- **"Zu prüfen"-Inbox auf dem Dashboard**: Ein Widget listet Transaktionen, die der Abgleich markiert hat
  (Betrag weicht von pretix ab oder keine pretix-Bestellung gefunden) mit Direktsprung in die Transaktion.
  Wird ausgeblendet, wenn nichts offen ist.
- **pretix-Webhook (nahezu Echtzeit)**: Jede pretix-Verbindung hat eine eigene, geheime Webhook-URL
  (`/webhooks/pretix/<geheim>`). In pretix eingetragen, stößt ein Order-Event einen inkrementellen Import an
  (um eine Minute verzögert, damit Bestell-Schübe in einem Lauf zusammengefasst werden). Unbekannte/inaktive
  Geheimnisse werden ignoriert; der 30-Minuten-Auto-Import bleibt als Sicherheitsnetz.
- **2FA-Pflicht für Admins**: Admins ohne aktiviertes 2FA werden nach dem Login zur Einrichtung gezwungen
  (`TWO_FACTOR_REQUIRED_FOR_ADMINS`, Standard an) – ein Admin-Konto kann nicht ungeschützt bleiben.
- **Backup-Überwachung**: `backup.sh` schreibt einen Zeitstempel-Marker; der tägliche Befehl `backup:check`
  benachrichtigt Admins, wenn das letzte Backup fehlt oder älter als 36 Stunden ist. Zusätzlich ein
  auskommentierter `rclone`-Block im Skript für Offsite-Kopien.

## [0.23.0] - 2026-07-11

### Neu

- **Fehler-Benachrichtigungen** über das Glocken-Symbol im Panel (kein SMTP nötig, alle 30 s aktualisiert):
  Admins werden bei **fehlgeschlagenem PayPal-Sync**, **fehlgeschlagenem pretix-Import** und bei **neuen
  pretix-Abgleich-Abweichungen** benachrichtigt (jeweils mit Direkt-Link). Abweichungs-Meldungen kommen nur
  bei *neuen* Abweichungen, damit der 30-Minuten-Takt nicht spammt. Jede Meldung landet zusätzlich im Log;
  E-Mail lässt sich nachrüsten, sobald SMTP konfiguriert ist.

## [0.22.0] - 2026-07-11

### Neu

- **Auszahlungs-Tracking**: „Abrechnung erstellen" legt jetzt einen **Abrechnungs-Datensatz** an (eingefrorener
  Snapshot der Summen – bleibt stabil, auch wenn sich Transaktionen später ändern). Neue Ansicht
  **Exporte → Abrechnungen**: PDF herunterladen, **„Als ausgezahlt markieren"** (Datum, Referenz, Notiz),
  wieder öffnen, Status-Filter und Summe der Auszahlungen. Zähler-Badge zeigt offene Abrechnungen.
- **Sammelabrechnung pro Kunde**: Aktion „Sammelabrechnung" am Kunden fasst alle seine Events in einem
  Dokument zusammen – mit **Aufschlüsselung je Veranstaltung** plus Gesamt-Auszahlungsbetrag.
- **Chargebacks/Rückbuchungen** werden jetzt getrennt von freiwilligen Erstattungen behandelt: PayPal-T11xx
  außer dem Merchant-Refund (T1107) gelten als „Rückbuchung/Chargeback" – eigene Art, eigener Block in der
  Abrechnung, sauber in Auszahlungssumme und MwSt berücksichtigt.

## [0.21.0] - 2026-07-11

### Neu

- **Event-Deckseite im Kundenexport**: Betrifft ein Export genau ein Event, beginnt das PDF jetzt mit einer
  eigenen Titelseite – **Event-Bild aus pretix**, Eventname, Kunde, Veranstaltungsdatum, Ort,
  Ansprechpartner, Zahlungszeitraum und Transaktionszahl. Danach folgt wie gehabt die Auswertung.
- Der pretix-Import **reichert die lokalen Events automatisch an**: Datum (`date_from`), Ort (`location`)
  und das in den pretix-Event-Einstellungen hinterlegte **Event-Bild** (wird einmalig heruntergeladen und
  lokal gespeichert). Ein manuell gesetztes Logo/Datum wird nicht mit leeren pretix-Werten überschrieben.

### UI

- **Kompakteres Interface**: durchgängig reduzierte Abstände (Seitenränder, Sektionen, Tabellen-Toolbar und
  -zeilen, Formulare, Widgets, Kacheln) und etwas Feinschliff (Fokus-Ring an Eingabefeldern, kompaktere
  Seitenüberschrift).

## [0.20.0] - 2026-07-11

### Sicherheit

- **SQL-Injection in der Volltextsuche behoben**: Das Suchfeld (`custom_field`, `invoice_id`, …) wurde in
  Roh-SQL interpoliert und stammte aus dem Request – ein eingeloggter Nutzer hätte über einen manipulierten
  Feldwert (Livewire-State ist client-seitig setzbar) beliebiges SQL einschleusen können. Das Feld wird jetzt
  strikt gegen eine Allow-Liste geprüft (Regressionstests inkl. Payload-Nachweis).
- **Regex-Suche abgesichert**: Ein ungültiges Muster wird jetzt ignoriert statt einen DB-Fehler/500 auszulösen.
- **Transaktionsliste zusätzlich per Berechtigung geschützt** (`view-reports`) – schließt einen hypothetischen
  rollenlosen Nutzer aus (Kunden bleiben zusätzlich zeilenweise gescoped).

Der übrige Sicherheits-Check war sauber: alle Admin-Ressourcen (Benutzer, PayPal-Konten, pretix, Audit-Log,
Failed-Jobs) sind korrekt per Berechtigung gated, Secrets liegen verschlüsselt, `APP_DEBUG=false`,
HttpOnly-Cookies, keine weiteren Raw-SQL-Stellen mit User-Input.

### UI

- **Dark-Mode wieder aktiv**: Das AdminLTE-Theme ist jetzt dark-tauglich – helle Flächenfarben sind auf den
  Light-Mode beschränkt (`html:not(.dark)`), sodass Filaments dunkle Palette im Dark-Mode durchscheint.

### Hinweis (offen, betrifft nur den ungenutzten „Kunde"-Zugang)

- Die Berichte-Seite und Export-Historie sind noch nicht kundenweise gescoped. Solange keine
  „customer"-Benutzer angelegt sind, ist das ohne Wirkung; vor Freischaltung eines Kundenzugangs muss das
  ergänzt werden.

## [0.19.0] - 2026-07-11

### Performance / Skalierung

Audit auf Wachstums-Tauglichkeit (was passiert bei 100k+ Transaktionen?) mit acht Befunden, alle behoben:

- **Indexierbarer Umsatz-Filter**: Der Ausschluss interner Kontobewegungen lief über `NOT LIKE`-Ketten
  (nie indexbar). Neue Spalte `is_ledger` (per Saving-Hook automatisch synchron, Bestand per Migration
  befüllt) macht Dashboard, Chart, Berichte und Transaktionsliste index-fähig.
- **Fehlende FK-Indexe ergänzt** (`event_id`, `pretix_order_id`, `instrument_type`) – Postgres legt bei
  Fremdschlüsseln keine Indexe an; diese Spalten werden ständig gefiltert.
- **Berichte aggregieren in SQL** statt alle Transaktionen (inkl. Roh-Payloads!) nach PHP zu laden und dort
  zu gruppieren – vorher O(Tabellengröße) an RAM und Zeit pro Berichtsaufruf.
- **Abgleich ohne RAM-Falle**: Der pretix-Abgleich lud jede Transaktion samt mehrerer KB Roh-Payload in den
  Speicher (~1 GB bei 100k Zeilen); jetzt nur noch die 9 benötigten Spalten. Unveränderte Zeilen erzeugen
  weiterhin kein UPDATE (Dirty-Check).
- **Inkrementeller pretix-Import**: Folgeläufe holen per `modified_since` nur noch seit dem letzten Erfolg
  geänderte Bestellungen (1h Sicherheits-Überlappung) und verbuchen nur diese neu – der 30-Minuten-Takt
  bleibt damit O(Änderungen) statt O(alle Bestellungen), auch API-seitig.
- **Dashboard-Kacheln gecacht** (60 s) statt 8 Aggregat-Queries pro Aufruf.
- **Import-Log gedeckelt** (letzte 300 Zeilen): jedes Log-Update schreibt die ganze JSON-Spalte – ungedeckelt
  wächst das quadratisch mit der Lauflänge.
- **SPA-Modus aktiviert**: Menüwechsel laufen über Livewire-Navigation statt Full-Page-Reloads – der größte
  Hebel für die *gefühlte* Trägheit beim Navigieren.

## [0.18.0] - 2026-07-11

### UI-Überarbeitung im AdminLTE-Stil

- **Dunkle Sidebar** mit blauem Aktiv-Zustand und Uppercase-Gruppenüberschriften, helle Topbar mit Schatten,
  grauer Seitenhintergrund – der klassische Admin-Panel-Look.
- **Dashboard mit "Small Boxes"**: 8 farbige Kennzahl-Kacheln (Umsatz, Transaktionen, Gebühren inkl. Quote,
  Nach Gebühren, Ø Warenkorb, Rückzahlungen, Ohne Event, pretix-Abweichungen) mit Icon-Wasserzeichen und
  **"Mehr Infos →"-Links direkt in die passend vorgefilterte Transaktionsliste**. Warnkacheln färben sich
  automatisch (grün bei 0, gelb/rot bei Handlungsbedarf).
- **Cards/Sektionen** mit blauer Akzent-Oberkante und Schatten; **Tabellen** gestreift, mit Hover-Effekt,
  Sticky-Header, Uppercase-Spaltenköpfen und Tabellenziffern für Beträge.
- **Beträge** rechtsbündig und gefärbt (Einnahmen grün, negative Beträge rot); **Status** jetzt deutsch und
  farbig (bezahlt/ausstehend/abgelehnt/storniert); Badges in kräftigen Vollfarben statt blasser Pillen.
- **Branding**: eigenes Logo in der Sidebar, Favicon, aufgeräumte Login-Karte. Dark-Mode-Umschalter
  deaktiviert (kollidiert mit dem AdminLTE-Design).

## [0.17.1] - 2026-07-11

### Tests / Qualität

- **Gesundheitscheck der Anwendung** (Log, alle Routen, CRUD): Authentifizierter Crawl über alle 39
  Admin-Routen auf Produktion – inkl. View-/Edit-Seiten mit echten Datensätzen – ergab **0 defekte Routen**;
  seit v0.17.0 keine Fehler im Log. Neuer dauerhafter **CRUD-Smoke-Test** deckt jetzt auch die Schreibseite
  ab (Anlegen + Speichern für Kunden, Export-Vorlagen, Events inkl. Deaktivieren-Aktion,
  pretix-Verbindungen inkl. Verschlüsselungs-Roundtrip des API-Tokens, Benutzer inkl. Rollenzuweisung) –
  kaputte Formulare oder Validierungsregeln fallen damit künftig in CI auf statt in Produktion.

## [0.17.0] - 2026-07-11

### Neu

- **Vereinsabrechnung pro Event**: Neue Aktion "Abrechnung erstellen" in der Event-Liste erzeugt ein PDF mit
  allen Zahlungsquellen des Events (PayPal-Zahlungen/-Erstattungen, Überweisungen & weitere Zahlarten aus
  pretix inkl. Überweisungsgebühr, pretix-Erstattungen), dem **Auszahlungsbetrag** als eine Zahl und einem
  Umsatzsteuer-Ausweis. Interne Kontobewegungen und "nicht relevante" Transaktionen sind ausgeschlossen.
- **pretix-Auto-Import**: Der Schalter "Automatischer Import aktiv" tut jetzt, was er verspricht – aktive
  Verbindungen werden **alle 30 Minuten** automatisch importiert und abgeglichen (Scheduler,
  Überlappungsschutz über den Job-Guard).
- **pretix-Erstattungen werden verbucht**: Abgeschlossene ("done") Erstattungen von Nicht-PayPal-Bestellungen
  erscheinen als eigene negative Transaktionen (ohne Gebühr, idempotent). Auch nachträglich stornierte,
  bereits verbuchte Bestellungen werden so sauber ausgeglichen. Die Rückzahlungs-Kennzahlen (Dashboard,
  Bericht, Filter) berücksichtigen sie über eine zentrale Refund-Definition.
- **Echte MwSt aus pretix**: Der Import übernimmt die tatsächliche MwSt jeder Bestellung
  (Positions-/Gebühren-Steuerwerte, gemischte Sätze inklusive). Exporte nutzen sie für alle verknüpften
  Transaktionen – auch PayPal-Zahlungen mit pretix-Verknüpfung; Teil-Erstattungen anteilig. Der wählbare
  MwSt-Satz ist nur noch **Fallback** für unverknüpfte Transaktionen; die MwSt-Spalte heißt daher schlicht
  "MwSt".
- **Backups**: `docker/backup.sh` (nächtlicher pg_dump + storage-Archiv, 14 Tage Rotation) mit
  Cron-Einrichtung und Restore-Anleitung im README.

## [0.16.0] - 2026-07-11

### Neu

- **Events deaktivierbar**: Neue Aktion "Deaktivieren" in der Event-Liste (plus Status-Filter). Deaktivierte
  Events verschwinden aus **allen Auswahllisten** (Event zuweisen, Massenzuweisung, Transaktions-Filter,
  Formular) und erhalten **keine automatischen Zuweisungen** mehr beim pretix-Import – der Import
  reaktiviert sie auch nicht und legt weiterhin alle pretix-Events an (neue als aktiv). Bestehende
  Zuordnungen bleiben erhalten.
- **UI für fehlgeschlagene Jobs** unter **System → Fehlgeschlagene Jobs** (nur Admin, mit Zähler-Badge in
  der Navigation): zeigt Job, Queue, Zeitpunkt und Fehlermeldung (voller Trace per Hover); Aktionen
  "Erneut versuchen" (queue:retry) und "Entfernen" (einzeln/Bulk).

### Performance

Profiling der Transaktionsseite ergab: ~95 % der Zeit steckte im PHP-/Filament-Rendering, nicht in SQL
(67 ms von 1.334 ms). Maßnahmen:

- **OPcache korrekt konfiguriert**: `validate_timestamps=0` (Code ändert sich nur per Deploy),
  `max_accelerated_files=30000` (Filament+vendor überschreiten die Standard-10000, der Cache verdrängte
  sich laufend selbst), mehr OPcache-Speicher.
- **Filaments Produktions-Caches** beim Containerstart: `view:cache`, `icons:cache` (Icon-Discovery scannt
  sonst tausende SVGs pro Request), `filament:cache-components`.
- **Transaktionstabelle**: lädt die schweren JSON-Spalten (`raw_payload`, `item_info`, …) nicht mehr in der
  Liste (25 × mehrere KB pro Seitenaufruf) und nutzt **deferLoading** – die Seite erscheint sofort, die
  Zeilen folgen asynchron.

## [0.15.1] - 2026-07-11

### Geändert

- **"manual"-Zahlungen gelten als Überweisungen**: pretix-Bestellungen mit Zahlungsart `manual` (von Hand
  bestätigte Überweisungseingänge) erhalten jetzt ebenfalls die Überweisungsgebühr (Standard 0,20 €), wie
  `banktransfer`. Kostenlose Bestellungen (0 €) bekommen nie eine Gebühr. Beim nächsten Import werden
  bestehende Buchungen entsprechend aktualisiert (idempotenter Upsert).

## [0.15.0] - 2026-07-11

### Geändert

- **Interne PayPal-Zwischenbuchungen raus aus der Transaktionsliste**: Reserven/Holds (T21xx), deren
  Freigaben und Auszahlungen (T04xx/T20xx) erscheinen nicht mehr in der Transaktionstabelle (und waren
  bereits aus Umsatz/Berichten ausgeschlossen). Die Filter-Optionen "Auszahlung"/"Reserve/Hold" sowie der
  Schnellfilter "Nur echte Umsätze" entfallen entsprechend.
- Stattdessen zeigt die **Detailansicht der zugehörigen Zahlung** eine neue (eingeklappte) Sektion
  **"Interne PayPal-Buchungen zu dieser Zahlung"** mit allen zugehörigen Buchungen (Datum, Art, T-Code,
  Betrag, Transaktions-ID). Die Zuordnung läuft über Bestellnummer, PayPal-Reference-ID (beide Richtungen)
  und die verknüpfte pretix-Bestellung.

## [0.14.1] - 2026-07-11

### Geändert

- **Begriffe entwirrt – Steuer vs. Gebühren**: "Brutto"/"Netto" sind Steuerbegriffe und werden nur noch für
  den MwSt-Ausweis im Export verwendet ("Brutto", "Netto (o. MwSt)", "MwSt"). Für Zahlungsbeträge gilt
  jetzt durchgängig: **"Betrag"** (vom Kunden gezahlt), **"Gebühr"**, **"Nach Gebühren"** – in
  Transaktionstabelle, Detailseite, Dashboard ("Umsatz" statt "Bruttoumsatz"), Umsatz-Chart, Berichten und
  Export-Spalten.

## [0.14.0] - 2026-07-11

### Neu

- **Automatische Event-Zuweisung aus pretix**: Der Import legt für jedes pretix-Event ein lokales Event an
  (Name wird bei jedem Import aus pretix übernommen; `display_name` bleibt als manuelle Überschreibung für
  PDFs unangetastet) und **weist alle Transaktionen anhand des Event-Slugs in der Bestellnummer automatisch
  zu** (`assignment_method: pretix`). Manuelle Zuweisungen werden **nie** überschrieben.
- Die "Event"-Spalte in der Transaktionstabelle zeigt jetzt den **echten pretix-Eventnamen** (Fallback:
  Kürzel aus der Bestellnummer) – **gekürzt auf 25 Zeichen, voller Name beim Hover**. Die formale
  Zuordnungs-Spalte ("Event (zugeordnet)") ist standardmäßig ausgeblendet und einblendbar.
- Damit füllen sich auch Gebührenanalyse nach Event, Event-Zuordnungsquote und der Event-Block im
  PDF-Export automatisch.

## [0.13.0] - 2026-07-11

### Neu

- **Nicht-PayPal-Bestellungen werden als Transaktionen verbucht**: Der pretix-Import legt für bezahlte
  Bestellungen mit anderer Zahlungsart (Überweisung, Boxoffice, …) jetzt eigene Transaktionen an – damit
  deckt die Abrechnung den **gesamten Umsatz** ab, nicht nur PayPal. **Überweisungen erhalten die
  konfigurierte Gebühr** (Standard **0,20 €/Transaktion**) als `Gebühr`, sodass Netto sie widerspiegelt;
  andere Zahlarten bekommen keine Gebühr.
- **PayPal-Bestellungen werden dabei übersprungen** (kommen bereits über den PayPal-Sync – keine
  Doppelzählung); der Schalter "Auch PayPal-Bestellungen importieren" an der Verbindung kann das für
  Verbindungen ohne PayPal-Sync übersteuern.
- Die verbuchten Transaktionen nutzen dasselbe Bestellnummern-Schema (`Order <EVENT>-<CODE>`) wie PayPal –
  Suche, Filter, Event-Zuordnungsregeln, pretix-Verlinkung und Abgleich funktionieren identisch. Zahlungsart
  ist als `banktransfer`/… sichtbar, Art = "Zahlung", Status wird von pretix gespiegelt (bezahlt→S,
  offen→P, storniert→V, abgelaufen→D). Erneuter Import ist idempotent; eine später stornierte Bestellung
  wird im Status gespiegelt (nie gelöscht).
- `paypal_account_id` an Transaktionen ist jetzt nullable (pretix-Buchungen haben kein PayPal-Konto).

## [0.12.5] - 2026-07-11

### Behoben

- **Import-Starts wurden nach einem abgebrochenen Lauf stillschweigend verworfen**: Der Job nutzte Laravels
  `ShouldBeUnique`, dessen unsichtbarer Cache-Lock nach einem gekillten Lauf in Produktion zweimal dazu
  führte, dass neue Dispatches kommentarlos verschwanden (kein Queue-Eintrag, kein failed job, keine
  Logzeile). Der Mechanismus wurde durch einen **expliziten, beobachtbaren Guard** über die
  Import-Lauf-Tabelle ersetzt: Ein zweiter Start wird nur übersprungen (mit Logzeile), solange ein Lauf
  derselben Verbindung *läuft* und jünger als das Job-Timeout ist – ein hängen gebliebener „running"-Eintrag
  blockiert also nach spätestens 30 Minuten nichts mehr (selbstheilend).

## [0.12.4] - 2026-07-11

### Behoben

- **Bestellnummern-Links in der Transaktionstabelle funktionierten nicht zuverlässig**: Die Spalte kombinierte
  `copyable` mit dem Link – der Kopier-Click-Handler lag innerhalb des Links und verschluckte den
  Navigations-Klick; zudem fehlte jede visuelle Kennzeichnung. Jetzt: verknüpfte Bestellnummern zeigen ein
  **"Öffnet in neuem Fenster"-Symbol** (↗), verlinken zuverlässig auf die pretix-Bestellung (neues Fenster)
  – in Tabelle **und** Detailseite. Kopieren des Rohwerts bleibt auf der Detailseite verfügbar. Neuer
  Render-Test prüft das erzeugte HTML auf `<a … target="_blank">`.

## [0.12.3] - 2026-07-11

### Behoben

- **Falsche "Betrag weicht ab"-Meldungen im pretix-Abgleich**: Zu einer Bestellung gehören neben der Zahlung
  oft auch PayPal-interne Ledger-Buchungen mit derselben Bestellnummer – insbesondere ein Guthaben-Hold
  (T2101, negativ) und dessen Freigabe (T2102, **positiv**). Die Freigabe wurde fälschlich zur "gezahlten
  Summe" addiert und verfälschte den Abgleich. Der Abgleich zählt jetzt **nur echte Zahlungen** (Ledger-
  Events ausgeschlossen) und dedupliziert PayPal-Revisionen mit gleicher Transaktions-ID. Ledger-Buchungen
  werden weiterhin mit der Bestellung verknüpft (für den Deep-Link), erhalten aber keinen Abgleich-Status.
- **`/admin/pretix-import-runs` warf HTTP 500**, sobald Import-Läufe vorhanden waren: Die "Aktuell"-Spalte
  nutzte `end()` auf einer Modell-Property (Übergabe per Referenz nicht möglich). Jetzt `Arr::last()`. Der
  Smoke-Test hatte das nicht erfasst, da er nur leere Tabellen prüft – ein Test mit befülltem Log wurde
  ergänzt.

## [0.12.2] - 2026-07-11

### Behoben

- **Kritischer Pagination-Fehler** im pretix-Client: Beim Blättern wurde die absolute `next`-URL mit einem
  (leeren) Query-Array aufgerufen, wodurch Guzzle deren `page`-Parameter entfernte – der Import lief endlos
  auf Seite 1 (im Test bereits „3700 geladen", aber nur 50 in der DB, und die pretix-API wurde in einer
  Schleife bombardiert). Die Folgeseiten werden jetzt ohne Query-Argument über die absolute `next`-URL
  geladen; page_size geht nur bei der ersten Anfrage mit. Zusätzlich ein hartes Seitenlimit (5000) als
  Schutz gegen künftige Endlosschleifen. Regressionstest mit zwei Seiten ergänzt.

## [0.12.1] - 2026-07-11

### Behoben

- pretix-Import brach mit **"ImportPretixOrdersJob has been attempted too many times"** ab. Ursache: Das
  `retry_after` der Redis-Queue (90s) war kleiner als das Job-Timeout (1800s) – die Queue hielt den noch
  laufenden Langläufer für abgebrochen und stellte ihn erneut zu, bis die Versuche erschöpft waren.
  `retry_after` ist jetzt **1920s** (> Job-Timeout). Zusätzlich hält ein abgestürzter Lauf den Unique-Lock
  nicht mehr unbegrenzt (`uniqueFor = 1800`), damit er künftige Importe derselben Verbindung nicht blockiert.

## [0.12.0] - 2026-07-11

### Neu

- **Live-Importlog** für den pretix-Import: Unter **pretix → pretix-Importe** wird jeder Importlauf mit
  Status (**läuft… / fertig / fehlgeschlagen**), Fortschritt (**Events x/y**, Anzahl Bestellungen), der
  **aktuellen Aktion** ("was macht er gerade") sowie den Abgleichzahlen angezeigt. Die Liste aktualisiert
  sich automatisch (Polling), sodass man dem Import live zusehen kann.
- Detailseite je Lauf mit dem **vollständigen zeitgestempelten Verlauf** (jedes Event, geladene Bestellungen,
  Abgleich, ggf. Fehler).

Der Hintergrund-Job schreibt den Fortschritt fortlaufend in die Datenbank (pro Event und pro geladener
Bestellseite), daher ist der Log auch während eines langen Laufs live sichtbar.

## [0.11.1] - 2026-07-11

### Behoben

- Der pretix-Import lief synchron im Web-Request und brach bei vielen Bestellungen mit einer **weißen Seite**
  ab (PHP-FPM-/nginx-Timeout). Er läuft jetzt als **Hintergrund-Job in der Queue** (kein Web-Timeout,
  Job-Timeout 30 min) und verarbeitet **Event für Event** (jede Bestellung wird beim Abruf gespeichert, sodass
  Teil-Fortschritt erhalten bleibt). Die Aktion startet den Import nur noch und meldet „läuft im Hintergrund";
  die Verbindungsliste zeigt den Status (**„läuft…"** / Ergebniszusammenfassung) und aktualisiert sich
  automatisch (Polling). Ein zweiter Start derselben Verbindung wird verhindert, solange ein Import läuft.

## [0.11.0] - 2026-07-11

### Neu

- **pretix-Bestell-Import & Abgleich**: Über **pretix-Verbindungen → "Import & Abgleich"** werden alle
  pretix-Bestellungen geladen und mit den PayPal-Transaktionen verknüpft. **PayPal bleibt führend**; pretix
  dient als Gegenprobe. Pro Transaktion wird ein **Abgleich-Status** gesetzt:
  - **abgeglichen** – pretix-Bestellung gefunden, Summe stimmt plausibel mit dem PayPal-Betrag überein,
  - **Betrag weicht ab** – Bestellung gefunden, aber die Beträge differieren,
  - **nicht in pretix** – Bestellnummer vorhanden, aber keine passende pretix-Bestellung.
  Die Zuordnung erfolgt über Event-Slug + Bestellnummer (aus der Bestellnummer geparst, case-insensitiv).
- **Klickbare Bestellnummern**: In der Transaktionstabelle und auf der Detailseite verlinkt die
  Bestellnummer direkt auf die zugehörige Bestellung im pretix-Control-Panel (sofern zugeordnet).
- Neue Spalte und Filter **"pretix-Abgleich"** in der Transaktionsliste.

Der Import ist idempotent (erneutes Ausführen aktualisiert bestehende Bestellungen, statt zu duplizieren).
Die Zahlungsart der pretix-Bestellung wird erkannt (PayPal/Überweisung/…), was die spätere Verbuchung der
Nicht-PayPal-Zahlungen inkl. Überweisungsgebühr vorbereitet.

## [0.10.0] - 2026-07-10

### Neu

- **pretix-Verbindungen über die Oberfläche einrichten** (analog zu den PayPal-Konten): unter **pretix →
  pretix-Verbindungen** lassen sich Basis-URL, Organizer-Slug und ein **verschlüsselt gespeicherter
  API-Token** hinterlegen, inkl. Aktion **"Verbindung testen"** (prüft URL/Token/Organizer und zeigt die
  Anzahl der Events). Berechtigung: `manage-pretix-connections` (Admin).
- Pro Verbindung konfigurierbar: **Überweisungsgebühr in Cent/Transaktion** (Default 20) und ob
  PayPal-Bestellungen mitimportiert werden sollen (Default aus – vermeidet Doppelzählung mit dem
  PayPal-Sync).

Dies ist der erste Baustein der pretix-Anbindung (Einrichtung/Verbindungstest). Der eigentliche
Bestell-Import mit Event-Matching per Slug und Verbuchung der Überweisungsgebühr folgt als nächstes.

## [0.9.0] - 2026-07-10

### Neu

- Neue Spalte **"Art"** in der Transaktionstabelle (Zahlung / Rückzahlung / Auszahlung / Reserve/Hold / …),
  abgeleitet aus der PayPal-T-Code-Gruppe. Damit ist sofort erkennbar, dass z. B. eine große negative
  Buchung eine **Auszahlung** (T04xx) oder **Reserve/Hold** (T21xx) ist – und **keine Erstattung**. Dazu ein
  neuer **"Art"-Filter** und ein Schnellfilter **"Nur echte Umsätze (ohne Auszahlungen/Reserven)"**.

### Behoben

- Die Erkennung von Nicht-Umsatz-Buchungen (Auszahlungen/Reserven) erfolgt jetzt über die **T-Code-Gruppen**
  (T04xx, T20xx, T21xx) statt einer handgepflegten Einzelcode-Liste. Dadurch werden auch bisher übersehene
  Codes wie **T2107** (eine Reserve) korrekt aus Dashboard, Berichten und Exporten ausgeschlossen.
- Der **Umsatz-nach-Tag-Chart** summierte fälschlich auch Auszahlungen/Reserven mit – jetzt werden diese
  (und als "nicht relevant" markierte) Transaktionen ausgeschlossen.

### Geändert

- **Kompakteres UI**: deutlich reduzierte Abstände/Weißraum (Seitenränder, Sektionen, Tabellenzeilen,
  Widgets) über kompakte Style-Overrides.

## [0.8.1] - 2026-07-10

### Geändert / Performance

- Das Panel nutzt jetzt die **volle Viewport-Breite** (`maxContentWidth: Full`) statt der schmalen
  zentrierten Standardspalte – die breite Transaktionstabelle hat damit deutlich mehr Platz.
- **N+1 in der Transaktionstabelle behoben**: `event`, `paypalAccount` und `irrelevantMarkedBy` werden jetzt
  eager-geladen (vorher pro Zeile einzeln nachgeladen → dutzende Extra-Queries je Seitenaufruf).
- Die `DISTINCT`-Optionslisten der Tabellen-Filter (Währung, Zahlungsart, Land, Status, T-Code) werden 10 min
  gecacht, statt bei jedem Render fünf DISTINCT-Scans auszuführen.

## [0.8.0] - 2026-07-10

### Geändert

- Das Feld **"Custom Field" heißt jetzt "Bestellnummer"** – in der Suchfeld-Auswahl, im Filter, in der
  Transaktionstabelle, auf der Detailseite und im Export.
- Der Wert des `custom_field` (pretix-Schema `Order <Event>-<Bestellnummer>`, z. B.
  `Order GAG-WISMAR-2026-SC3HR`) wird jetzt aufgeteilt dargestellt:
  - **Bestellnummer** zeigt nur noch die reine pretix-Bestellnummer (letztes Segment, z. B. `SC3HR`) –
    ohne "Order" und ohne Verwendungszweck.
  - Neue Spalte **"Event"** zeigt die Eventkurzform / den Verwendungszweck (der Teil zwischen "Order" und der
    Bestellnummer, z. B. `GAG-WISMAR-2026`).
- Beides gilt für Tabelle **und** Export (PDF/CSV/XLSX). Die neuen Spalten "Event" und "Bestellnummer" sind
  in der Standard-Spaltenauswahl des Exports enthalten. Der Rohwert bleibt auf der Transaktions-Detailseite
  als "Verwendungszweck (roh)" vollständig sichtbar.
- Die zuvor "Event" genannte Spalte (zugeordnetes Event aus der Event-Verwaltung) heißt zur Abgrenzung jetzt
  **"Event (zugeordnet)"** und ist weiterhin als Export-Spalte wählbar.
- Die Parsing-Logik ist in `App\Services\CustomFieldParser` zentralisiert (auch von der
  Event-Kürzel-Analyse auf der Berichte-Seite genutzt).

## [0.7.1] - 2026-07-10

### Behoben

- Aufruf eines geteilten Filter-Links (`/f/{token}`) durch nicht eingeloggte Besucher warf HTTP 500
  ("Route [login] not defined"). Die Anwendung hat keine generische `login`-Route – die Anmeldung läuft
  vollständig über das Filament-Panel. Nicht authentifizierte Besucher werden jetzt korrekt zur
  Filament-Anmeldung geleitet (und nach dem Login dank gespeicherter Ziel-URL zurück zum Filter-Link); der
  eingeloggte Pfad war bereits korrekt (der Session-Key des Controllers stimmt exakt mit Filaments
  `getTableFiltersSessionKey()` überein).

### Tests

- Neuer Smoke-Test, der als Admin **alle** Ressourcen-Seiten (Index/Create), das Dashboard sowie die
  Custom-Pages (Berichte, CSV-Import, 2FA-Einstellungen) aufruft und sicherstellt, dass keine davon einen
  500 rendert – als Absicherung gegen die wiederkehrende 500er-Klasse.

## [0.7.0] - 2026-07-10

### Neu

- **MwSt-Ausweis im Export**: Exporte weisen jetzt die MwSt **pro Position** (neue Spalte "MwSt", standardmäßig
  enthalten) und **gesamt** (in Gruppensummen und Gesamtsumme) aus. Zusätzlich verfügbar ist die Spalte
  "Netto (o. MwSt)". Der Bruttobetrag gilt als MwSt-inklusive (deutscher B2C-Fall), d. h. MwSt =
  Brutto × Satz/(100 + Satz).
- Der **MwSt-Satz ist beim Export frei definierbar** (Feld im Export-Dialog, Default **19 %**) und
  überschreibt einen ggf. in der Export-Vorlage hinterlegten Satz. Export-Vorlagen können einen eigenen
  Standardsatz speichern (`vat_rate`, Default 19 %).
- PDF-Gesamtsumme und Summenzeilen zeigen jetzt zusätzlich **Netto (o. MwSt)** und **MwSt (Satz %)**;
  CSV/XLSX enthalten am Ende explizite, spaltenunabhängige Zeilen für MwSt-Satz, Netto gesamt, MwSt gesamt
  und Brutto gesamt.

Die MwSt-Summen werden aus den je Transaktion gerundeten Beträgen gebildet, sodass die Summenzeile exakt der
Summe der Positionswerte entspricht; das Netto (o. MwSt) ergibt sich aus Brutto − MwSt, wodurch
Brutto = Netto (o. MwSt) + MwSt auch in der Gesamtsumme exakt aufgeht.

## [0.6.0] - 2026-07-10

### Neu

- Transaktionen können jetzt als **"nicht relevant"** markiert werden (Einzelaktion in der Transaktionstabelle,
  auf der Detailseite und als Massenaktion). Eine markierte Transaktion wird aus Dashboard-Kennzahlen,
  Berichten und kundenseitigen Exporten (PDF/CSV/XLSX) ausgeschlossen, bleibt aber vollständig erhalten und
  kann jederzeit wieder als relevant markiert werden. Beim Markieren ist ein **Grund verpflichtend**.
- Jede (De-)Markierung wird **revisionssicher im Audit-Log** festgehalten: **wer**, **wann**, **warum** und
  welche Transaktion. Das Audit-Log ist unter **System → Audit-Log** einsehbar (Berechtigung
  `view-audit-log`, standardmäßig für Admin und Auditor).
- Neue Berechtigungslogik: Markieren erfordert `manage-transactions`.

### Sicherheit / Datenintegrität

- Transaktionen und Audit-Log-Einträge können **niemals gelöscht werden** - weder über die Oberfläche noch
  programmatisch. `Transaction::delete()`/`forceDelete()` und `AuditLogEntry::delete()`/`forceDelete()`
  werfen eine Ausnahme; das Löschen von Transaktionen ist in Filament nicht verfügbar. Das Audit-Log nutzt
  ein eigenes, append-only Modell (`App\Models\AuditLogEntry`), sodass selbst Spaties
  `activitylog:clean`-Kommando keine Einträge entfernen kann (es wird zudem nicht geplant/geschedult).

### Behoben

- `/admin/saved-filters/create` warf beim Absenden einen HTTP 500 ("null value in column 'filters' violates
  not-null constraint"). Die generische Filament-Erstellseite konnte weder den aktuellen Filterzustand noch
  `user_id` erfassen und erzeugte dadurch ungültige Datensätze. Gespeicherte Filter werden ausschließlich
  über die Aktion **"Filter speichern"** in der Transaktionstabelle erzeugt (dort werden Filterzustand und
  Benutzer korrekt gesetzt); die überflüssige Erstellseite wurde entfernt.

## [0.5.10] - 2026-07-10

### Behoben

- PDF-Export schlug bei echten Klicks in der Oberfläche weiterhin mit "chrome_crashpad_handler: --database is
  required" fehl, obwohl derselbe Code über die Kommandozeile im selben Container fehlerfrei lief. Ursache:
  PHP-FPMs Standardverhalten `clear_env=yes` entfernt Umgebungsvariablen für Worker-Prozesse, die ein
  `docker compose exec`-Shell-Aufruf dagegen normal erbt – dadurch kam das per Dockerfile gesetzte `HOME=/tmp`
  (das Chromium für sein beschreibbares Profil-/Crash-Datenbank-Verzeichnis braucht) nie bei echten,
  über PHP-FPM bedienten Web-Requests an, sondern nur bei manuellen Testaufrufen. Durch gezieltes Entfernen von
  `HOME` beim CLI-Testaufruf ließ sich der exakte Fehler reproduzieren und durch erneutes Setzen bestätigt
  beheben. Ein PHP-FPM-Pool-Override (`env[HOME] = /tmp`) sorgt jetzt dafür, dass auch echte Worker-Prozesse
  diesen Wert erhalten. DB-/Redis-Konfiguration war davon nie betroffen, da diese bereits beim Deploy per
  `config:cache` einmalig aufgelöst und danach aus einer kompilierten Datei gelesen wird – nur zur Laufzeit
  per Subprozess (Node/Chromium) ausgelesene Variablen wie `HOME` waren betroffen.
- PHP-Memory-Limit im Container war mit den Docker-Image-Standardwerten (128M) knapp bemessen für ein
  Filament-Adminpanel mit wachsender Transaktionsanzahl (aktuell 800+, inkl. teils großer JSON-Rohdaten pro
  Zeile) und führte zu einem "Allowed memory size exhausted"-Fehler beim Aufruf von `/admin/transactions`.
  Konnte nicht deterministisch isoliert reproduziert werden (ein synthetischer Testaufruf blieb bei nur
  ~48 MB), das Limit wurde defensiv auf 256M angehoben, um mehr Sicherheitsspielraum zu haben.

## [0.5.9] - 2026-07-10

### Behoben

- Gebührenquote in Dashboard und Berichten war stark verfälscht (z. B. 27 % statt der tatsächlichen ~3,6 %
  auf echte Zahlungen). Ursache: Bank-Auszahlungen (T0400/T0401/T0403) und Guthaben-Reserven/-Freigaben
  (T2101/T2102/T2108) sind reine PayPal-Kontobuchungen ohne eigene Gebühr, aber teils mit hohem Betrag –
  ihre Summierung in den Bruttoumsatz verzerrte den Nenner der Gebührenquote erheblich, ohne dass eine
  echte Verkaufstransaktion dahintersteht. Anhand der offiziellen PayPal-T-Code-Referenz
  (developer.paypal.com/docs/transaction-search/transaction-event-codes/) wurde verifiziert: T0400/T0401/
  T0403 sind Auszahlungen, T2101 ist ein allgemeines Halten von Guthaben, T2102/T2108 sind Freigaben davon –
  keines davon ist ein Verkauf oder eine Rückzahlung. Diese Codes werden jetzt über
  `Transaction::LEDGER_ONLY_EVENT_CODES` konsequent aus Dashboard-Kennzahlen und Berichten ausgeschlossen.
- Korrigiert außerdem einen Folgefehler aus v0.5.7/v0.5.8: T0400/T0403/T2101 wurden dort fälschlich als
  "Rückzahlungscodes" eingestuft, weil sie (wie oben beschrieben) rein zufällig zu 100 % negative Beträge
  hatten – tatsächlich sind es Auszahlungen bzw. eine Guthabenreserve, keine Rückzahlungen. Die Erkennung von
  Rückzahlungen verlässt sich jetzt ausschließlich auf den dokumentierten PayPal-Code `T1107`
  ("Merchant-Initiated Refund"), nicht mehr zusätzlich auf das Vorzeichen des Betrags – Letzteres hatte sich
  bereits zweimal als unzuverlässig erwiesen (siehe oben).

## [0.5.8] - 2026-07-10

### Behoben

- Die Custom-Field-Präfix-Analyse (Berichte-Seite) gruppierte reale Bestell-Custom-Fields nicht sinnvoll,
  da die alte Heuristik nur einen trailing Lauf aus Ziffern/Trennzeichen abschnitt. Echte Werte folgen dem
  Schema `Order <Präfix>-<Bestell-ID>` (z. B. `Order GAG-WISMAR-2026-SC3HR`), wobei die Bestell-ID
  alphanumerisch ist (nicht rein numerisch, z. B. `SC3HR`). `ReportService::extractPrefix()` entfernt jetzt
  das führende `Order`-Label und das letzte Bindestrich-Segment gezielt, sodass z. B. `Order
  GAG-WISMAR-2026-SC3HR` korrekt zu `GAG-WISMAR-2026` gruppiert wird.
- `Transaction::REFUND_EVENT_CODES` um `T0400`/`T1107` hinaus ergänzt: Anhand von 813 echten
  Produktions-Transaktionen wurde verifiziert, dass auch `T0403` (1/1) und `T2101` (48/48) zu 100 % mit
  negativem Bruttobetrag korrelieren, während `T2102`/`T2108` zu 0 % korrelieren und daher weiterhin
  ausgeschlossen bleiben. Ändert das aktuelle Dashboard-/Berichts-Ergebnis nicht (die Fälle sind bereits über
  die Negativbetrag-Prüfung erfasst), macht aber `Transaction::isRefundOrReversal()` korrekt für Aufrufer,
  die sich allein auf den Event-Code verlassen.

## [0.5.7] - 2026-07-10

### Behoben

- Dashboard und Berichte zeigten fast jede Transaktion als "Rückzahlung/Reversal" an (z. B. 447 von 447
  Transaktionen). Ursache: Der Event-Code `T0006` wurde fälschlich als Rückzahlungsindikator behandelt –
  tatsächlich ist er PayPals generischer Code für eine normale Zahlung und deckte in echten Kontodaten
  ~99 % aller gewöhnlichen Transaktionen ab. Anhand echter Produktionsdaten wurde empirisch verifiziert,
  dass ausschließlich `T0400` und `T1107` mit tatsächlich negativen Bruttobeträgen korrelieren. Die Codes
  sind jetzt an einer Stelle als `Transaction::REFUND_EVENT_CODES` gepflegt (statt an vier Stellen dupliziert)
  und werden von Dashboard, Berichten, Transaktionsfilter und Modell einheitlich verwendet.
- PDF-Export schlug mit HTTP 500 fehl ("Failed to launch the browser process! chrome_crashpad_handler:
  --database is required"). Das im Container installierte Distributions-Chromium versucht beim Start seinen
  Crash-Reporter zu initialisieren, für den kein beschreibbarer Datenbankpfad bereitgestellt ist. Behoben
  durch `--disable-crash-reporter` (zusammen mit `--disable-dev-shm-usage` gegen bekannte Container-Probleme
  mit begrenztem `/dev/shm`) als zusätzliche Chromium-Startparameter in `PdfRenderer`.
- `storage:link` schlug beim Containerstart mit "Permission denied" fehl (harmlos, da bereits durch
  `|| true` abgefangen, aber unnötiges Rauschen im Log) – `public/` gehörte im Image weiterhin `root`, obwohl
  der Container als `www-data` läuft. Jetzt wird `public/` beim Image-Build ebenfalls auf `www-data`
  übertragen.

## [0.5.6] - 2026-07-10

### Behoben

- Transaktionsdetailseite warf HTTP 500 ("Array to string conversion") für Transaktionen mit
  Warenkorb-/Item-Details im Raw-JSON (z. B. Ticket-Bestellungen mit mehreren Positionen). Ursache: Der
  Raw-JSON-Infolist-Eintrag nutzte `formatStateUsing()`, das nur die Anzeige transformiert – Filament liest
  aber zusätzlich den unformatierten `getState()`-Rohwert (bei `raw_payload` ein verschachteltes Array durch
  den `array`-Cast), um zu entscheiden, ob der Wert als Liste dargestellt werden soll, und stürzt dabei bei
  verschachtelten Arrays ab. Jetzt `->state()` statt `->formatStateUsing()`, wodurch der Rohwert nie als
  Array durchscheint.

## [0.5.5] - 2026-07-10

### Behoben

- "Verbindung testen" fragte die letzte Stunde ab und erhielt dafür von PayPal die Fehlermeldung "Data for
  the given start date is not available" – unabhängig von der bekannten ~3h-Verzögerung bei echten
  Transaktionsdaten lehnt PayPal offenbar zu frische Suchfenster grundsätzlich ab. Der Testzeitraum liegt
  jetzt einen Tag in der Vergangenheit (der Test prüft ohnehin nur, ob PayPal antwortet, nicht ob Daten
  vorhanden sind).

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
