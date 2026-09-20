<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One active ticket position of one pretix order.
 *
 * Derived data owned by pretix: rewritten on every import, never edited here. No
 * audit log for the same reason - an entry would record a copy, not a decision.
 */
class PretixPosition extends Model
{
    protected $fillable = [
        'pretix_connection_id',
        'pretix_order_id',
        'event_slug',
        'order_code',
        'order_status',
        'payment_provider',
        'buyer_email',
        'buyer_name',
        'position_id',
        'item_id',
        'price',
        'voucher',
        'attendee_name',
        'zipcode',
        'city',
        'country',
        'ordered_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'ordered_at' => 'datetime',
            'position_id' => 'integer',
            'item_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PretixOrder::class, 'pretix_order_id');
    }
}
