<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * Replaces Spatie's default Activity model (see config/activitylog.php) so the
 * audit trail is append-only at the application layer: no delete, no
 * force-delete, from anywhere in the app - not even Spatie's own
 * `activitylog:clean` command (which deletes entries older than
 * activitylog.delete_records_older_than_days by default) can remove a row,
 * since it goes through this same model. This is intentional and must not be
 * relaxed: audit entries need to outlive whatever they document.
 */
class AuditLogEntry extends Activity
{
    public function delete(): bool
    {
        throw new \RuntimeException('Audit-Log-Einträge dürfen nicht gelöscht werden.');
    }

    public function forceDelete(): bool
    {
        throw new \RuntimeException('Audit-Log-Einträge dürfen nicht gelöscht werden.');
    }
}
