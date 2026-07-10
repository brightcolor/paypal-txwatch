<?php

namespace Tests\Feature\PayPal;

use App\Models\PaypalAccount;
use App\Services\PayPal\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectionTesterTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $overrides = []): PaypalAccount
    {
        return PaypalAccount::create(array_merge([
            'name' => 'Acc', 'mode' => 'sandbox', 'client_id' => 'id', 'client_secret' => 'secret',
        ], $overrides));
    }

    public function test_it_succeeds_when_credentials_and_permission_are_valid(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200),
            '*/v1/reporting/transactions*' => Http::response(['transaction_details' => [], 'total_items' => 0, 'total_pages' => 1], 200),
        ]);

        $result = (new ConnectionTester())->test($this->account());

        $this->assertTrue($result['success']);
    }

    public function test_it_reports_missing_transaction_search_permission(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400], 200),
            '*/v1/reporting/transactions*' => Http::response(['name' => 'PERMISSION_DENIED'], 403),
        ]);

        $result = (new ConnectionTester())->test($this->account());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Transaction Search', $result['message']);
    }

    public function test_it_always_fetches_a_fresh_token_even_if_a_valid_one_is_cached(): void
    {
        // A stale cached token can keep failing with PERMISSION_DENIED even
        // after the feature is enabled on PayPal's side (see PayPalClient
        // ::getAccessToken doc comment) - the connection test must never
        // trust the cache.
        $account = $this->account([
            'access_token' => 'stale-cached-token',
            'access_token_expires_at' => now()->addHours(8),
        ]);

        $tokenRequests = 0;

        Http::fake(function ($request) use (&$tokenRequests) {
            if (str_contains($request->url(), '/v1/oauth2/token')) {
                $tokenRequests++;

                return Http::response(['access_token' => 'brand-new-token', 'expires_in' => 32400], 200);
            }

            return Http::response(['transaction_details' => [], 'total_items' => 0, 'total_pages' => 1], 200);
        });

        (new ConnectionTester())->test($account);

        $this->assertSame(1, $tokenRequests);
        $this->assertSame('brand-new-token', $account->fresh()->access_token);
    }
}
