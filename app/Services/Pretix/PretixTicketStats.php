<?php

namespace App\Services\Pretix;

use App\Models\PretixConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates ticket capacity/sold/available per event for a pretix connection
 * by calling the quota-availability endpoint per event. Results are cached
 * (live external API) and can be force-refreshed from the page.
 */
class PretixTicketStats
{
    private const TTL_SECONDS = 600;

    /**
     * @return Collection<int, array{slug: string, name: string, capacity: ?int, sold: int, available: int, unlimited: bool, ratio: ?float}>
     */
    public function forConnection(PretixConnection $connection, bool $fresh = false): Collection
    {
        $key = "pretix_ticket_stats:{$connection->id}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL_SECONDS, function () use ($connection) {
            $client = new PretixClient($connection);

            return collect($client->events())
                ->map(function (array $event) use ($client) {
                    $avail = $client->ticketAvailability($event['slug']);
                    $capacity = $avail['capacity'];

                    return [
                        'slug' => $event['slug'],
                        'name' => $event['name'] ?? $event['slug'],
                        'capacity' => $capacity,
                        'sold' => $avail['sold'],
                        'available' => $avail['available'],
                        'unlimited' => $avail['unlimited'],
                        'ratio' => ($capacity && $capacity > 0) ? round($avail['sold'] / $capacity * 100, 1) : null,
                    ];
                })
                ->values();
        });
    }
}
