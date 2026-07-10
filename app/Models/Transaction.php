<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    /**
     * PayPal transaction event codes for genuine refunds/reversals of a
     * sale, per PayPal's official T-code reference (see LEDGER_ONLY_PREFIXES
     * for why sign-correlation ("negative gross_amount") is NOT a reliable way
     * to detect these - two earlier passes at this constant used exactly that
     * heuristic and both were wrong: T0006 (~99% of all transactions) was once
     * assumed to be a refund code, and T0400/T0403/T2101 were later added
     * because they were 100% negative-gross_amount in production data - but
     * they are documented as bank withdrawals (T0400/T0403) and a fund hold
     * (T2101), not refunds. Verify any addition against
     * https://developer.paypal.com/docs/transaction-search/transaction-event-codes/
     * before adding a code here again.
     */
    public const REFUND_EVENT_CODES = ['T1107'];

    /**
     * Human-readable "Art" per PayPal T-code group (first 3 chars, e.g. "T04").
     * Groups are stable in PayPal's reference; classifying by group avoids
     * having to enumerate every individual code.
     */
    public const TYPE_PREFIX_LABELS = [
        'T00' => 'Zahlung',
        'T01' => 'Gebühr',
        'T02' => 'Währungsumrechnung',
        'T03' => 'Einzahlung',
        'T04' => 'Auszahlung',
        'T05' => 'Kartenzahlung',
        'T11' => 'Rückzahlung/Storno',
        'T12' => 'Korrektur',
        'T20' => 'Auszahlung',
        'T21' => 'Reserve/Hold',
    ];

    /**
     * T-code groups that are pure account-ledger movements (bank withdrawals
     * T04xx, payouts T20xx, fund holds/reserves/releases T21xx), NOT distinct
     * customer sales or refunds: a hold is placed/released against a sale that
     * was already counted, and a withdrawal just moves already-earned balance
     * to a bank account. They carry a (sometimes large) amount but no fee of
     * their own, so summing them into gross/fee/net revenue corrupts the fee
     * ratio and average-basket figures. Excluded from all revenue/report
     * figures via scopeExcludingLedgerEvents(). Classifying by group (rather
     * than a hand-maintained code list) also caught codes like T2107 that an
     * explicit list had missed.
     */
    public const LEDGER_ONLY_PREFIXES = ['T04', 'T20', 'T21'];

    protected $fillable = [
        'paypal_account_id',
        'event_id',
        'assignment_method',
        'assignment_rule_id',
        'assigned_at',
        'transaction_id',
        'paypal_reference_id',
        'paypal_reference_id_type',
        'invoice_id',
        'custom_field',
        'transaction_event_code',
        'transaction_status',
        'transaction_initiation_date',
        'transaction_updated_date',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'currency',
        'payer_name',
        'payer_email',
        'payer_country_code',
        'payment_method_type',
        'instrument_type',
        'protection_eligibility',
        'subject',
        'note',
        'item_info',
        'raw_payload',
        'raw_hash',
        'dedupe_key',
        'imported_at',
        'last_seen_at',
        'marked_irrelevant_at',
        'irrelevant_reason',
        'irrelevant_marked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_initiation_date' => 'datetime',
            'transaction_updated_date' => 'datetime',
            'assigned_at' => 'datetime',
            'imported_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'marked_irrelevant_at' => 'datetime',
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'item_info' => 'array',
            'raw_payload' => 'array',
        ];
    }

    /**
     * Transactions are never deletable, under any circumstances - "marking as
     * irrelevant" (see markIrrelevant()) is the only supported way to exclude
     * one from revenue figures, and it is fully audit-logged and reversible.
     * This blocks $transaction->delete()/forceDelete()/deleteOrFail()/
     * deleteQuietly() (all of which route through delete() in Eloquent) and
     * Transaction::destroy($ids). It does NOT block a raw
     * Transaction::query()->delete() bulk query, which bypasses model
     * instantiation entirely - no code in this app does that, and it must
     * stay that way.
     */
    public function delete(): bool
    {
        throw new \RuntimeException('Transaktionen dürfen nicht gelöscht werden - nur als nicht relevant markiert.');
    }

    public function forceDelete(): bool
    {
        throw new \RuntimeException('Transaktionen dürfen nicht gelöscht werden - nur als nicht relevant markiert.');
    }

    public function paypalAccount(): BelongsTo
    {
        return $this->belongsTo(PaypalAccount::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function assignmentRule(): BelongsTo
    {
        return $this->belongsTo(EventAssignmentRule::class, 'assignment_rule_id');
    }

    public function irrelevantMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'irrelevant_marked_by_user_id');
    }

    public function isRefundOrReversal(): bool
    {
        return in_array($this->transaction_event_code, self::REFUND_EVENT_CODES, true);
    }

    /**
     * The 3-char PayPal T-code group (e.g. "T04"), or null when there is no
     * event code (e.g. some CSV-imported rows).
     */
    public function eventCodeGroup(): ?string
    {
        return $this->transaction_event_code
            ? substr($this->transaction_event_code, 0, 3)
            : null;
    }

    /**
     * Human-readable "Art" of the transaction: Zahlung / Rückzahlung /
     * Auszahlung / Reserve / … derived from the T-code group. This is what the
     * transactions table shows so that e.g. a large negative bank withdrawal
     * (T0400) is clearly an "Auszahlung", not mistaken for a refund.
     */
    public function typeLabel(): string
    {
        $group = $this->eventCodeGroup();

        if ($group === null) {
            return '–';
        }

        return self::TYPE_PREFIX_LABELS[$group] ?? 'Sonstige';
    }

    public function isLedgerEvent(): bool
    {
        return in_array($this->eventCodeGroup(), self::LEDGER_ONLY_PREFIXES, true);
    }

    public function scopeExcludingLedgerEvents(Builder $query): Builder
    {
        // Keep rows whose code is NULL (e.g. CSV imports - can't be classified,
        // so must not be dropped) OR whose code is in no ledger-only group.
        return $query->where(function (Builder $q) {
            $q->whereNull('transaction_event_code')
                ->orWhere(function (Builder $q2) {
                    foreach (self::LEDGER_ONLY_PREFIXES as $prefix) {
                        $q2->where('transaction_event_code', 'not like', $prefix.'%');
                    }
                });
        });
    }

    /**
     * Filters to a single "Art" (see typeLabel()) by its T-code group(s).
     */
    public function scopeOfType(Builder $query, string $label): Builder
    {
        $prefixes = array_keys(self::TYPE_PREFIX_LABELS, $label, true);

        return $query->where(function (Builder $q) use ($prefixes, $label) {
            foreach ($prefixes as $prefix) {
                $q->orWhere('transaction_event_code', 'like', $prefix.'%');
            }

            if ($label === 'Sonstige') {
                $q->orWhere(function (Builder $q2) {
                    $q2->whereNotNull('transaction_event_code');
                    foreach (array_keys(self::TYPE_PREFIX_LABELS) as $known) {
                        $q2->where('transaction_event_code', 'not like', $known.'%');
                    }
                });
            }
        });
    }

    public function isAssigned(): bool
    {
        return $this->event_id !== null;
    }

    public function isIrrelevant(): bool
    {
        return $this->marked_irrelevant_at !== null;
    }

    /**
     * Excludes a transaction from revenue/report figures without ever deleting
     * it. Always audit-logged (who, when, why) via Spatie Activitylog -
     * see App\Models\AuditLogEntry for why that log is itself undeletable.
     */
    public function markIrrelevant(User $user, string $reason): void
    {
        $this->forceFill([
            'marked_irrelevant_at' => now(),
            'irrelevant_reason' => $reason,
            'irrelevant_marked_by_user_id' => $user->id,
        ])->save();

        activity()
            ->causedBy($user)
            ->performedOn($this)
            ->withProperties(['reason' => $reason, 'transaction_id' => $this->transaction_id])
            ->log('Transaktion als nicht relevant markiert');
    }

    public function markRelevant(User $user, string $reason): void
    {
        $this->forceFill([
            'marked_irrelevant_at' => null,
            'irrelevant_reason' => null,
            'irrelevant_marked_by_user_id' => null,
        ])->save();

        activity()
            ->causedBy($user)
            ->performedOn($this)
            ->withProperties(['reason' => $reason, 'transaction_id' => $this->transaction_id])
            ->log('Transaktion wieder als relevant markiert');
    }

    public function scopeExcludingIrrelevant(Builder $query): Builder
    {
        return $query->whereNull('marked_irrelevant_at');
    }
}
