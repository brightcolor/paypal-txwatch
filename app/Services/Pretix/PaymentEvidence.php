<?php

namespace App\Services\Pretix;

use App\Models\BankTransaction;
use App\Models\EnableBankingJournalEntry;
use App\Models\PretixPaymentConfirmation;

/**
 * The money that is supposed to pay an order, and where it came from.
 *
 * A value object rather than ten loose arguments: the call decides whether a
 * customer gets their tickets, and a transposed pair of arguments in a call that
 * long is the kind of mistake nothing catches. It also fixes ONE shape for both
 * sources - a row in the books and an entry from the bank pull - which is what
 * keeps the two paths from drifting into two different sets of rules.
 */
class PaymentEvidence
{
    public function __construct(
        public readonly string $source,
        public readonly ?string $orderCode,
        public readonly float $amount,
        public readonly ?string $purpose = null,
        public readonly ?string $currency = 'EUR',
        public readonly ?string $counterpartyName = null,
        public readonly ?string $bookedOn = null,
        public readonly ?int $connectionId = null,
        public readonly ?string $eventSlug = null,
        public readonly ?int $bankTransactionId = null,
        public readonly ?int $journalEntryId = null,
    ) {
    }

    /**
     * The same money, but assigned to the order a PERSON named.
     *
     * The recognition reads the purpose text; it cannot know that the guest quoted
     * the wrong code or none at all. Only the assignment changes - amount, purpose
     * and origin stay exactly as the bank delivered them, because those are the
     * evidence and must not be rewritten by whoever decides.
     */
    public function withOrderCode(string $orderCode): self
    {
        return new self(
            source: $this->source,
            orderCode: mb_strtoupper(trim($orderCode)),
            amount: $this->amount,
            purpose: $this->purpose,
            currency: $this->currency,
            counterpartyName: $this->counterpartyName,
            bookedOn: $this->bookedOn,
            connectionId: $this->connectionId,
            eventSlug: $this->eventSlug,
            bankTransactionId: $this->bankTransactionId,
            journalEntryId: $this->journalEntryId,
        );
    }

    /** A row in the books - imported statement file or promoted journal entry. */
    public static function fromBankTransaction(BankTransaction $bank): self
    {
        return new self(
            source: PretixPaymentConfirmation::SOURCE_BANK,
            orderCode: $bank->pretix_order_code,
            amount: (float) $bank->amount,
            purpose: $bank->purpose,
            currency: $bank->currency ?? 'EUR',
            counterpartyName: $bank->counterparty_name,
            bookedOn: $bank->booked_on?->format('Y-m-d'),
            connectionId: $bank->pretix_connection_id,
            eventSlug: $bank->pretix_event_slug,
            bankTransactionId: $bank->id,
        );
    }

    /**
     * An entry straight from the bank pull, before anything is booked.
     *
     * Deliberately usable in journal mode: marking an order paid in pretix and
     * booking money into the accounts are two different things, and the request was
     * for the first one - "on incoming payment", not "after the next import".
     */
    public static function fromJournalEntry(EnableBankingJournalEntry $entry): self
    {
        return new self(
            source: PretixPaymentConfirmation::SOURCE_JOURNAL,
            orderCode: $entry->pretix_order_code,
            amount: (float) $entry->amount,
            purpose: $entry->purpose,
            currency: $entry->currency ?? 'EUR',
            counterpartyName: $entry->counterparty_name,
            bookedOn: $entry->booked_on?->format('Y-m-d'),
            journalEntryId: $entry->id,
        );
    }
}
