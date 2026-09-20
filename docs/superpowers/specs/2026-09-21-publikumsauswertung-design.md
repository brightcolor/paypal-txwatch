# Publikumsauswertung — Entwurf

Stand: 21.09.2026 · Status: abgestimmt, bereit für die Planung

## 1. Ziel

Wir wollen wissen, welche Ticketkäufer sich für welche Veranstaltungen interessieren, damit
Angebot und Auswahl der Events darauf aufbauen können. Konkret beantwortet die Auswertung:

- Welche Personen haben bei mehreren ausgewählten Veranstaltungen gekauft?
- Welche Veranstaltungen teilen sich ein Publikum?
- Wer kauft wie oft, wie viele Tickets und welche Ticketarten?
- Welche weiteren Muster stecken im Bestand (Vorlaufzeit, Gruppengröße, Verkaufsverlauf,
  Bestellwert, Gutscheine, Zahlungsart, Herkunft)?

## 2. Befund aus dem Bestand

Gemessen am Produktivbestand am 20.09.2026, lesend über ein Prüfskript im App-Container.

| Kennzahl | Wert |
|---|---|
| Bestellungen / aktive Ticketpositionen | 1458 / 3359 |
| Zeitraum der Bestellungen | 30.03.2026 bis 20.09.2026 |
| Verschiedene Käufer-E-Mails | 1288, davon 1259 mit bezahlter Bestellung |
| Käufer mit mehr als einer Bestellung | 140 |
| Käufer mit mehr als einer Veranstaltung | 48 |
| Meiste Bestellungen einer Person | 4 |
| Veranstaltungen mit Bestellungen | `gag-wismar-2026` (654), `fcaspiel` (556), `ac-friends-2026` (229), `gag-wismar-2027` (19) |
| Bestellstatus | bezahlt 1409, abgelaufen 33, storniert 10, offen 6 |
| Zahlungsart | PayPal 1120, manuell 165, Überweisung 101, kostenlos 72 |

Belegung der Felder, die als Dimension in Frage kommen:

| Feld | Belegung |
|---|---|
| Ticketart, Preis, Bestellzeitpunkt, Status, Zahlungsart | 100 % |
| `attendee_name` an der Position | 75 % der Bestellungen |
| `attendee_email` an der Position | 32 % der Bestellungen |
| Rechnungsadresse (Name, Straße, PLZ, Ort) | 46 % der Bestellungen |
| Gutschein an der Position | 281 von 3359 Positionen |
| pretix-Kundenkonto an der Bestellung | 147 von 1458 |

Gleichförmig und damit als Dimension wertlos: `sales_channel` ist durchgehend `web`, `locale`
durchgehend `de`. Varianten, Subevents und Zusatzprodukte kommen im Bestand nicht vor. Es gibt
genau eine Positionsfrage (`788Q9KVV`, Ja/Nein, 406 Antworten) — Fragen werden deshalb
generisch behandelt, damit künftige Fragen ohne Codeänderung mitlaufen.

Zwei Randbedingungen, die der Entwurf berücksichtigt:

1. Die Überschneidung steht heute bei 48 Personen. Die Auswertung wird mit jeder weiteren
   Veranstaltung tragfähiger; `gag-wismar-2027` beginnt gerade zu verkaufen.
2. Es gibt derzeit 0 Veranstalter-Datensätze und 0 Portalnutzer; alle sechs Events tragen
   `customer_id = NULL`. Die Mandantensperre wird trotzdem von Anfang an eingebaut.

## 3. Begriffe

In TxWatch heißt `Customer` der **Veranstalter** (Mandant, Rolle `customer`, `CustomerScope`).
Diese Auswertung betrachtet den **Ticketkäufer**. Um die beiden auseinanderzuhalten, gelten
verbindlich:

| Begriff in der Oberfläche | Bedeutung | Code |
|---|---|---|
| Veranstalter | Mandant, Inhaber von Events | `Customer`, `customer_id` |
| Käufer | Person hinter einer pretix-Bestellung | `buyer_email`, `AudienceBuyers` |
| Publikum | Menge der Käufer einer Eventauswahl | `Audience*` |

Das Wort „Kunde" bleibt in der neuen Oberfläche dem Veranstalter vorbehalten.

**Identität eines Käufers** ist die kleingeschriebene, getrimmte E-Mail-Adresse der Bestellung.
Namen und Adressen dienen der Anzeige.

## 4. Datenmodell

Neue Tabelle `pretix_positions`, eine Zeile je **aktiver** Ticketposition. Positionen mit
`canceled: true` bleiben draußen, wie im `ParticipantExporter`.

| Spalte | Typ | Herkunft |
|---|---|---|
| `id` | bigint, PK | |
| `pretix_connection_id` | FK auf `pretix_connections` | Bestellung |
| `pretix_order_id` | FK auf `pretix_orders`, cascade | Bestellung |
| `event_slug` | string(255), indiziert | Bestellung |
| `order_code` | string(64) | Bestellung |
| `order_status` | string(8) | `pretix_orders.status` |
| `payment_provider` | string(64), nullable | Bestellung |
| `buyer_email` | string(255), kleingeschrieben, indiziert | `pretix_orders.email` |
| `buyer_name` | string(255), nullable | `invoice_address.name` |
| `position_id` | bigint | `position.id` |
| `item_id` | bigint, indiziert | `position.item` |
| `price` | decimal(10,2) | `position.price` |
| `voucher` | string(255), nullable | `position.voucher` |
| `attendee_name` | string(255), nullable | `position.attendee_name` |
| `zipcode` | string(32), nullable | `invoice_address.zipcode`, ersatzweise Position |
| `city` | string(128), nullable | `invoice_address.city`, ersatzweise Position |
| `country` | string(8), nullable | `invoice_address.country`, ersatzweise Position |
| `ordered_at` | timestamp, indiziert | `pretix_orders.order_datetime` |
| `created_at` / `updated_at` | timestamps | |

Eindeutig: `(pretix_connection_id, event_slug, order_code, position_id)`.
Weitere Indizes: `(event_slug, buyer_email)`, `(buyer_email)`, `(ordered_at)`, `(item_id)`.

**Spaltenbreiten großzügig wählen.** SQLite verschweigt zu enge `varchar`-Längen, PostgreSQL
weist den Insert ab — dieselbe Klasse wie der `source_format`-Vorfall vom 17.08.2026. Der
bestehende Schemawächter `SourceFormatFitsColumnTest` ist das Vorbild für die Denkweise.

Model `App\Models\PretixPosition`: abgeleitete Daten, kein Audit-Log, löschbar. Die harte
Invariante „niemals löschbar" gilt weiter für `Transaction` und `AuditLogEntry`.

**Kein eigener Käufer-Datensatz.** Der Käufer ist die Gruppierung über `buyer_email`. Damit
bleibt pretix die einzige Quelle der Personendaten, und es entsteht keine zweite Identität,
die nachgezogen werden müsste.

## 5. Befüllung

`PretixOrderImporter` schreibt die Positionen einer Bestellung im selben Schreibvorgang mit:
erst alle Zeilen dieser Bestellung löschen, dann die aktiven Positionen neu schreiben, beides
in einer Transaktion. Eine Position, die in pretix storniert wurde, verschwindet damit beim
nächsten Import.

Dazu ein Befehl für den Erstaufbau und für Reparaturen:

```
php artisan pretix:rebuild-positions [--event=<slug>]
```

Er liest die gespeicherten Bestellungen und baut die Tabelle daraus neu auf. Er ist
wiederholbar und liefert am Ende eine Zusammenfassung (Bestellungen gelesen, Zeilen
geschrieben, Bestellungen ohne Positionen).

Der Erstaufbau auf Produktion erfasst die 1458 Bestellungen mit 3359 aktiven Positionen.

## 6. Auswertungsdienst

`app/Services/Audience/`, eine Klasse je Frage, jede für sich prüfbar:

| Klasse | Aufgabe |
|---|---|
| `AudienceQuery` | Die Auswahl (Events, Zeitraum, Status, Ticketarten) als Objekt; baut den Basis-Query und wendet die Mandantensperre an |
| `AudienceStats` | Kopfzahlen: Käufer, Tickets, Umsatz, Anteil wiederkehrend |
| `AudienceOverlap` | Überschneidungsmatrix je Eventpaar |
| `AudienceBuyers` | Die Käuferliste als Query-Builder für die Tabelle |
| `AudienceDimensions` | Vorlaufzeit, Gruppengröße, Verkaufsverlauf, Bestellwert, Gutschein, Zahlungsart, Herkunft, Stornoquote |

`AudienceQuery` ist der Engpass, durch den jede Auswertung muss. Dort sitzt die Mandantensperre
(Abschnitt 9), damit ein später ergänztes Widget sie nicht umgehen kann.

Jede Klasse gibt einfache Arrays zurück. Die Oberfläche rechnet nichts nach.

## 7. Analysedimensionen im Einzelnen

Alle Kennzahlen beziehen sich auf die aktuelle Auswahl (Events, Zeitraum, Status, Ticketarten).
Vorgabe des Statusfilters ist `p` (bezahlt).

**Die Zahlen dieser Seite beschreiben das Publikum.** Die Buchhaltung rechnet weiterhin über
Transaktionen, Berichte und Abrechnungen; `price` ist der Ticketpreis aus pretix.

### 7.1 Kopfzahlen

| Kennzahl | Definition |
|---|---|
| Käufer | Anzahl verschiedener `buyer_email` mit mindestens einer Zeile in der Auswahl |
| Tickets | Anzahl Zeilen in der Auswahl |
| Umsatz | Summe `price` der Zeilen in der Auswahl |
| Bestellungen | Anzahl verschiedener `order_code` in der Auswahl |
| Käufer mit mehreren Veranstaltungen | Käufer mit Zeilen zu mindestens zwei verschiedenen `event_slug` **innerhalb der Auswahl** |
| Anteil wiederkehrend | Vorige Zahl geteilt durch Käufer, in Prozent |

### 7.2 Überschneidung

Für jedes geordnete Paar (A, B) aus der Auswahl: Anzahl Käufer mit mindestens einer Zeile in A
und mindestens einer in B. Die Prozentangabe bezieht sich auf die Käuferzahl von **A**, ist also
asymmetrisch — das ist die nützliche Lesart: „Von den 654 Käufern von A waren 48 auch bei B,
das sind 7,3 %."

Darstellung als Matrix Event × Event, Diagonale trägt die Käuferzahl des Events selbst.

### 7.3 Neu gegen wiederkehrend je Event

Ein Käufer gilt bei einem Event als **Erstkäufer**, wenn seine früheste Bestellung im gesamten
sichtbaren Bestand zu diesem Event gehört. Sonst ist er **wiederkehrend**. Bezugsraum ist der
sichtbare Bestand, damit die Zahl unabhängig von der gerade gewählten Eventliste bleibt.

Je Event: Erstkäufer, wiederkehrende Käufer, Anteil in Prozent.

### 7.4 Käuferliste

Eine Zeile je `buyer_email`, mit:

| Spalte | Definition |
|---|---|
| E-Mail | `buyer_email` |
| Name | Häufigster gefüllter `buyer_name` dieser Adresse; bei Gleichstand der zuletzt bestellte |
| Veranstaltungen | Anzahl verschiedener `event_slug`, dazu die Namen als Text |
| Bestellungen | Anzahl verschiedener `order_code` |
| Tickets | Anzahl Zeilen |
| Umsatz | Summe `price` |
| Ticketarten | Namen der gekauften Ticketarten |
| Erste Bestellung / Letzte Bestellung | Minimum und Maximum von `ordered_at` |

Sortierbar über jede Spalte, durchsuchbar über E-Mail und Name, exportierbar als CSV und XLSX.

### 7.5 Ticketartenvorliebe

Anzahl Positionen und Umsatz je `item_id`, mit dem Namen aus `PretixItem`, wahlweise für die
Auswahl gesamt oder je Event.

Dazu: Anteil der Käufer, die über mehrere Events hinweg dieselbe Ticketart wählen. **Der
Vergleich läuft über den Namen der Ticketart, nicht über `item_id`** — jede Veranstaltung hat
eigene Ticketart-Nummern, dieselbe „VIP" trägt bei zwei Events zwei verschiedene Nummern.
Verglichen wird der getrimmte, kleingeschriebene Name aus `PretixItem`. Die Kennzahl erscheint
nur, wenn mindestens zwei Events der Auswahl eine gleichnamige Ticketart führen.

### 7.6 Vorlaufzeit

Tage zwischen `ordered_at` und `Event.event_date`, gruppiert in Klassen
(am Tag selbst, 1–3, 4–7, 8–14, 15–30, 31–90, über 90 Tage). Gilt nur für Events mit gepflegtem
`event_date`; die Abdeckung wird ausgewiesen.

### 7.7 Gruppengröße

Anzahl aktiver Positionen je Bestellung, als Verteilung (1, 2, 3, 4, 5–9, ab 10) und als
Mittelwert je Event.

Dazu der **Anteil der Bestellungen, bei denen ein Name auf dem Ticket vom Käufernamen
abweicht** — das ist das einzige Signal im Bestand dafür, dass jemand Begleitung mitbringt.
Verglichen werden getrimmte, kleingeschriebene Werte von `attendee_name` und `buyer_name`;
Bestellungen ohne einen der beiden Werte bleiben aus der Quote heraus, ihre Zahl wird
ausgewiesen.

### 7.8 Verkaufsverlauf

Bestellungen und Tickets je Tag. Zwei Achsen wählbar: absolutes Datum, oder Tage vor dem
Veranstaltungstag — letzteres macht mehrere Events vergleichbar und setzt wie 7.6 ein
gepflegtes `event_date` voraus.

Dazu die Verteilung nach Wochentag und nach Tageszeit in Vierstundenblöcken.

### 7.9 Bestellwert

Summe `price` je Bestellung, als Verteilung in Klassen sowie Median und Mittelwert je Event.
Die Verteilung steht vor dem Mittelwert, damit einzelne Großbestellungen sichtbar bleiben.

### 7.10 Gutscheine

Anteil der Positionen mit gefülltem `voucher`, je Event und über die Auswahl. Dazu die
häufigsten Gutscheincodes mit Anzahl.

### 7.11 Zahlungsart

Verteilung von `payment_provider` über Bestellungen, je Event und über die Auswahl.

### 7.12 Herkunft

Gruppierung über die ersten zwei Stellen der PLZ, dazu die häufigsten Orte und die Verteilung
über `country`. Die Abdeckung (Anteil der Bestellungen mit gefüllter Adresse, im Bestand 46 %)
steht als Zahl über dem Block.

### 7.13 Storno- und Ablaufquote

Diese Kennzahl wird auf **Bestellebene aus `pretix_orders`** gerechnet, weil stornierte
Positionen in `pretix_positions` gar nicht erst landen: Anteil der Bestellungen mit Status `c`
oder `e` an allen Bestellungen eines Events, sowie je Käufer die Anzahl solcher Bestellungen.

Lesart: „Bestellungen, die storniert wurden oder abgelaufen sind, gemessen an allen
Bestellungen des Events."

Ein Käufer, der ausschließlich stornierte oder abgelaufene Bestellungen hat, erscheint deshalb
in diesem Block, während er in der Käuferliste aus 7.4 fehlt — dort zählt das Publikum, hier
zählen die Bestellungen. Der Block weist die Zahl dieser Käufer eigens aus.

### 7.14 Positionsfragen

Falls Positionen Antworten tragen, werden sie generisch ausgewertet: je Frage die Verteilung
der Antworten, mit dem Fragetext aus dem Payload. Fragen ohne Antworten in der Auswahl bleiben
unsichtbar.

## 8. Oberfläche

Neue Filament-Seite `App\Filament\Pages\AudiencePage`:

- Slug `publikum`, Navigationsgruppe `Berichte`, Titel „Publikum", Icon `heroicon-o-user-group`
- Kopfbereich als Formular: Veranstaltungen (Mehrfachauswahl, Vorauswahl alle aktiven mit
  pretix-Slug), Bestellstatus (Vorgabe `p`), Ticketarten (leer = alle), Zeitraum von/bis
- Abschnitt 1 — Kennzahlen aus 7.1, als Kachelreihe
- Abschnitt 2 — Überschneidungsmatrix aus 7.2, darunter Neu gegen wiederkehrend aus 7.3
- Abschnitt 3 — Käuferliste aus 7.4 als Filament-Tabelle
- Abschnitt 4 — die Dimensionen aus 7.5 bis 7.14, je ein kompakter Block

Die Käuferliste ist eine Filament-Tabelle über `PretixPosition`, gruppiert nach `buyer_email`.
Als Datensatzschlüssel dient `MIN(id)`. Die Seite bindet
`App\Filament\Concerns\ClampsRecordsPerPageOnReload` ein. Der Trait überschreibt
`getDefaultTableRecordsPerPageSelectOption()`, die `Filament\Tables\Concerns\InteractsWithTable`
auch auf einer Page bereitstellt; die Planung prüft das an einem laufenden Beispiel und
erweitert den Trait, falls die Signatur dort abweicht.

**Styling:** Das Projekt hat keinen Tailwind-Build. Eigene Darstellung kommt als echtes CSS in
`resources/views/filament/adminlte-theme.blade.php` unter einer eigenen Klasse (`.aud`), nach
dem Vorbild von `.rpt`. Light-only Flächen werden auf `html:not(.dark)` beschränkt.

**Diagramme:** Verkaufsverlauf und Verteilungen als Filament-Chart-Widgets (Chart.js), nach dem
Vorbild von `RevenueByDayChart`. Die Matrix bleibt eine Tabelle.

**Export:** Die Käuferliste und die Überschneidungsmatrix lassen sich als CSV und XLSX
herunterladen, über den vorhandenen Exportweg.

## 9. Zugriff und Datenschutz

### Zugriff

- Admins sehen alle Veranstaltungen.
- Die Rolle `customer` sieht ausschließlich Veranstaltungen mit der eigenen `customer_id`.

Dafür bekommt `App\Support\CustomerScope` eine Methode:

```php
public static function byEventSlug(Builder $query, string $column = 'event_slug'): Builder
```

Sie beschränkt auf die `pretix_event_slug`-Werte der Events des Veranstalters. Ein Nutzer ohne
`customer_id` erhält eine leere Liste und sieht damit nichts. Aufgerufen wird sie genau einmal,
in `AudienceQuery`.

Ein Veranstalter sieht Käufe, die zu seinen eigenen Veranstaltungen gehören. Käufe derselben
Person bei anderen Veranstaltern bleiben ihm verborgen — auch in den Spalten
„Veranstaltungen" und „Erste Bestellung" der Käuferliste, die deshalb ebenfalls über
`AudienceQuery` laufen.

Die Seite selbst ist über `canAccess()` an ein eigenes Recht `view-audience` gebunden, das
Admins und die Veranstalterrolle erhalten.

### Datenschutz

Die Auswertung bildet Profile von Ticketkäufern. Aufzunehmen ins Verarbeitungsverzeichnis:

| Punkt | Inhalt |
|---|---|
| Zweck | Verbesserung des Veranstaltungsangebots und der Eventauswahl |
| Rechtsgrundlage | Berechtigtes Interesse, Art. 6 Abs. 1 lit. f DSGVO |
| Datenkategorien | E-Mail, Name, Rechnungsort, Ticketart, Preis, Kaufzeitpunkt, Gutscheincode |
| Herkunft | pretix-Bestellungen |
| Empfänger | Betreiber; Veranstalter ausschließlich für eigene Veranstaltungen |
| Aufbewahrung | Abgeleitet aus pretix; die Zeile verschwindet, sobald die Bestellung in pretix entfällt |

Auskunfts- und Löschbegehren werden in pretix bedient; `pretix:rebuild-positions` zieht die
Tabelle nach. Mailings laufen weiter über den bestehenden Teilnehmer-Export, damit der Versand
an einer Stelle bleibt.

## 10. Tests

| Test | Prüft |
|---|---|
| `PretixPositionsBackfillTest` | Zeilenzahl, Summen und Ticketarten der Tabelle gegen das, was `ParticipantExporter` direkt aus `raw_payload` liest — samt erneutem Import, bei dem eine Position storniert wurde und die Zeile verschwinden muss |
| `PretixPositionsSchemaTest` | Liest die Spaltenbreiten aus der Migration und hält sie gegen eine Liste der Feldlängen, die pretix für diese Felder zulässt. Damit fällt das Einengen einer Spalte auch unter SQLite auf, wo ein Schreibversuch grün bliebe; Vorbild `SourceFormatFitsColumnTest` |
| `AudienceOverlapTest` | Bekannte Konstellation aus drei Events und fünf Käufern ergibt die erwartete Matrix, inklusive der asymmetrischen Prozentwerte |
| `AudienceReturningTest` | Erstkäufer gegen wiederkehrend, mit einem Käufer, dessen erste Bestellung außerhalb der Auswahl liegt |
| `AudienceScopeTest` | Gegenprobe: ein Veranstalter sieht sein eigenes Event; ein fremdes Event taucht in keiner der Auswertungen und in keiner Spalte der Käuferliste auf |
| `AudienceFiltersTest` | Livewire wendet jeden Filter der Seite tatsächlich an — der Seiten-Smoke-Test sieht das nicht |
| `AudiencePageSmokeTest` | Die Seite rendert für Admin und Veranstalter |

Zusätzlich eine **Gegenprobe auf PostgreSQL**: Die Käufersuche nutzt `LOWER(…) LIKE`, weil
`LIKE` auf PostgreSQL Groß- und Kleinschreibung unterscheidet und auf SQLite nicht. Geprüft
wird gegen den Produktivbestand über ein Wegwerfskript in einer zurückgerollten Transaktion.

Der Wächter gegen Rückfall: Wer `CustomerScope::byEventSlug` aus `AudienceQuery` entfernt, macht
`AudienceScopeTest` rot.

## 11. Bewusst draußen

- Vorgerechnete Käuferprofile in einer eigenen Tabelle
- Segmentierung, Scoring oder Vorhersagen
- Mailversand aus der Seite heraus
- Zusammenführung von Personen über Namen oder Adresse; der Schlüssel bleibt die E-Mail
- Auswertung der PayPal-Transaktionen ohne zugehörige pretix-Bestellung

## 12. Annahmen

- `Event.pretix_event_slug` bleibt die Brücke zwischen TxWatch-Event und pretix-Veranstaltung.
  Event 1 („VIP Dauerkarte 2026") trägt einen Wert, der kein pretix-Slug ist, und hat keine
  Bestellungen; solche Events erscheinen in der Auswahl mit dem Hinweis, dass keine Bestellungen
  vorliegen.
- Zwei TxWatch-Events können denselben pretix-Slug tragen. Die Auswertung gruppiert über den
  Slug und zeigt den Namen des zuerst angelegten Events.
- Der Umfang des Bestands (1458 Bestellungen) erlaubt Aggregation in SQL ohne Zwischenspeicher.
  Ein Cache kommt dazu, sobald eine Auswertung messbar langsam wird; der Schlüssel muss dann die
  Mandantensperre enthalten.
