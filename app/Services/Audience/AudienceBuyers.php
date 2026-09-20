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
     * A slug without an Event record stays visible as the slug: an order for an
     * event nobody set up yet is still a purchase, and dropping it would make the
     * count in the list disagree with the names beside it.
     *
     * @return array<int, string>
     */
    public function eventNames(AudienceQuery $query, string $email): array
    {
        $slugs = $query->buyers()
            ->where('buyer_email', $email)
            ->distinct()
            ->orderBy('event_slug')
            ->pluck('event_slug')
            ->all();

        $namen = Event::namesBySlug();

        return array_map(fn (string $slug) => $namen[$slug] ?? $slug, $slugs);
    }
}
