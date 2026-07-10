<?php

namespace App\Services\PayPal;

use App\Models\PaypalAccount;
use App\Services\PayPal\Exceptions\PayPalApiException;
use Carbon\CarbonImmutable;

/**
 * Verifies that an account's credentials are valid AND that the app has
 * the Transaction Search permission, by requesting a tiny (1 hour, 1
 * record) window. Used by the "Verbindung testen" action in the UI.
 */
class ConnectionTester
{
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
            $end = CarbonImmutable::now();
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
