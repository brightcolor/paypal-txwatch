<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts notifications.data from text to jsonb on existing Postgres databases.
 * The original migration created it as text, which makes Filament's
 * `data->>'format'` filter 500 with SQLSTATE 42883 (no ->> operator on text).
 * Fresh installs already get json from the create migration; this only repairs
 * databases that ran the old one. Idempotent: casting json/jsonb->jsonb is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            // USING clause is required to reinterpret the existing text as json.
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
        }
        // SQLite/MySQL store json as text-ish and support the operator already;
        // no structural change needed there.
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('notifications')) {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
    }
};
