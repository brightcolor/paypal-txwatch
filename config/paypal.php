<?php

return [
    /*
     * PayPal REST API base URLs. Each PaypalAccount chooses sandbox or live.
     */
    'endpoints' => [
        'sandbox' => 'https://api-m.sandbox.paypal.com',
        'live' => 'https://api-m.paypal.com',
    ],

    /*
     * Transaction Search API hard limits (see PayPal docs).
     */
    'max_window_days' => 31,
    'max_page_size' => (int) env('PAYPAL_SYNC_MAX_PAGE_SIZE', 500),
    'max_records_per_window' => (int) env('PAYPAL_SYNC_MAX_RECORDS_PER_WINDOW', 10000),

    /*
     * PayPal can report transactions up to ~3h late. Every scheduled sync
     * re-checks this many hours before "now" to catch late-arriving records.
     */
    'default_lookback_hours' => (int) env('PAYPAL_SYNC_LOOKBACK_HOURS', 4),

    /*
     * When a search window returns RESULTSET_TOO_LARGE, split it by these
     * step sizes in order until the window is small enough.
     */
    'window_split_steps' => [
        'P7D',   // 7 days
        'P1D',   // 1 day
        'PT6H',  // 6 hours
        'PT1H',  // 1 hour
    ],

    /*
     * A sync-enabled account is flagged as "overdue" in the UI when no
     * successful sync happened within this many hours (floor - scales up
     * with the account's own interval, see PaypalAccount::isSyncOverdue()).
     */
    'sync_warning_threshold_hours' => (int) env('PAYPAL_SYNC_WARNING_THRESHOLD_HOURS', 2),

    /*
     * HTTP client behaviour.
     */
    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry_times' => 3,
        'retry_sleep_ms' => 1000,
    ],
];
