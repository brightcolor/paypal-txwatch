<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to mark a pretix order paid - and why it went the way it did.
 *
 * APPEND-ONLY BY CONSTRUCTION. `$timestamps` is off and nothing here updates or
 * deletes; the Filament resource offers no edit and no delete either. These rows
 * exist to be checked against, and a record that can be corrected afterwards
 * answers nothing.
 *
 * Written for EVERY attempt, not only the successful ones. "Why was this order
 * marked paid" and "why was that one not" are the same question from two sides,
 * and the second one used to be unanswerable the moment the next run overwrote the
 * error column.
 */
class PretixPaymentConfirmation extends Model
{
    public const OUTCOME_CONFIRMED = 'confirmed';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_SKIPPED = 'skipped';

    public const SOURCE_BANK = 'bank';
    public const SOURCE_JOURNAL = 'journal';

    /** Why it went that way - short keys, so filtering does not depend on wording. */
    public const REASON_OK = 'exact_amount_and_code';
    public const REASON_SWITCH_OFF = 'event_switch_off';
    public const REASON_NO_EVENT = 'event_unknown';
    public const REASON_NOT_OPEN = 'order_not_open';
    public const REASON_NO_PAYMENT = 'no_pending_payment';
    public const REASON_AMOUNT = 'amount_mismatch';
    public const REASON_ALREADY = 'already_confirmed';
    public const REASON_API = 'pretix_refused';
    public const REASON_NO_ORDER = 'order_unknown';

    /**
     * Confirmed although the amount did not fit - and someone said so by name.
     *
     * ITS OWN REASON, not a plain OK with a longer message. The one case where a
     * confirmation went out WITHOUT the strongest corroboration there is has to be
     * findable as a set, not buried in free text. The automation can never reach
     * this value; see PaymentMarker.
     */
    public const REASON_FORCED_AMOUNT = 'amount_mismatch_accepted';

    /**
     * pretix accepted the call and the order still is not paid.
     *
     * Its own reason because it is the one failure that LOOKS like a success from
     * the outside: the request went through, the response was 200, and the guest has
     * no tickets. Found only by asking pretix again afterwards.
     */
    public const REASON_NOT_CONFIRMED = 'not_paid_after_confirm';

    public $timestamps = false;

    protected $fillable = [
        'pretix_connection_id', 'event_slug', 'order_code', 'event_id',
        'bank_transaction_id', 'journal_entry_id', 'source',
        'amount', 'currency', 'purpose', 'counterparty_name', 'booked_on',
        'outcome', 'reason', 'message', 'user_id', 'automatic',
        'pretix_payment_local_id', 'at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'booked_on' => 'date',
            'automatic' => 'boolean',
            'at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::OUTCOME_CONFIRMED;
    }

    /**
     * Who set this in motion, in the words the protocol uses everywhere.
     *
     * One implementation for the list, the evidence view and the journal protocol:
     * three copies of "Automatik or a name" would eventually say three things about
     * the same row.
     */
    public function triggeredByLabel(): string
    {
        if ($this->automatic) {
            return 'die Automatik';
        }

        return $this->user?->name ?? 'Handeingabe';
    }

    /**
     * The reason in one plain sentence.
     *
     * Kept next to the constants rather than in the view: the same wording is needed
     * in the list, in the detail and in the journal protocol, and three copies would
     * eventually say three different things.
     */
    public function reasonText(): string
    {
        return match ($this->reason) {
            self::REASON_OK => 'Die Bestellnummer stand im Verwendungszweck und der Betrag stimmte auf den Cent.',
            self::REASON_SWITCH_OFF => 'Für dieses Event ist „Zahlungen automatisch melden" ausgeschaltet.',
            self::REASON_NO_EVENT => 'Zu dieser Bestellung gibt es kein Event in TxWatch – ohne Event gibt es '
                . 'keinen Schalter, und ohne Schalter wird nichts gemeldet.',
            self::REASON_NOT_OPEN => 'Die Bestellung war nicht offen (bereits bezahlt, storniert oder abgelaufen).',
            self::REASON_NO_PAYMENT => 'In pretix gab es keine offene Überweisungs-Zahlung zu dieser Bestellung.',
            self::REASON_AMOUNT => 'Der Betrag der offenen Zahlung wich vom Geldeingang ab.',
            self::REASON_ALREADY => 'Für diese Bestellung wurde bereits ein Geldeingang gemeldet.',
            self::REASON_API => 'pretix hat die Meldung abgelehnt.',
            self::REASON_NO_ORDER => 'Zu dieser Bestellnummer gibt es keine Bestellung in TxWatch.',
            self::REASON_FORCED_AMOUNT => 'Von Hand gemeldet, obwohl der Betrag abwich – die Abweichung '
                . 'wurde ausdrücklich angenommen.',
            self::REASON_NOT_CONFIRMED => 'pretix hat die Meldung angenommen, führt die Bestellung danach '
                . 'aber nicht als bezahlt. Die Nachprüfung hat das aufgedeckt.',
            default => (string) $this->message,
        };
    }

    /**
     * Reason and wording in one line, for a screen that has room for one.
     *
     * The reason is the RULE, the message the individual case - "der Betrag wich ab"
     * against "31,50 statt 25,00". A notification that shows only the first leaves
     * the reader to guess the numbers; one that shows only the second reads like a
     * stray sentence.
     */
    public function explain(): string
    {
        $reason = $this->reasonText();
        $message = (string) $this->message;

        return $message === '' || $message === $reason ? $reason : $reason . ' ' . $message;
    }

    public function outcomeLabel(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_CONFIRMED => 'als bezahlt gemeldet',
            self::OUTCOME_FAILED => 'fehlgeschlagen',
            default => 'nicht gemeldet',
        };
    }
}
