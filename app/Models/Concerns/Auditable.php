<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Shared audit wiring for user-driven config models. Each model declares which
 * attributes are worth auditing via the static $auditAttributes list; only
 * those are watched, so machine writes to other columns (e.g. the importer
 * bumping last_successful_sync_at) never flood the audit trail. Secrets are
 * simply left out of the list so their values are never written to the log.
 *
 * Spatie fills the causer from the authenticated user automatically, so panel
 * actions are attributed to whoever performed them. Entries land in the
 * append-only App\Models\AuditLogEntry (see config/activitylog.php).
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(static::$auditAttributes ?? [])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(static::$auditLogName ?? 'config')
            ->setDescriptionForEvent(fn (string $event) => static::auditLabel() . ' ' . match ($event) {
                'created' => 'angelegt',
                'updated' => 'geändert',
                'deleted' => 'gelöscht',
                default => $event,
            });
    }

    /** Human label for the model in audit descriptions. */
    protected static function auditLabel(): string
    {
        return class_basename(static::class);
    }
}
