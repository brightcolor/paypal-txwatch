<?php

namespace App\Services\Audience;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;

/**
 * The buyer list: one row per e-mail address, with their totals.
 *
 * RETURNS A BUILDER, not an array, so the Filament table can sort, search and
 * paginate in the database. `MIN(id)` is the record key the table needs - the group
 * itself has none.
 */
class AudienceBuyers
{
    public function query(AudienceQuery $query): Builder
    {
        return $query->buyers()
            ->selectRaw('MIN(id) as id')
            ->selectRaw('buyer_email')
            // One of the names on that address. They are the same in almost every
            // case; where they differ, the per-order names stay visible in the
            // participant export.
            ->selectRaw('MAX(buyer_name) as buyer_display_name')
            ->selectRaw('COUNT(*) as tickets')
            ->selectRaw('SUM(price) as revenue')
            ->selectRaw('COUNT(DISTINCT event_slug) as events')
            ->selectRaw("COUNT(DISTINCT event_slug || '/' || order_code) as orders")
            ->selectRaw('MIN(ordered_at) as first_at')
            ->selectRaw('MAX(ordered_at) as last_at')
            ->groupBy('buyer_email');
    }

    /**
     * Narrows the list by e-mail or name.
     *
     * LOWER() ON BOTH SIDES: `LIKE` is case sensitive on PostgreSQL and case
     * insensitive on SQLite, so a plain LIKE passes every test and finds nothing in
     * production. The filter goes into WHERE, before the grouping, which is exactly
     * where it belongs - a buyer matches when any of their rows matches.
     */
    public static function search(Builder $query, string $term): Builder
    {
        $muster = '%' . mb_strtolower(trim($term)) . '%';

        return $query->where(function (Builder $query) use ($muster) {
            $query->whereRaw('LOWER(buyer_email) LIKE ?', [$muster])
                ->orWhereRaw('LOWER(buyer_name) LIKE ?', [$muster]);
        });
    }

    /**
     * The events one buyer appears at, by name where an event is configured.
     *
     * @return array<int, string>
     */
    public function eventNames(AudienceQuery $query, string $email): array
    {
        return $this->eventNamesForBuyers($query, [$email])[$email] ?? [];
    }

    /**
     * The same, for many buyers in ONE query.
     *
     * ASKING PER BUYER IS THE TRAP: a table of 50 rows then costs 50 queries per
     * render, and the export one per person. Measured on production that was 100 of
     * the 184 queries a single page view made.
     *
     * A slug without an Event record stays visible as the slug: an order for an
     * event nobody set up yet is still a purchase, and dropping it would make the
     * count in the list disagree with the names beside it.
     *
     * @param  array<int, string>  $emails  empty means every buyer of the selection
     * @return array<string, array<int, string>>
     */
    public function eventNamesForBuyers(AudienceQuery $query, array $emails = []): array
    {
        $paare = $query->buyers()
            ->when($emails !== [], fn (Builder $q) => $q->whereIn('buyer_email', $emails))
            ->select('buyer_email', 'event_slug')
            ->distinct()
            ->orderBy('buyer_email')
            ->orderBy('event_slug')
            ->get();

        $namen = Event::namesBySlug();
        $proKaeufer = [];

        foreach ($paare as $paar) {
            $proKaeufer[$paar->buyer_email][] = $namen[$paar->event_slug] ?? $paar->event_slug;
        }

        return $proKaeufer;
    }
}
