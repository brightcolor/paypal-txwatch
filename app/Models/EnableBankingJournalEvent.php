<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the protocol of a journal entry: what the pull delivered, what the
 * recognition made of it, and what changed later.
 *
 * APPEND-ONLY BY INTENT. `updated_at` and `created_at` do not exist here - there is
 * only `at`, the moment the thing happened. A protocol line that can be changed
 * afterwards is not a protocol.
 */
class EnableBankingJournalEvent extends Model
{
    protected $table = 'enable_banking_journal_events';

    /** No Eloquent timestamps: `at` is the only time this table knows. */
    public $timestamps = false;

    protected $fillable = ['journal_entry_id', 'kind', 'message', 'context', 'at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(EnableBankingJournalEntry::class, 'journal_entry_id');
    }

    /** For the UI: colour by what happened, not by severity. */
    public function color(): string
    {
        return match ($this->kind) {
            'matched', 'promoted' => 'success',
            'suggested', 'changed' => 'warning',
            'unmatched' => 'gray',
            default => 'info',
        };
    }

    public function label(): string
    {
        return match ($this->kind) {
            'pulled' => 'Abgerufen',
            'matched' => 'Zugeordnet',
            'suggested' => 'Vorschlag',
            'unmatched' => 'Keine Zuordnung',
            'changed' => 'Geändert',
            'promoted' => 'Übernommen',
            default => $this->kind,
        };
    }
}
