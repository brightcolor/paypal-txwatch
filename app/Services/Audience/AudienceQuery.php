<?php

namespace App\Services\Audience;

use App\Models\PretixOrder;
use App\Models\PretixPosition;
use App\Support\CustomerScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The current selection of the audience screen, as one object.
 *
 * EVERY audience figure goes through here, and the customer scope is applied in
 * this one place. A check that sat on the screen instead would let any widget added
 * later reach past it - and a leak of one promoter's buyers into another promoter's
 * view is the one mistake this feature must not make.
 */
class AudienceQuery
{
    /** @var array<int, string> */
    public readonly array $eventSlugs;

    /** @var array<int, string> */
    public readonly array $statuses;

    /** @var array<int, int> */
    public readonly array $itemIds;

    public function __construct(
        array $eventSlugs = [],
        array $statuses = ['p'],
        array $itemIds = [],
        public readonly ?CarbonInterface $from = null,
        public readonly ?CarbonInterface $until = null,
    ) {
        $this->eventSlugs = array_values(array_filter(array_map('strval', $eventSlugs), fn ($s) => $s !== ''));
        $this->statuses = array_values(array_filter(array_map('strval', $statuses), fn ($s) => $s !== ''));
        $this->itemIds = array_values(array_map('intval', $itemIds));
    }

    /** The positions of the current selection. */
    public function positions(): Builder
    {
        $query = $this->visiblePositions();

        if ($this->eventSlugs !== []) {
            $query->whereIn('event_slug', $this->eventSlugs);
        }

        if ($this->statuses !== []) {
            $query->whereIn('order_status', $this->statuses);
        }

        if ($this->itemIds !== []) {
            $query->whereIn('item_id', $this->itemIds);
        }

        return $this->betweenDates($query, 'ordered_at');
    }

    /** The positions the user may see at all, ignoring the selection. */
    public function visiblePositions(): Builder
    {
        return CustomerScope::byEventSlug(PretixPosition::query());
    }

    /** The positions of the selection that carry a buyer. */
    public function buyers(): Builder
    {
        return $this->positions()->whereNotNull('buyer_email');
    }

    /** The orders of the selection. The ticket type filter cannot apply here. */
    public function orders(): Builder
    {
        $query = $this->ordersAnyStatus();

        if ($this->statuses !== []) {
            $query->whereIn('status', $this->statuses);
        }

        return $query;
    }

    /**
     * The orders of the selection, whatever their status.
     *
     * Needed by the cancellation rate: with the default filter on "paid" a cancelled
     * order would be filtered away before it could be counted as cancelled.
     */
    public function ordersAnyStatus(): Builder
    {
        $query = CustomerScope::byEventSlug(PretixOrder::query());

        if ($this->eventSlugs !== []) {
            $query->whereIn('event_slug', $this->eventSlugs);
        }

        return $this->betweenDates($query, 'order_datetime');
    }

    /**
     * The date range covers whole days: "until 6 July" includes an order at 23:30
     * that evening, which a plain comparison against midnight would drop.
     */
    private function betweenDates(Builder $query, string $column): Builder
    {
        if ($this->from !== null) {
            $query->where($column, '>=', $this->from->copy()->startOfDay());
        }

        if ($this->until !== null) {
            $query->where($column, '<=', $this->until->copy()->endOfDay());
        }

        return $query;
    }
}
