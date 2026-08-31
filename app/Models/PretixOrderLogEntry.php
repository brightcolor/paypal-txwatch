<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to one pretix order during an import.
 *
 * APPEND-ONLY: `$timestamps` is off, nothing updates or deletes these rows, and the
 * UI offers neither. They exist to answer "what became of this order", and a record
 * that can be corrected afterwards answers nothing.
 */
class PretixOrderLogEntry extends Model
{
    protected $table = 'pretix_order_log';

    /** The order came in and did not exist here before. */
    public const ACTION_NEW = 'found_new';

    /** The order came in and something about it differs from what we had. */
    public const ACTION_CHANGED = 'found_changed';

    /** The order came in unchanged - pretix returned it, nothing moved. */
    public const ACTION_UNCHANGED = 'found_unchanged';

    /** A transaction was written for it (bank transfer, manual, …). */
    public const ACTION_BOOKED = 'booked';

    /** Deliberately not booked, with a reason. */
    public const ACTION_SKIPPED = 'skipped';

    /** Matched against a PayPal transaction - or explicitly not. */
    public const ACTION_RECONCILED = 'reconciled';

    /**
     * The opening line for an order that predates this recording.
     *
     * Says what is KNOWN now, and says plainly that what came before was not
     * recorded. A history that quietly starts in the middle is worse than one that
     * names its own beginning.
     */
    public const ACTION_BASELINE = 'baseline';

    public $timestamps = false;

    protected $fillable = [
        'pretix_import_run_id', 'pretix_connection_id', 'event_slug', 'order_code',
        'pretix_order_id', 'action', 'status_before', 'status_after', 'total',
        'message', 'context', 'at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'context' => 'array',
            'at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PretixOrder::class, 'pretix_order_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PretixImportRun::class, 'pretix_import_run_id');
    }

    /** One short phrase for the kind of thing that happened. */
    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_NEW => 'neu gefunden',
            self::ACTION_CHANGED => 'geändert',
            self::ACTION_UNCHANGED => 'unverändert',
            self::ACTION_BOOKED => 'verbucht',
            self::ACTION_SKIPPED => 'nicht verbucht',
            self::ACTION_RECONCILED => 'abgeglichen',
            self::ACTION_BASELINE => 'Bestand aufgenommen',
            default => (string) $this->action,
        };
    }

    /**
     * The pretix status in words.
     *
     * Kept here rather than in three views: the same four letters are shown in the
     * order list, in this history and in the import run, and three copies of the
     * mapping would eventually disagree.
     */
    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'n' => 'offen',
            'p' => 'bezahlt',
            'e' => 'abgelaufen',
            'c' => 'storniert',
            null, '' => '–',
            default => $status,
        };
    }
}
