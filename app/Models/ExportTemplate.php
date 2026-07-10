<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportTemplate extends Model
{
    use HasFactory;

    public const MODE_CUSTOMER = 'customer';
    public const MODE_INTERNAL = 'internal';

    public const DEFAULT_COLUMNS = [
        'date', 'transaction_id', 'name', 'email', 'event_ref', 'custom_field',
        'invoice_id', 'status', 'gross', 'vat', 'fee', 'net', 'currency',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'columns',
        'group_by',
        'show_group_sums',
        'show_grand_total',
        'mode',
        'mask_pii',
        'title',
        'subtitle',
        'description',
        'show_event_info',
        'footer_note',
        'vat_rate',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'show_group_sums' => 'boolean',
            'show_grand_total' => 'boolean',
            'mask_pii' => 'boolean',
            'show_event_info' => 'boolean',
            'vat_rate' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
