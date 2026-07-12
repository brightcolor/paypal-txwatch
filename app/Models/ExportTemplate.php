<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportTemplate extends Model
{
    use HasFactory;
    use \App\Models\Concerns\Auditable;

    protected static array $auditAttributes = ['name', 'columns', 'group_by', 'mode', 'mask_pii', 'vat_rate', 'title', 'is_default', 'accent_color', 'filename_pattern'];

    protected static string $auditLogName = 'export-vorlage';

    protected static function auditLabel(): string
    {
        return 'Export-Vorlage';
    }

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
        'is_default',
        'accent_color',
        'filename_pattern',
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
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Exactly one default template: setting the flag unsets it everywhere else.
        static::saved(function (ExportTemplate $template) {
            if ($template->is_default) {
                static::query()->whereKeyNot($template->getKey())->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public static function defaultTemplate(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
