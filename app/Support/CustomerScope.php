<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Central server-side row scoping for the "customer" role: a customer user may
 * only ever see data belonging to their own customer_id. Applied to every query
 * that a customer can reach (transactions, reports, settlements) so scoping is
 * enforced in one place instead of being re-derived per resource. Admins,
 * managers and auditors are unaffected.
 */
class CustomerScope
{
    /** The current user, if they are a scoped customer; otherwise null. */
    public static function activeCustomerId(): ?int
    {
        $user = auth()->user();

        if ($user instanceof User && $user->hasRole('customer')) {
            // A customer with no customer_id must see nothing, not everything.
            return $user->customer_id ?? -1;
        }

        return null;
    }

    /** Scope a transactions query to the customer's events (via event.customer_id). */
    public static function transactions(Builder $query): Builder
    {
        $customerId = static::activeCustomerId();

        if ($customerId !== null) {
            $query->whereHas('event', fn (Builder $q) => $q->where('customer_id', $customerId));
        }

        return $query;
    }

    /** Scope a query that has a direct customer_id column (e.g. settlements). */
    public static function byCustomerId(Builder $query, string $column = 'customer_id'): Builder
    {
        $customerId = static::activeCustomerId();

        if ($customerId !== null) {
            $query->where($column, $customerId);
        }

        return $query;
    }
}
