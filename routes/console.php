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
// withoutOverlapping expiry kept SHORT (10 min): the command only dispatches a
// queue job and finishes in ~1s, so a lock held longer than that means the
// scheduler process was killed mid-run (container recreation / Watchtower race
// on the shared host). Laravel's 24h default would then stall PayPal sync for a
// full day; 10 min lets it self-heal on the next tick.
Schedule::command('paypal:schedule-sync')->everyMinute()->withoutOverlapping(10);

// Keeps pretix orders, bookings and the PayPal reconciliation fresh without
// manual "Import & Abgleich" clicks. 30 minutes is plenty for billing data;
// the job's own guard prevents overlapping runs per connection.
Schedule::command('pretix:schedule-import')->everyThirtyMinutes()->withoutOverlapping(20);

// Once a day: warn admins if the nightly backup marker is missing or stale.
Schedule::command('backup:check')->dailyAt('09:00');

// Every few hours: alert admins about newly seen open PayPal disputes so they
// can respond before the buyer window closes (chargeback prevention).
Schedule::command('disputes:check')->everySixHours()->withoutOverlapping(30);

// Daily bank pull via FinTS/HBCI (+ re-auth warning). No-op unless an active
// FinTS bank connection is set up - and, since FinTS is switched off by default,
// normally a no-op that says so once in the log (see config/bank.php).
Schedule::command('bank:sync')->dailyAt('06:30')->withoutOverlapping(30);

// Bank pull via Enable Banking (PSD2) - the path that needs no DK product
// registration. No-op unless a bank was connected.
//
// FOUR TIMES A DAY, AND THAT NUMBER IS THE RULE, not a preference: PSD2 grants an
// aggregator four accesses per account per day WITHOUT the account holder present.
// Every six hours is exactly those four. Asking more often does not fetch more;
// the bank refuses, and once the quota is spent the feed is dead until midnight.
//
// The command additionally checks the gap to the last pull (Sync::tooSoon), because
// a restarted scheduler fires again on the next tick and would burn the quota on
// its own. A manual "Jetzt abrufen" is attended and does not count.
//
// AT :36 rather than on the hour: the 06:30 FinTS run ends in the same import
// pipeline, and two concurrent runs would race on the dedupe hash of the very same
// transactions.
Schedule::command('enablebanking:sync')->cron('36 */6 * * *')->withoutOverlapping(30);

// Keep the error log from growing forever: drop resolved errors last seen more
// than 30 days ago (unresolved ones stay until handled).
Schedule::call(function () {
    \App\Models\ErrorLogEntry::where('resolved', true)
        ->where('last_seen_at', '<', now()->subDays(30))
        ->delete();
})->weekly();
