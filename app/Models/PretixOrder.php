<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PretixOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'pretix_connection_id',
        'event_slug',
        'order_code',
        'status',
        'payment_provider',
        'email',
        'total',
        'currency',
        'order_datetime',
        'url',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'order_datetime' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PretixConnection::class, 'pretix_connection_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isPaypal(): bool
    {
        return str_contains(strtolower((string) $this->payment_provider), 'paypal');
    }

    /**
     * Normalized key "eventslug/ordercode" used to match against a PayPal
     * transaction's parsed custom_field. Case-insensitive because the pretix
     * event slug is typically lowercase while PayPal's custom field is upper.
     */
    public static function matchKey(?string $eventSlug, ?string $orderCode): ?string
    {
        if (blank($eventSlug) || blank($orderCode)) {
            return null;
        }

        return strtolower(trim($eventSlug)) . '/' . strtolower(trim($orderCode));
    }

    public function ownMatchKey(): ?string
    {
        return self::matchKey($this->event_slug, $this->order_code);
    }
}
