<?php

namespace App\Services\Bank;

use App\Models\BankConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the GoCardless Bank Account Data (ex-Nordigen) PSD2 API.
 * Read-only: token, institutions, end-user agreement, requisition (consent
 * link), account transactions. The access token is cached until it expires.
 *
 * Docs: https://developer.gocardless.com/bank-account-data/
 */
class GoCardlessClient
{
    private const BASE = 'https://bankaccountdata.gocardless.com/api/v2';

    public function __construct(private readonly BankConnection $connection)
    {
    }

    /** A fresh access token, cached until shortly before it expires. */
    public function accessToken(): string
    {
        return Cache::remember('gocardless_token', now()->addHours(20), function () {
            $response = Http::acceptJson()->asJson()->timeout(30)
                ->post(self::BASE . '/token/new/', [
                    'secret_id' => $this->connection->secret_id,
                    'secret_key' => $this->connection->secret_key,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('GoCardless-Anmeldung fehlgeschlagen (Zugangsdaten prüfen): ' . $response->body());
            }

            return (string) $response->json('access');
        });
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout(30)->baseUrl(self::BASE);
    }

    /**
     * Banks available for a country, sorted by name.
     *
     * @return array<int, array{id: string, name: string, bic?: string}>
     */
    public function institutions(string $country = 'DE'): array
    {
        $response = $this->http()->get('/institutions/', ['country' => strtolower($country)]);
        $response->throw();

        return collect($response->json())
            ->map(fn ($i) => ['id' => $i['id'], 'name' => $i['name'], 'bic' => $i['bic'] ?? null])
            ->sortBy('name')->values()->all();
    }

    /**
     * Creates a 90-day read agreement, then a requisition (consent session).
     * Returns the requisition id + the link the user must open to authorise.
     *
     * @return array{id: string, link: string}
     */
    public function createRequisition(string $institutionId, string $redirect, string $reference): array
    {
        $agreement = $this->http()->post('/agreements/enduser/', [
            'institution_id' => $institutionId,
            'max_historical_days' => 90,
            'access_valid_for_days' => 90,
            'access_scope' => ['balances', 'details', 'transactions'],
        ]);
        $agreement->throw();

        $requisition = $this->http()->post('/requisitions/', [
            'redirect' => $redirect,
            'institution_id' => $institutionId,
            'reference' => $reference,
            'agreement' => $agreement->json('id'),
            'user_language' => 'DE',
        ]);
        $requisition->throw();

        // Persist the agreement id for the consent-expiry reminder.
        $this->connection->update(['agreement_id' => $agreement->json('id')]);

        return ['id' => (string) $requisition->json('id'), 'link' => (string) $requisition->json('link')];
    }

    /**
     * Requisition status + linked account ids.
     *
     * @return array{status: string, accounts: array<int, string>}
     */
    public function getRequisition(string $id): array
    {
        $response = $this->http()->get("/requisitions/{$id}/");
        $response->throw();

        return [
            'status' => (string) $response->json('status'),
            'accounts' => (array) ($response->json('accounts') ?? []),
        ];
    }

    /**
     * Booked transactions for one account (raw GoCardless shape).
     *
     * @return array<int, array<string, mixed>>
     */
    public function accountTransactions(string $accountId): array
    {
        $response = $this->http()->get("/accounts/{$accountId}/transactions/");
        $response->throw();

        return (array) ($response->json('transactions.booked') ?? []);
    }

    /** Drops the cached token (e.g. after changing credentials). */
    public static function forgetToken(): void
    {
        Cache::forget('gocardless_token');
    }
}
