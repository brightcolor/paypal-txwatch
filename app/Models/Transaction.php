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
     * sale, per PayPal's official T-code reference (see LEDGER_ONLY_EVENT_CODES
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
     * PayPal event codes for pure account-ledger events (bank withdrawals,
     * fund holds/releases) that the Transaction Search API reports as
     * "transactions" but that do not represent a distinct customer sale or
     * refund: a hold is placed/released against an existing sale that was
     * already counted once, and a withdrawal just moves already-earned
     * balance to a bank account. Summing these into gross/fee/net revenue
     * corrupts the fee ratio and average-basket figures, since they carry a
     * (sometimes large) amount but no processing fee of their own.
     *
     * T0400/T0401/T0403 - general/AutoSweep/user-initiated bank withdrawal
     * T2101             - general hold placed on funds
     * T2102/T2108       - general/payment hold release
     */
    public const LEDGER_ONLY_EVENT_CODES = ['T0400', 'T0401', 'T0403', 'T2101', 'T2102', 'T2108'];

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
    ];

    protected function casts(): array
    {
        return [
            'transaction_initiation_date' => 'datetime',
            'transaction_updated_date' => 'datetime',
            'assigned_at' => 'datetime',
            'imported_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'item_info' => 'array',
            'raw_payload' => 'array',
        ];
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

    public function isRefundOrReversal(): bool
    {
        return in_array($this->transaction_event_code, self::REFUND_EVENT_CODES, true);
    }

    public function isLedgerEvent(): bool
    {
        return in_array($this->transaction_event_code, self::LEDGER_ONLY_EVENT_CODES, true);
    }

    public function scopeExcludingLedgerEvents(Builder $query): Builder
    {
        // whereNotIn alone would also drop rows with a NULL transaction_event_code
        // (e.g. CSV-imported transactions without one) due to SQL's NULL semantics -
        // those can't be identified as ledger-only, so they must be kept.
        return $query->where(function (Builder $q) {
            $q->whereNull('transaction_event_code')
                ->orWhereNotIn('transaction_event_code', self::LEDGER_ONLY_EVENT_CODES);
        });
    }

    public function isAssigned(): bool
    {
        return $this->event_id !== null;
    }
}
