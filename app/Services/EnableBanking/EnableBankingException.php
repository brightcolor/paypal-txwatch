<?php

namespace App\Services\EnableBanking;

use RuntimeException;

/**
 * A refusal from Enable Banking, translated into a sentence with a next step.
 *
 * WHY TRANSLATE AT ALL: the same lesson FinTS taught this project (see
 * FintsErrorHelp). The raw answer is `{"error_code": "ACCESS_DENIED"}` - true,
 * useless, and it looks like a defect. What an operator needs is which side said
 * no and what to do about it.
 *
 * THE UNTRANSLATED CASE STAYS READABLE. Anything not in the table keeps the
 * service's own description; making up a friendly sentence for an unknown code
 * would be worse than the code, because it would sound like knowledge.
 */
class EnableBankingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Known refusals, and what to do about each.
     *
     * Keyed on `error_code`; the wording addresses whoever is looking at the
     * screen, which for this application is always an admin.
     */
    private const HELP = [
        'ACCESS_DENIED' => 'Die Zustimmung wurde bei der Bank abgebrochen oder abgelehnt. Es wurde nichts '
            . 'verbunden – der Vorgang kann einfach neu gestartet werden.',
        'SESSION_EXPIRED' => 'Die Zustimmung ist abgelaufen. PSD2 begrenzt sie auf höchstens 90 Tage; bitte '
            . 'die Bank unter „Bank verbinden" erneut freigeben.',
        'SESSION_INVALID' => 'Die Verbindung gilt bei Enable Banking nicht mehr. Bitte die Bank erneut '
            . 'verbinden.',
        'INVALID_CODE' => 'Der Rückkehrcode wurde bereits eingelöst oder ist abgelaufen. Er gilt nur wenige '
            . 'Minuten und nur einmal – bitte den Vorgang neu starten.',
        'WRONG_REDIRECT_URL' => 'Die Rückkehr-Adresse dieser Installation ist im Control Panel von Enable '
            . 'Banking nicht eingetragen. Sie muss dort zeichengenau stehen – der Selbsttest auf der '
            . 'Einrichtungsseite nennt die Adresse.',
        'UNAUTHORIZED' => 'Enable Banking hat den Ausweis abgewiesen. Meist passt der hinterlegte Schlüssel '
            . 'nicht zur Anwendungskennung – der Selbsttest auf der Einrichtungsseite klärt das.',
        'APPLICATION_NOT_ACTIVE' => 'Die Anwendung ist im Control Panel nicht aktiv. Ohne Freigabe dort '
            . 'lässt sich keine Bank verbinden.',
    ];

    /**
     * Build from the service's error body.
     *
     * 401 GETS ITS OWN SENTENCE EVEN WITHOUT A CODE, because it is the one an
     * operator hits first and most often: a freshly uploaded key that does not
     * belong to the stored application id answers exactly this way, with nothing
     * else to go on.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body, int $status): self
    {
        $code = is_string($body['error_code'] ?? null) ? strtoupper($body['error_code']) : null;

        $described = collect(['error_description', 'error', 'message', 'detail'])
            ->map(fn (string $key) => $body[$key] ?? null)
            ->first(fn ($value) => is_string($value) && trim($value) !== '');

        $help = $code !== null ? (self::HELP[$code] ?? null) : null;

        if ($help === null && $status === 401) {
            $help = self::HELP['UNAUTHORIZED'];
        }

        $message = $help
            ?? (is_string($described) ? trim($described) : null)
            ?? sprintf('Der Bankdienst antwortete mit Status %d.', $status);

        /*
         * The service's own wording is appended when we replaced it with our
         * own - it is what helps on the phone with support, and it costs one
         * parenthesis.
         */
        if ($help !== null && is_string($described) && trim($described) !== '') {
            $message .= ' (' . trim($described) . ')';
        }

        return new self($message, $status, $code);
    }
}
