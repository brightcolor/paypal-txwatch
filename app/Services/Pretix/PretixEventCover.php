<?php

namespace App\Services\Pretix;

use App\Models\Event;
use App\Models\PretixOrder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Assembles the live pretix data for an export cover page: event facts (date,
 * admission, venue, presale window), capacity/utilisation, and the
 * "Gästebilanz" - per ticket category how many guests booked and how many
 * actually showed up (check-ins), plus totals and the show-up rate.
 *
 * Deliberately fault-tolerant: any API problem returns null and the export
 * falls back to the plain local cover instead of failing.
 */
class PretixEventCover
{
    private const TTL_SECONDS = 600;

    public function forEvent(Event $event, bool $fresh = false): ?array
    {
        if (blank($event->pretix_event_slug)) {
            return null;
        }

        $key = "pretix_event_cover:{$event->id}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::TTL_SECONDS, function () use ($event) {
            try {
                return $this->build($event);
            } catch (Throwable) {
                return null;
            }
        });
    }

    private function build(Event $event): ?array
    {
        $slug = $event->pretix_event_slug;

        // The connection is not stored on the event; resolve it via the
        // imported orders of that slug.
        $connection = PretixOrder::query()
            ->where('event_slug', $slug)
            ->with('connection')
            ->first()?->connection;

        if (! $connection) {
            return null;
        }

        $client = new PretixClient($connection);

        $details = $client->eventDetails($slug);
        if (! $details) {
            return null;
        }

        $items = $client->items($slug);
        $attendance = $client->attendanceByItem($slug);
        $availability = $client->ticketAvailability($slug);

        $categories = collect($attendance)
            ->map(fn (array $row) => [
                'name' => $items[$row['item']] ?? ('Kategorie #' . $row['item']),
                'booked' => $row['booked'],
                'attended' => $row['attended'],
                'revenue' => round($row['revenue'], 2),
                'ratio' => $row['booked'] > 0 ? round($row['attended'] / $row['booked'] * 100, 1) : null,
            ])
            ->sortByDesc('booked')
            ->values()
            ->all();

        $booked = array_sum(array_column($categories, 'booked'));
        $attended = array_sum(array_column($categories, 'attended'));

        return [
            'details' => $details,
            'capacity' => $availability,
            'categories' => $categories,
            'totals' => [
                'booked' => $booked,
                'attended' => $attended,
                'no_shows' => max(0, $booked - $attended),
                'show_up_ratio' => $booked > 0 ? round($attended / $booked * 100, 1) : null,
                'revenue' => round(array_sum(array_column($categories, 'revenue')), 2),
            ],
        ];
    }
}
