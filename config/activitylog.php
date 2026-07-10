<?php

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted. NOTE: this app never
     * schedules `activitylog:clean` (see routes/console.php / bootstrap/app.php)
     * precisely because the audit trail must never be deleted - see
     * App\Models\AuditLogEntry, which is the model actually used (below) and
     * throws on delete()/forceDelete() as a second line of defense even if
     * this command is ever invoked by mistake.
     */
    'delete_records_older_than_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     */
    'subject_returns_soft_deleted_models' => false,

    /*
     * App\Models\AuditLogEntry extends the package's Activity model and
     * overrides delete()/forceDelete() to always throw, making the audit
     * trail append-only at the application layer.
     */
    'activity_model' => \App\Models\AuditLogEntry::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
