<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAssignmentRule extends Model
{
    use HasFactory;

    public const TYPE_CUSTOM_FIELD_CONTAINS = 'custom_field_contains';
    public const TYPE_CUSTOM_FIELD_REGEX = 'custom_field_regex';
    public const TYPE_INVOICE_ID_CONTAINS = 'invoice_id_contains';
    public const TYPE_INVOICE_ID_REGEX = 'invoice_id_regex';
    public const TYPE_AMOUNT_RANGE = 'amount_range';
    public const TYPE_DATE_RANGE = 'date_range';
    public const TYPE_PAYPAL_ACCOUNT = 'paypal_account';

    protected $fillable = [
        'event_id',
        'match_type',
        'pattern',
        'case_sensitive',
        'amount_min',
        'amount_max',
        'date_from',
        'date_to',
        'paypal_account_id',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'case_sensitive' => 'boolean',
            'is_active' => 'boolean',
            'amount_min' => 'decimal:2',
            'amount_max' => 'decimal:2',
            'date_from' => 'datetime',
            'date_to' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function paypalAccount(): BelongsTo
    {
        return $this->belongsTo(PaypalAccount::class);
    }

    /**
     * Does this rule match the given transaction attribute bag?
     * $data mirrors the normalized fields used when a Transaction is built.
     */
    public function matches(array $data): bool
    {
        return match ($this->match_type) {
            self::TYPE_CUSTOM_FIELD_CONTAINS => $this->stringContains($data['custom_field'] ?? null, $this->pattern),
            self::TYPE_CUSTOM_FIELD_REGEX => $this->regexMatches($data['custom_field'] ?? null, $this->pattern),
            self::TYPE_INVOICE_ID_CONTAINS => $this->stringContains($data['invoice_id'] ?? null, $this->pattern),
            self::TYPE_INVOICE_ID_REGEX => $this->regexMatches($data['invoice_id'] ?? null, $this->pattern),
            self::TYPE_AMOUNT_RANGE => $this->amountInRange($data['gross_amount'] ?? null),
            self::TYPE_DATE_RANGE => $this->dateInRange($data['transaction_initiation_date'] ?? null),
            self::TYPE_PAYPAL_ACCOUNT => (int) ($data['paypal_account_id'] ?? 0) === (int) $this->paypal_account_id,
            default => false,
        };
    }

    private function stringContains(?string $haystack, ?string $needle): bool
    {
        if ($haystack === null || $needle === null || $needle === '') {
            return false;
        }

        return $this->case_sensitive
            ? str_contains($haystack, $needle)
            : str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    private function regexMatches(?string $subject, ?string $pattern): bool
    {
        if ($subject === null || $pattern === null || $pattern === '') {
            return false;
        }

        $delimiter = $this->case_sensitive ? '/u' : '/iu';

        return @preg_match('/' . str_replace('/', '\/', $pattern) . $delimiter, $subject) === 1;
    }

    private function amountInRange($amount): bool
    {
        if ($amount === null) {
            return false;
        }

        $amount = (float) $amount;

        if ($this->amount_min !== null && $amount < (float) $this->amount_min) {
            return false;
        }

        if ($this->amount_max !== null && $amount > (float) $this->amount_max) {
            return false;
        }

        return true;
    }

    private function dateInRange($date): bool
    {
        if ($date === null) {
            return false;
        }

        $date = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable($date);

        if ($this->date_from && $date < $this->date_from) {
            return false;
        }

        if ($this->date_to && $date > $this->date_to) {
            return false;
        }

        return true;
    }
}
