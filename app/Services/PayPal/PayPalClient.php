<?php

namespace App\Services\PayPal;

use App\Models\PaypalAccount;
use App\Services\PayPal\Exceptions\PayPalApiException;
use App\Services\PayPal\Exceptions\PayPalAuthException;
use App\Services\PayPal\Exceptions\PayPalPermissionException;
use App\Services\PayPal\Exceptions\PayPalRateLimitException;
use App\Services\PayPal\Exceptions\PayPalResultSetTooLargeException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin, account-scoped HTTP client for the PayPal REST API. Handles the
 * OAuth2 client-credentials flow (with token caching on the account record)
 * and translates PayPal error responses into typed exceptions.
 */
class PayPalClient
{
    public function __construct(private readonly PaypalAccount $account)
    {
    }

    public function baseUrl(): string
    {
        return $this->account->apiBaseUrl();
    }

    /**
     * Returns a valid bearer token, reusing the cached one when possible.
     * PayPal client-credential tokens are valid for ~9h; we refresh a
     * minute early to avoid edge-of-expiry races.
     *
     * Pass $forceFresh when the result must reflect the app's *current*
     * PayPal-side configuration (e.g. the "Verbindung testen" action) -
     * a token issued before a permission (like Transaction Search) was
     * enabled in the PayPal Developer Console keeps failing with
     * PERMISSION_DENIED even after the feature is switched on, since the
     * grant appears to be baked into the token at issuance time rather
     * than checked live on every request.
     */
    public function getAccessToken(bool $forceFresh = false): string
    {
        if (! $forceFresh && $this->account->hasValidCachedToken()) {
            return $this->account->access_token;
        }

        return $this->fetchNewAccessToken();
    }

    private function fetchNewAccessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth($this->account->client_id, $this->account->client_secret)
            ->timeout(config('paypal.http.connect_timeout', 10))
            ->post("{$this->baseUrl()}/v1/oauth2/token", [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->status() === 401) {
            throw new PayPalAuthException(
                'PayPal-Zugangsdaten wurden abgelehnt (Client ID/Secret ungültig oder Konto gesperrt).',
                'AUTHENTICATION_FAILURE',
                401,
            );
        }

        if (! $response->successful()) {
            throw PayPalApiException::fromResponse(
                $response->status(),
                (array) $response->json(),
                'PayPal OAuth2-Token konnte nicht abgerufen werden.',
            );
        }

        $data = $response->json();
        $token = $data['access_token'];
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        $this->account->forceFill([
            'access_token' => $token,
            'access_token_expires_at' => now()->addSeconds(max(60, $expiresIn - 60)),
        ])->save();

        return $token;
    }

    public function http(): PendingRequest
    {
        return Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->timeout(config('paypal.http.timeout', 30))
            ->connectTimeout(config('paypal.http.connect_timeout', 10))
            ->retry(
                config('paypal.http.retry_times', 3),
                config('paypal.http.retry_sleep_ms', 1000),
                fn (\Throwable $e) => $e instanceof \Illuminate\Http\Client\ConnectionException
                    || ($e instanceof RequestException && $e->response->status() >= 500),
                throw: false,
            );
    }

    /**
     * GET request against the PayPal REST API with unified error mapping.
     *
     * @param  array<string,mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        $response = $this->http()->get("{$this->baseUrl()}{$path}", $query);

        $this->throwIfError($response);

        return $response;
    }

    private function throwIfError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = (array) $response->json();
        $errorName = $body['name'] ?? null;

        Log::channel(config('logging.default'))->warning('PayPal API error', [
            'status' => $status,
            'error_name' => $errorName,
            // never log tokens/secrets - only the structured error body
            'details' => $body['details'] ?? null,
        ]);

        if ($status === 401) {
            throw new PayPalAuthException(
                'PayPal-Authentifizierung fehlgeschlagen. Bitte Zugangsdaten prüfen.',
                $errorName,
                $status,
                $body,
            );
        }

        if ($status === 403 || $errorName === 'PERMISSION_DENIED' || $errorName === 'NOT_AUTHORIZED') {
            throw new PayPalPermissionException(
                'Diesem PayPal-Konto fehlt die Berechtigung "Transaction Search". Bitte in der PayPal Developer Console freischalten.',
                $errorName,
                $status,
                $body,
            );
        }

        if ($status === 429 || $errorName === 'RATE_LIMIT_REACHED') {
            throw new PayPalRateLimitException(
                'PayPal Rate Limit erreicht. Der Sync wird automatisch später erneut versucht.',
                $errorName,
                $status,
                $body,
            );
        }

        if ($errorName === 'RESULTSET_TOO_LARGE') {
            throw new PayPalResultSetTooLargeException(
                'Zeitraum enthält zu viele Datensätze für einen einzelnen Request.',
                $errorName,
                $status,
                $body,
            );
        }

        throw PayPalApiException::fromResponse($status, $body, 'PayPal API-Anfrage fehlgeschlagen.');
    }
}
