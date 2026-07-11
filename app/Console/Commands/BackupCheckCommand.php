<?php

namespace App\Console\Commands;

use App\Support\AdminNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Warns admins when the nightly backup hasn't run recently. backup.sh writes a
 * unix timestamp to storage/app/last-backup-at (bind-mounted, so the container
 * sees it); this checks its age daily. A missing/stale marker is exactly the
 * "my backups silently stopped" failure that otherwise goes unnoticed.
 */
#[Signature('backup:check')]
#[Description('Alert admins if the last backup is missing or older than 36 hours.')]
class BackupCheckCommand extends Command
{
    public function handle(): int
    {
        $path = storage_path('app/last-backup-at');
        $maxAgeHours = 36;

        $ts = is_readable($path) ? (int) trim((string) @file_get_contents($path)) : 0;
        $ageHours = $ts > 0 ? (now()->timestamp - $ts) / 3600 : null;

        if ($ageHours === null) {
            AdminNotifier::warn('Backup-Warnung', 'Es wurde noch kein Backup-Zeitstempel gefunden – läuft das nächtliche Backup (docker/backup.sh via Cron)?');
            $this->warn('Kein Backup-Marker gefunden.');

            return self::SUCCESS;
        }

        if ($ageHours > $maxAgeHours) {
            AdminNotifier::warn('Backup-Warnung', 'Das letzte Backup ist ' . round($ageHours) . ' Stunden alt (Schwelle ' . $maxAgeHours . 'h). Bitte Backup-Cron prüfen.');
            $this->warn('Backup veraltet: ' . round($ageHours) . 'h');

            return self::SUCCESS;
        }

        $this->info('Backup aktuell (' . round($ageHours, 1) . 'h alt).');

        return self::SUCCESS;
    }
}
