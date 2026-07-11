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
