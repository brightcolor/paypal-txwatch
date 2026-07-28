<?php

namespace App\Services\Bank;

/**
 * Translates raw FinTS return codes into plain-German, actionable hints.
 *
 * Background: the bank answers with numeric codes (0xxx = ok, 3xxx = hint,
 * 9xxx = error) plus its OWN free text. The text differs per institute, so we
 * never replace it - we prefix an explanation of what the code means and what
 * to do, and keep the bank's wording underneath.
 *
 * A failed dialog usually reports SEVERAL codes at once, where the generic ones
 * ("Die Nachricht enthält Fehler", "Dialog abgebrochen") merely wrap the real
 * cause. We therefore pick the most specific code, not the first one.
 */
class FintsErrorHelp
{
    /**
     * Generic "envelope" codes: they only say that something went wrong, never
     * what. Used as a last resort when no specific code is present.
     *
     * @var array<int, string>
     */
    private const GENERIC = ['9050', '9800', '9010', '9340', '9380', '3999', '9999'];

    /**
     * Specific codes with a concrete recommendation.
     *
     * @var array<string, string>
     */
    private const HINTS = [
        // --- Produktregistrierung (PSD2) ---
        '9078' => 'Die Bank kennt eure Registrierungsnummer (noch) nicht. Nach der Beantragung bei der '
            . 'Deutschen Kreditwirtschaft dauert es einige Zeit, bis die Nummer an die Bankrechner verteilt ist. '
            . 'Bitte die Nummer auf Tippfehler prüfen und sonst in einigen Tagen erneut versuchen.',
        '3078' => 'Die Software gilt bei der Bank nicht als registriertes FinTS-Produkt. Bitte die '
            . 'Registrierungsnummer der Deutschen Kreditwirtschaft prüfen bzw. deren Freischaltung abwarten.',
        '3079' => 'Die Bank verweist an den Hersteller des Banking-Programms – das seid ihr selbst: In aller '
            . 'Regel geht es um die Produktregistrierung. Bitte Registrierungsnummer prüfen bzw. bei der '
            . 'FinTS-Leitstelle nachfragen.',

        // --- Zugangsdaten / Sperren ---
        '9931' => 'Anmeldename oder PIN wurden abgelehnt. Bei Sparkassen ist als Anmeldename meist die '
            . '(10-stellige) Legitimations-ID nötig – nicht der selbst gewählte Anmeldename aus dem Web-Banking. '
            . 'Achtung: Nach mehreren Fehlversuchen sperrt die Bank den Zugang. Bitte erst die Zugangsdaten '
            . 'klären, statt weiter zu probieren.',
        '9930' => 'Der Zugang bzw. Geschäftsbereich ist bei der Bank gesperrt. Entsperren kann nur die Bank '
            . '(Filiale oder Telefon-Banking).',
        '9942' => 'Die PIN wurde abgelehnt. Bitte die Online-Banking-PIN prüfen – nach mehreren Fehlversuchen '
            . 'sperrt die Bank den Zugang.',

        // --- Starke Authentifizierung (TAN) ---
        '9075' => 'Die Bank verlangt eine starke Authentifizierung (TAN). Bitte den Login erneut starten und '
            . 'die TAN bzw. die Freigabe in der Banking-App bestätigen.',
        '9260' => 'Die TAN-Anforderung wurde abgebrochen, der Auftrag nicht ausgeführt. Bitte erneut versuchen '
            . 'und die Freigabe zügig bestätigen (TAN-Anfragen laufen nach kurzer Zeit ab).',
        '3076' => 'Für diesen Schritt war keine TAN nötig – das ist keine Fehlermeldung.',
        '3920' => 'Die Bank hat die für euch zugelassenen TAN-Verfahren gemeldet. Bitte die genannte Nummer '
            . 'oben im Feld „TAN-Verfahren (ID)" eintragen und speichern.',
        '3955' => 'Das gewählte TAN-Verfahren passt nicht bzw. es wird ein TAN-Medium benötigt. Bitte über '
            . '„TAN-Verfahren anzeigen" ein zulässiges Verfahren (und ggf. Medium) auswählen.',
        '9210' => 'Die Bank konnte den Auftrag mit dem gewählten TAN-Verfahren nicht bearbeiten. Bitte über '
            . '„TAN-Verfahren anzeigen" ein anderes zulässiges Verfahren wählen.',

        // --- Technik / Nachrichtenaufbau ---
        '9030' => 'Die Bank konnte die Nachricht nicht entschlüsseln. Meist hilft ein erneuter Login; bleibt es '
            . 'dabei, bitte die Zugangsdaten neu speichern (Verbindung trennen und neu anmelden).',
        '9110' => 'Die Bank hat den Nachrichtenaufbau abgelehnt. Das deutet auf eine technische Unverträglichkeit '
            . 'mit diesem Bankrechner hin – bitte melden, dann sehen wir uns das Protokoll an.',
        '9130' => 'Ein Feld enthält für die Bank ungültige Zeichen. Bitte Zugangsdaten auf Sonderzeichen, '
            . 'Umlaute oder versehentlich kopierte Leerzeichen prüfen.',
        '9330' => 'Der Zugang muss neu legitimiert werden (Schlüssel/Anmeldung nicht mehr gültig). Bitte einmal '
            . '„Trennen" und anschließend neu anmelden.',
        '9370' => 'Der Bank fehlt eine Signatur bzw. Berechtigung für diesen Benutzer. Bitte prüfen, ob der '
            . 'Zugang für FinTS/HBCI freigeschaltet ist – das klärt die Bank.',

        // --- Fachliche Hinweise (kein Fehler) ---
        '3010' => 'Für den abgefragten Zeitraum liegen keine Umsätze vor – das ist kein Fehler.',
        '3040' => 'Die Bank liefert die Daten in mehreren Teilen; der Abruf wird automatisch fortgesetzt.',
        '3050' => 'Die Bankparameter waren veraltet und wurden aktualisiert – das ist kein Fehler.',
        '3060' => 'Die Kundendaten waren veraltet und wurden aktualisiert – das ist kein Fehler.',
        '0020' => 'Der Auftrag wurde von der Bank ausgeführt.',
    ];

    /** Explanations by code range, when no specific code is known. */
    private const RANGE = [
        '0' => 'Die Bank meldet einen erfolgreichen Ablauf.',
        '3' => 'Die Bank meldet einen Hinweis (keinen Fehler). Details stehen in ihrer Meldung unten – '
            . 'meist kann der Vorgang normal fortgesetzt werden.',
        '9' => 'Die Bank hat den Vorgang mit einem Fehler abgebrochen. Der genaue Grund steht in ihrer Meldung '
            . 'unten. Häufige Ursachen: falsche Zugangsdaten, noch nicht freigeschaltete Registrierungsnummer '
            . 'oder eine erforderliche TAN-Freigabe.',
    ];

    /**
     * Returns a plain-German hint for the most specific FinTS code found in the
     * message, or null if the message carries no recognisable code.
     */
    public static function explain(?string $message): ?string
    {
        if (blank($message)) {
            return null;
        }

        $codes = self::codes($message);
        if ($codes === []) {
            return null;
        }

        // Specific codes first; generic envelope codes ("Nachricht enthält
        // Fehler") only if nothing more meaningful is present.
        foreach ($codes as $code) {
            if (isset(self::HINTS[$code]) && ! in_array($code, self::GENERIC, true)) {
                return self::HINTS[$code];
            }
        }

        foreach ($codes as $code) {
            if (isset(self::HINTS[$code])) {
                return self::HINTS[$code];
            }
        }

        // Unknown code: at least explain what its range means, so the operator
        // knows whether this is fatal or just a note.
        foreach ($codes as $code) {
            if (! in_array($code, self::GENERIC, true) && isset(self::RANGE[$code[0]])) {
                return self::RANGE[$code[0]];
            }
        }

        return self::RANGE[$codes[0][0]] ?? null;
    }

    /** The hint (when known) followed by the bank's raw message, for notifications. */
    public static function decorate(?string $message): string
    {
        $message = (string) $message;
        $hint = self::explain($message);

        return $hint ? $hint . "\n\n" . $message : $message;
    }

    /**
     * All four-digit FinTS return codes in the message, in order of appearance.
     *
     * @return array<int, string>
     */
    private static function codes(string $message): array
    {
        // Codes appear as standalone 4-digit numbers ("9078 (wrt seg 4): ...").
        preg_match_all('/\b([039]\d{3})\b/', $message, $m);

        return array_values(array_unique($m[1] ?? []));
    }
}
