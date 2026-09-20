# Publikumsauswertung — Eintrag für das Verarbeitungsverzeichnis

Diese Datei hält fest, was für das Verarbeitungsverzeichnis nach Art. 30 DSGVO zu übernehmen ist.
Sie ersetzt das Verzeichnis selbst nicht.

| Punkt | Inhalt |
|---|---|
| Bezeichnung | Publikumsauswertung in PayPal TxWatch (Seite „Berichte → Publikum") |
| Zweck | Verbesserung des Veranstaltungsangebots und der Eventauswahl |
| Rechtsgrundlage | Berechtigtes Interesse, Art. 6 Abs. 1 lit. f DSGVO |
| Betroffene | Ticketkäufer der über pretix verkauften Veranstaltungen |
| Datenkategorien | E-Mail-Adresse, Name, PLZ, Ort und Land der Rechnungsadresse, Ticketart, Preis, Kaufzeitpunkt, Zahlungsart, Gutscheinkennung, Name auf dem Ticket, Antworten auf Fragen beim Kauf |
| Herkunft | pretix-Bestellungen, übernommen durch den bestehenden pretix-Import |
| Speicherort | Tabelle `pretix_positions` in der TxWatch-Datenbank, abgeleitet aus `pretix_orders` |
| Empfänger | Betreiber; Veranstalter mit Portalzugang ausschließlich für die eigenen Veranstaltungen |
| Übermittlung in Drittländer | keine |
| Aufbewahrung | Abgeleitet aus pretix. Eine Zeile verschwindet, sobald die Position in pretix storniert ist und der nächste Import oder `pretix:rebuild-positions` gelaufen ist |
| Auskunft und Löschung | Werden in pretix bedient; TxWatch zieht beim nächsten Lauf nach |
| Exporte | Käuferliste und Überschneidung als CSV oder Excel, im Speicher erzeugt und direkt ausgeliefert; auf dem Server bleibt keine Datei zurück |
| Technische Maßnahmen | Zugriff nur mit dem Recht `view-audience`; Mandantentrennung über `AudienceQuery` und `CustomerScope::byEventSlug`; Tests sichern beides ab |

## Abwägung zum berechtigten Interesse

Ausgewertet wird, welche Veranstaltungen sich ein Publikum teilen und wie gekauft wird, um das Angebot
danach auszurichten. Die Daten stammen aus Käufen, die die Betroffenen selbst getätigt haben, und verlassen
den Betreiber nicht. Es findet keine Anreicherung aus fremden Quellen statt, keine automatisierte
Einzelentscheidung und kein Versand aus der Auswertung heraus; Mailings laufen weiterhin über den
Teilnehmer-Export und dessen eigene Rechtsgrundlage.
