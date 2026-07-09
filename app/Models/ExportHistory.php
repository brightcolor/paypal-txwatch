<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportHistory extends Model
{
    use HasFactory;

    protected $table = 'export_history';

    protected $fillable = [
        'user_id',
        'export_template_id',
        'format',
        'filters_snapshot',
        'file_path',
        'row_count',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters_snapshot' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exportTemplate(): BelongsTo
    {
        return $this->belongsTo(ExportTemplate::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
