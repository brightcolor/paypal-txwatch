<?php

namespace App\Services\PayPal;

use App\Models\PaypalAccount;
use App\Services\PayPal\Exceptions\PayPalApiException;
use Carbon\CarbonImmutable;

/**
 * Verifies that an account's credentials are valid AND that the app has
 * the Transaction Search permission, by requesting a tiny 1-hour window.
 * Used by the "Verbindung testen" action in the UI.
 */
class ConnectionTester
{
    /**
     * How far back the test window starts. PayPal's Transaction Search
     * rejects windows that are "too recent" with a generic "Data for the
     * given start date is not available" error (observed even for a
     * window ending at "now") - independent of the documented ~3h
     * reporting delay for actual transaction data. A day-old window
     * avoids that edge case entirely; the test doesn't care whether any
     * transactions actually exist in it, only that PayPal answers.
     */
    private const TEST_WINDOW_OFFSET_HOURS = 25;

    /**
     * @return array{success: bool, message: string}
     */
    public function test(PaypalAccount $account): array
    {
        try {
            $client = new PayPalClient($account);
            // Force a fresh token: a cached one issued before a permission
            // (e.g. Transaction Search) was enabled in the PayPal Developer
            // Console would otherwise keep failing even after the feature
            // is switched on, since PayPal appears to bind granted scopes
            // to the token at issuance rather than checking them live.
            $client->getAccessToken(forceFresh: true);

            $search = new TransactionSearchClient($client);
            $end = CarbonImmutable::now()->subHours(self::TEST_WINDOW_OFFSET_HOURS - 1);
            $start = $end->subHour();

            $search->searchPage($start, $end, page: 1);

            return [
                'success' => true,
                'message' => 'Verbindung erfolgreich: Zugangsdaten gültig, Transaction Search erreichbar.',
            ];
        } catch (PayPalApiException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
