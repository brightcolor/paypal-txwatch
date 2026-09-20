<?php

namespace App\Services\Audience;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The headline figures of a selection.
 */
class AudienceStats
{
    /**
     * @return array{buyers: int, tickets: int, orders: int, revenue: float, tickets_without_buyer: int, multi_event_buyers: int, returning_share: float}
     */
    public function forSelection(AudienceQuery $query): array
    {
        $kaeufer = (int) $query->buyers()->distinct()->count('buyer_email');
        $mehrfach = $this->multiEventBuyers($query);

        return [
            'buyers' => $kaeufer,
            'tickets' => (int) $query->positions()->count(),
            'orders' => $this->countOrders($query->positions()),
            'revenue' => (float) $query->positions()->sum('price'),
            'tickets_without_buyer' => (int) $query->positions()->whereNull('buyer_email')->count(),
            'multi_event_buyers' => $mehrfach,
            'returning_share' => $kaeufer > 0 ? round($mehrfach * 100 / $kaeufer, 1) : 0.0,
        ];
    }

    /**
     * Distinct orders behind a position query.
     *
     * OVER slug + code, because a pretix order code is unique within its event and
     * two events may well both have an order "GLEICH". `||` is the string
     * concatenation of SQLite and PostgreSQL alike.
     */
    private function countOrders(Builder $positions): int
    {
        return (int) $positions
            ->selectRaw("COUNT(DISTINCT event_slug || '/' || order_code) as anzahl")
            ->value('anzahl');
    }

    /** Buyers with tickets at more than one of the selected events. */
    private function multiEventBuyers(AudienceQuery $query): int
    {
        $gruppen = $query->buyers()
            ->select('buyer_email')
            ->groupBy('buyer_email')
            ->havingRaw('COUNT(DISTINCT event_slug) > 1');

        return (int) DB::query()->fromSub($gruppen, 'mehrfach')->count();
    }
}
