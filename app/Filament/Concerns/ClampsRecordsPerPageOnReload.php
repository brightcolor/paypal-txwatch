<?php

namespace App\Filament\Concerns;

/**
 * Big page sizes (> 200 rows) are opt-in per visit: the pagination guard
 * (resources/views/filament/adminlte-theme) makes the user confirm a warning
 * before a 500-row load. Without this trait the choice would be persisted in
 * the session and re-applied on every reload/revisit, so each page load would
 * re-run the heavy query and "we'd go in circles". This overrides the restore
 * path only: on mount we clamp a remembered value back down to 200. Picking a
 * large size still works for the current view (that goes through
 * updatedTableRecordsPerPage, not this method); it just never survives a reload.
 */
trait ClampsRecordsPerPageOnReload
{
    public function getDefaultTableRecordsPerPageSelectOption(): int | string
    {
        $option = parent::getDefaultTableRecordsPerPageSelectOption();

        if (is_numeric($option) && (int) $option > 200) {
            return 200;
        }

        return $option;
    }
}
