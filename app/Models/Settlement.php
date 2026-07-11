<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settlement extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'event_id', 'customer_id', 'title', 'period_from', 'period_to', 'vat_rate',
        'tx_count', 'gross', 'fees', 'payout', 'vat', 'net_excl_vat', 'blocks', 'events',
        'status', 'paid_at', 'paid_reference', 'note', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'vat_rate' => 'decimal:2',
            'gross' => 'decimal:2',
            'fees' => 'decimal:2',
            'payout' => 'decimal:2',
            'vat' => 'decimal:2',
            'net_excl_vat' => 'decimal:2',
            'blocks' => 'array',
            'events' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Rebuilds the array shape the settlement PDF blade consumes from this
     * frozen record - so the document renders identically regardless of later
     * transaction changes.
     */
    public function pdfData(): array
    {
        return [
            'event' => $this->event,
            'customer' => $this->customer,
            'title' => $this->title,
            'generated_at' => $this->created_at,
            'vat_rate' => (float) $this->vat_rate,
            'period' => ['from' => $this->period_from, 'to' => $this->period_to],
            'blocks' => $this->blocks,
            'events' => $this->events ?? [],
            'totals' => [
                'count' => $this->tx_count,
                'amount' => (float) $this->gross,
                'fees' => (float) $this->fees,
                'payout' => (float) $this->payout,
                'vat' => (float) $this->vat,
                'net_excl_vat' => (float) $this->net_excl_vat,
            ],
        ];
    }
}
