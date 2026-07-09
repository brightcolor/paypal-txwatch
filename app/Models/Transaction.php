<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

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
            || in_array($this->transaction_event_code, ['T1107', 'T1108', 'T0006'], true);
    }

    public function isAssigned(): bool
    {
        return $this->event_id !== null;
    }
}
