<?php

namespace App\Services\EnableBanking;

use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Access to Enable Banking - a PSD2 aggregator for account transactions.
 *
 * WHAT THIS PATH DOES DIFFERENTLY FROM FinTS: with FinTS this application talks
 * to the bank itself and needs the customer's PIN and TAN. Here the customer
 * authorises at THEIR OWN bank, and what comes back is a read-only token. This
 * application never sees the banking credentials - that is the actual gain, not
 * the convenience.
 *
 * THE PRICE IS JUST AS PLAIN: a third party sits between bank and books and sees
 * every transaction. And PSD2 caps a consent at 90 days - after that the
 * customer has to confirm again or the pull dies. FinTS knows neither.
 *
 * NO SILENT CAPS. The transaction query pages through `continuation_key` and has
 * a hard ceiling - but it SAYS when it bites (TransactionPage::$truncated). A cap
 * nobody reports looks exactly like a complete pull and is a hole in the books.
 */
class Client
{
    public function __construct(
        private readonly KeyVault $vault,
        private readonly Jwt $jwt,
    ) {
    }

    public function isReady(): bool
    {
        return $this->vault->isReady();
    }

    public function missingReason(): ?string
    {
        return $this->vault->missingReason();
    }

    /**
     * The self test: does the service know this application?
     *
     * The first call one makes and the only one without side effects. It answers
     * in one go whether id, key and signature line up - and returns the
     * registered redirect urls, which is what helps when hunting the most common
     * setup mistake.
     *
     * @return array<string, mixed>
     */
    public function application(): array
    {
        return $this->call('GET', '/application');
    }

    /**
     * The banks of one country.
     *
     * @return array<int, array<string, mixed>>
     */
    public function banks(string $country = 'DE'): array
    {
        $response = $this->call('GET', '/aspsps', ['country' => strtoupper(trim($country))]);

        return is_array($response['aspsps'] ?? null) ? $response['aspsps'] : [];
    }

    /**
     * How many days of consent one specific bank grants at most - or null when it
     * does not say.
     *
     * WHY ASK INSTEAD OF ASSUMING: PSD2 originally capped account-information
     * consent at 90 days, and that number is in every older guide. Measured
     * against the live list on 2026-08-17, German banks report
     * `maximum_consent_validity` of 180 days. Requesting 90 would have thrown
     * away half the validity and made the account holder re-authorise at their
     * bank twice as often as necessary - for no reason other than a number
     * someone once wrote down.
     *
     * Asking costs one request per consent, which happens at most a few times a
     * year.
     */
    public function maxConsentDays(string $bank, string $country = 'DE'): ?int
    {
        foreach ($this->banks($country) as $candidate) {
            if (($candidate['name'] ?? null) !== $bank) {
                continue;
            }

            $seconds = $candidate['maximum_consent_validity'] ?? null;

            return is_numeric($seconds) ? (int) floor((int) $seconds / 86400) : null;
        }

        return null;
    }

    /**
     * Starts the consent and returns the address the browser has to go to.
     *
     * `valid_until` MUST NOT EXCEED THE BANK'S `maximum_consent_validity` - the
     * API rejects the call otherwise. The caller therefore clamps the wish to
     * the chosen bank's own limit rather than passing it through unchecked and
     * showing the error to the user.
     *
     * @return array{url: string, authorization_id: string, psu_id_hash: string}
     */
    public function startAuthorization(
        string $bank,
        string $country,
        string $redirectUrl,
        string $state,
        DateTimeInterface $validUntil,
        string $psuType = 'business',
        string $language = 'de',
    ): array {
        $response = $this->call('POST', '/auth', [], [
            'access' => [
                'valid_until' => $validUntil->format(DateTimeInterface::ATOM),
                'balances' => true,
                'transactions' => true,
            ],
            'aspsp' => ['name' => $bank, 'country' => strtoupper($country)],
            'state' => $state,
            'redirect_url' => $redirectUrl,
            'psu_type' => $psuType,
            'language' => $language,
        ]);

        return [
            'url' => (string) ($response['url'] ?? ''),
            'authorization_id' => (string) ($response['authorization_id'] ?? ''),
            'psu_id_hash' => (string) ($response['psu_id_hash'] ?? ''),
        ];
    }

    /**
     * Exchanges the return code for a session including the account list.
     *
     * The code is redeemable ONCE and valid for a few minutes only. A second
     * attempt with the same code fails - that is not a defect of this
     * application, it is the point.
     *
     * @return array<string, mixed>
     */
    public function openSession(string $code): array
    {
        return $this->call('POST', '/sessions', [], ['code' => $code]);
    }

    /** @return array<string, mixed> */
    public function session(string $sessionId): array
    {
        return $this->call('GET', '/sessions/' . rawurlencode($sessionId));
    }

    /**
     * Ends the session and revokes the consent at the bank.
     *
     * Needed when disconnecting: whoever only tried this out should not leave
     * the bank reading for them for another 90 days. The consent would otherwise
     * only end with its own deadline.
     */
    public function closeSession(string $sessionId): void
    {
        $this->call('DELETE', '/sessions/' . rawurlencode($sessionId));
    }

    /** @return array<int, array<string, mixed>> */
    public function balances(string $accountUid): array
    {
        $response = $this->call('GET', '/accounts/' . rawurlencode($accountUid) . '/balances');

        return is_array($response['balances'] ?? null) ? $response['balances'] : [];
    }

    /**
     * Transactions of one account, across all pages.
     *
     * `$limit` bounds how many come back - and the result says whether the bound
     * bit. For the books that is the most important part: "37 transactions"
     * without "of more" is a statement about the bank that nobody verified.
     */
    public function transactions(
        string $accountUid,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        ?int $limit = null,
        ?string $status = null,
    ): TransactionPage {
        $limit ??= (int) config('bank.enablebanking.max_transactions');
        $maxPages = (int) config('bank.enablebanking.max_pages');

        $path = '/accounts/' . rawurlencode($accountUid) . '/transactions';
        $query = [];

        if ($from !== null) {
            $query['date_from'] = $from->format('Y-m-d');
        }
        if ($to !== null) {
            $query['date_to'] = $to->format('Y-m-d');
        }
        if (filled($status)) {
            $query['transaction_status'] = $status;
        }

        $collected = [];
        $key = null;
        $pages = 0;
        $truncated = false;

        do {
            if ($key !== null) {
                $query['continuation_key'] = $key;
            }

            $response = $this->call('GET', $path, $query);
            $pages++;

            $page = is_array($response['transactions'] ?? null) ? $response['transactions'] : [];

            foreach ($page as $transaction) {
                if (count($collected) >= $limit) {
                    $truncated = true;

                    break 2;
                }
                $collected[] = $transaction;
            }

            $key = filled($response['continuation_key'] ?? null)
                ? (string) $response['continuation_key']
                : null;

            /*
             * A bank server that always sends a continuation_key would otherwise
             * spin this loop forever. Generous and still finite.
             */
            if ($key !== null && $pages >= $maxPages) {
                $truncated = true;

                break;
            }
        } while ($key !== null);

        return new TransactionPage($collected, $truncated, $pages);
    }

    /**
     * One call - with credential, error translation, and no exceptions for 4xx.
     *
     * `throw()` is NOT used, because the error response is itself the payload: it
     * carries `error_code` and `error_description`. A client that throws on 4xx
     * before reading the body throws the explanation away and reports
     * "connection error".
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws EnableBankingException
     */
    private function call(string $method, string $path, array $query = [], array $body = []): array
    {
        if (! $this->vault->isReady()) {
            throw new EnableBankingException(
                'Der Bankabruf über Enable Banking ist auf dieser Installation nicht eingerichtet. '
                . $this->vault->missingReason()
            );
        }

        $request = Http::withToken($this->jwt->credential())
            ->acceptJson()
            /*
             * Generous: there is a bank behind this service, and bank servers
             * are occasionally slow. Without a limit the UI would hang forever
             * on a stalled counterpart.
             */
            ->timeout(60)
            ->connectTimeout(15);

        $url = rtrim((string) config('bank.enablebanking.base_url'), '/') . $path;

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $query),
                'DELETE' => $request->delete($url, $query),
                default => $request->withQueryParameters($query)->send($method, $url, ['json' => $body]),
            };
        } catch (ConnectionException $e) {
            throw new EnableBankingException('Der Bankdienst war nicht erreichbar: ' . $e->getMessage());
        }

        $raw = $response->body();

        // DELETE /sessions answers 204 with an empty body - that is success.
        if (trim($raw) === '') {
            if ($response->successful()) {
                return [];
            }

            throw new EnableBankingException(
                sprintf('Der Bankdienst antwortete mit Status %d und leerem Inhalt.', $response->status()),
                $response->status()
            );
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new EnableBankingException(
                'Der Bankdienst hat etwas geschickt, das kein JSON war.',
                $response->status()
            );
        }

        if ($response->status() >= 400) {
            throw EnableBankingException::fromResponse($data, $response->status());
        }

        return $data;
    }
}
