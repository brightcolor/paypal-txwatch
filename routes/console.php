<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Checks every minute which PayPal accounts are due for a sync based on
// their individual sync_interval_minutes; actual dispatching happens
// inside the command so intervals stay per-account configurable.
Schedule::command('paypal:schedule-sync')->everyMinute()->withoutOverlapping();

// Keeps pretix orders, bookings and the PayPal reconciliation fresh without
// manual "Import & Abgleich" clicks. 30 minutes is plenty for billing data;
// the job's own guard prevents overlapping runs per connection.
Schedule::command('pretix:schedule-import')->everyThirtyMinutes()->withoutOverlapping();

// Once a day: warn admins if the nightly backup marker is missing or stale.
Schedule::command('backup:check')->dailyAt('09:00');

// Every few hours: alert admins about newly seen open PayPal disputes so they
// can respond before the buyer window closes (chargeback prevention).
Schedule::command('disputes:check')->everySixHours()->withoutOverlapping();

// Daily bank pull via GoCardless (+ consent-expiry warning). No-op unless a
// bank connection is set up.
Schedule::command('bank:sync')->dailyAt('06:30')->withoutOverlapping();

// Keep the error log from growing forever: drop resolved errors last seen more
// than 30 days ago (unresolved ones stay until handled).
Schedule::call(function () {
    \App\Models\ErrorLogEntry::where('resolved', true)
        ->where('last_seen_at', '<', now()->subDays(30))
        ->delete();
})->weekly();
