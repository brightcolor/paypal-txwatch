<?php

/*
 * pretix API behaviour. Each value is checked against its limits in
 * App\Services\Pretix\PretixSettings.
 */
return [
    /*
     * Seconds the ticket statistics of a connection stay cached (0 - 86400).
     * A failed call is never cached; 0 turns the cache off.
     */
    'ticket_stats_cache_seconds' => env('PRETIX_TICKET_STATS_CACHE_SECONDS', 600),

    /*
     * Seconds one call to the pretix API may take before it counts as a
     * timeout (1 - 300).
     */
    'http_timeout' => env('PRETIX_HTTP_TIMEOUT', 20),
];
