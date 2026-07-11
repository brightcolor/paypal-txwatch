<?php

namespace App\Services\PayPal;

use App\Models\PaypalAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates open PayPal disputes across all active accounts (live API, cached).
 * Each row is tagged with the owning account so the operator sees everything in
 * one list.
 */
class DisputesOverview
{
    private const CACHE_KEY = 'paypal_open_disputes';
    private const TTL_SECONDS = 300;

    /** @return Collection<int, array<string, mixed>> */
    public function all(bool $fresh = false): Collection
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function () {
            $rows = collect();

            foreach (PaypalAccount::query()->where('is_active', true)->get() as $account) {
                $client = new DisputesClient(new PayPalClient($account));

                foreach ($client->openDisputes() as $dispute) {
                    $rows->push($dispute + ['account' => $account->name, 'account_id' => $account->id]);
                }
            }

            return $rows->sortByDesc('created')->values();
        });
    }
}
