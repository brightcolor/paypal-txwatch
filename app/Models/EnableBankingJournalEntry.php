<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One transaction as the bank reported it - recorded, not booked.
 *
 * THIS IS THE HOLDING AREA. Nothing reads it for reports, for the EÜR or for the
 * reconciliation, and that is the whole point of the current stage: the pull runs,
 * the data can be looked at, and no figure in the books moves because of it.
 *
 * `promoted_at` and `bank_transaction_id` stay empty until that changes. When it
 * does, the entries go through the SAME importer the file import uses - they share
 * `import_hash` with it, so an entry cannot become a duplicate of a statement line
 * that was already imported by hand.
 */
class EnableBankingJournalEntry extends Model
{
    protected $table = 'enable_banking_journal';

    protected $fillable = [
        'import_hash', 'booked_on', 'valued_on', 'amount', 'currency', 'purpose',
        'counterparty_name', 'counterparty_iban', 'end_to_end_id', 'bank_ref',
        'pretix_order_code', 'raw', 'pulled_at', 'promoted_at', 'bank_transaction_id',
        'match_method', 'match_score', 'match_candidates', 'match_haystack',
        'pretix_order_status', 'possible_double_payment',
    ];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'valued_on' => 'date',
            'amount' => 'decimal:2',
            'raw' => 'array',
            'match_candidates' => 'array',
            'possible_double_payment' => 'boolean',
            'pulled_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    /** The protocol, oldest first - it is read as a course of events. */
    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EnableBankingJournalEvent::class, 'journal_entry_id')->orderBy('at');
    }

    /**
     * Is there a proposal waiting for a decision?
     *
     * A proposal exists exactly when the recognition found something but did not
     * assign it - i.e. one character was wrong or missing.
     */
    public function hasSuggestion(): bool
    {
        return $this->pretix_order_code === null
            && is_array($this->match_candidates)
            && $this->match_candidates !== [];
    }

    /**
     * The best proposal, or null.
     *
     * @return array<string, mixed>|null
     */
    public function bestSuggestion(): ?array
    {
        return $this->hasSuggestion() ? $this->match_candidates[0] : null;
    }

    /** Money in - the direction that can settle a ticket order. */
    public function scopeCredits($query)
    {
        return $query->where('amount', '>', 0);
    }

    public function isPromoted(): bool
    {
        return $this->promoted_at !== null;
    }

    /**
     * Is there anything to DO for this entry?
     *
     * The distinction the journal was missing: a recognised order whose state is
     * still open is work. A recognised order that is already paid is information -
     * and of 1025 orders, 986 are in that state. Without separating the two, almost
     * every entry looked like an unexplained gap.
     */
    public function isActionable(): bool
    {
        return $this->pretix_order_status === 'n'
            // A second credit on one order is work even though the order is settled -
            // very likely a refund is owed.
            || (bool) $this->possible_double_payment
            || $this->hasSuggestion();
    }

    /** Recognised, but nothing follows from it. */
    public function isSettled(): bool
    {
        return $this->pretix_order_code !== null && $this->pretix_order_status === 'p';
    }

    /** One short phrase for what this entry means. */
    public function stateLabel(): string
    {
        return match (true) {
            (bool) $this->possible_double_payment => 'mögliche Doppelzahlung',
            $this->pretix_order_status === 'n' => 'offen – zu buchen',
            $this->isSettled() => 'bereits bezahlt',
            $this->pretix_order_code !== null => 'zugeordnet',
            $this->hasSuggestion() => 'Vorschlag',
            default => 'keine Zuordnung',
        };
    }
    /**
     * The normalized entry as the shared import pipeline expects it.
     *
     * Rebuilt from the stored columns rather than from `raw`, so a promotion uses
     * exactly the values a human reviewed in the journal - not a second, possibly
     * different interpretation of the payload.
     *
     * @return array<string, mixed>
     */
    public function toImportEntry(): array
    {
        return [
            'booked_on' => $this->booked_on?->format('Y-m-d'),
            'valued_on' => $this->valued_on?->format('Y-m-d'),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'purpose' => $this->purpose,
            'counterparty_name' => $this->counterparty_name,
            'counterparty_iban' => $this->counterparty_iban,
            'end_to_end_id' => $this->end_to_end_id,
            'bank_ref' => $this->bank_ref,
            'source_format' => 'enablebanking',
        ];
    }
}
