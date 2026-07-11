<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A captured server-side error (HTTP 5xx / unhandled exception). Unlike
 * transactions and audit entries, these are pure diagnostics and MAY be deleted
 * or pruned. Grouped by fingerprint so a recurring bug is one row with a running
 * occurrence count. Written by App\Support\ErrorLogger.
 */
class ErrorLogEntry extends Model
{
    protected $fillable = [
        'fingerprint', 'exception_class', 'message', 'file', 'line', 'status_code',
        'method', 'url', 'route', 'user_id', 'app_version', 'context', 'trace',
        'occurrences', 'resolved', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Short "File.php:123" location for tables/notifications. */
    public function shortLocation(): string
    {
        return $this->file ? basename($this->file) . ($this->line ? ':' . $this->line : '') : '–';
    }

    /** Class basename without namespace, e.g. "PDOException". */
    public function shortClass(): string
    {
        return class_basename($this->exception_class);
    }
}
