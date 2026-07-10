<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    /**
     * PayPal transaction event codes observed to correlate with a
     * refund/reversal (negative gross_amount) in real account data. NOTE:
     * T0006 was previously included here on the (wrong) assumption that it
     * meant "refund" - it is in fact PayPal's generic "Payment" code and
     * matched ~99% of a real account's ordinary transactions, making the
     * refund filter/report/dashboard stat effectively meaningless. Verify
     * any additions against real negative-gross_amount rows before adding
     * a code here again.
     */
    public const REFUND_EVENT_CODES = ['T0400', 'T1107'];

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
        return (float) $this->gross_amount < 0
            || in_array($this->transaction_event_code, self::REFUND_EVENT_CODES, true);
    }

    public function isAssigned(): bool
    {
        return $this->event_id !== null;
    }
}
