<?php

namespace App\Http\Controllers;

use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\SavedFilter;
use Illuminate\Http\RedirectResponse;

/**
 * Resolves a shareable saved-filter link: loads the SavedFilter's filter
 * state into the exact session key Filament's table uses for
 * ->persistFiltersInSession(), then redirects into the Transactions list
 * so it opens pre-filtered.
 */
class SharedFilterController extends Controller
{
    public function __invoke(string $token): RedirectResponse
    {
        $filter = SavedFilter::query()
            ->where('share_token', $token)
            ->where('is_shared', true)
            ->firstOrFail();

        session()->put(
            'tables.' . md5(ListTransactions::class) . '_filters',
            $filter->filters,
        );

        return redirect(\App\Filament\Resources\TransactionResource::getUrl('index'));
    }
}
