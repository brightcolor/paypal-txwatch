<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bank statement (Sparkasse CAMT.053 / MT940). Imported and
 * reconciled against PayPal payouts and pretix bank transfers. Purely
 * reference/diagnostic data - may be deleted, unlike PayPal transactions.
 */
class BankTransaction extends Model
{
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_IGNORED = 'ignored';

    public const METHOD_PAYOUT = 'payout';
    public const METHOD_PRETIX = 'pretix';
    public const METHOD_MANUAL = 'manual';

    protected $fillable = [
        'booked_on', 'valued_on', 'amount', 'currency', 'purpose',
        'counterparty_name', 'counterparty_iban', 'end_to_end_id', 'bank_ref',
        'source_format', 'import_hash', 'reconciliation_status',
        'matched_transaction_id', 'match_method', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'valued_on' => 'date',
            'amount' => 'decimal:2',
            'raw' => 'array',
        ];
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id');
    }

    public function isCredit(): bool
    {
        return (float) $this->amount > 0;
    }

    public function isMatched(): bool
    {
        return $this->reconciliation_status === self::STATUS_MATCHED;
    }
}
