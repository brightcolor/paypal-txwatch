<?php

namespace App\Services\Bank;

/**
 * Translates raw FinTS return codes into plain-German, actionable hints.
 *
 * The bank answers with numeric codes ("9078", "9931") that end up verbatim in
 * the exception message. On their own they tell an operator nothing, so the
 * settings page prefixes a short explanation of what to actually do.
 */
class FintsErrorHelp
{
    /**
     * Known codes, most specific first (the first match wins).
     *
     * @var array<string, string>
     */
    private const HINTS = [
        '9078' => 'Die Bank kennt eure Registrierungsnummer (noch) nicht. '
            . 'Nach der Beantragung bei der Deutschen Kreditwirtschaft dauert es einige Zeit, '
            . 'bis die Nummer an die Bankrechner verteilt ist. Bitte Nummer auf Tippfehler prüfen '
            . 'und sonst einige Tage später erneut versuchen.',

        '9931' => 'Anmeldename oder PIN wurden von der Bank abgelehnt. Bei Sparkassen ist als '
            . 'Anmeldename meist die (10-stellige) Legitimations-ID nötig – nicht der selbst gewählte '
            . 'Anmeldename aus dem Web-Banking. Achtung: nach mehreren Fehlversuchen sperrt die Bank den Zugang.',

        '9930' => 'Der Zugang ist bei der Bank gesperrt. Bitte direkt bei der Bank entsperren lassen.',

        '3920' => 'Die Bank hat die zugelassenen TAN-Verfahren gemeldet. Bitte die genannte Nummer '
            . 'oben im Feld „TAN-Verfahren (ID)" eintragen und speichern.',

        '9075' => 'Die Bank verlangt eine (erneute) starke Authentifizierung. Bitte den Login wiederholen '
            . 'und die TAN bestätigen.',
    ];

    /** Returns a plain-German hint for the first known code in the message, if any. */
    public static function explain(?string $message): ?string
    {
        if (blank($message)) {
            return null;
        }

        foreach (self::HINTS as $code => $hint) {
            if (str_contains($message, $code)) {
                return $hint;
            }
        }

        return null;
    }

    /** The hint (when known) followed by the bank's raw message, for notifications. */
    public static function decorate(?string $message): string
    {
        $message = (string) $message;
        $hint = self::explain($message);

        return $hint ? $hint . "\n\n" . $message : $message;
    }
}
